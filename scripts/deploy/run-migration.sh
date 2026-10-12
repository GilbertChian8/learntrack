#!/usr/bin/env bash
# Runs php artisan migrate --force as a one-off task, prints its logs and exits with its exit code.
set -euo pipefail

if [ "$#" -ne 4 ]; then
  echo "Usage: $0 <cluster> <task-definition-arn> <subnet-ids> <security-group-id>" >&2
  exit 2
fi

cluster="$1"
task_definition="$2"
subnets="$3"
security_group="$4"

container="$(aws ecs describe-task-definition --task-definition "$task_definition" \
  --query 'taskDefinition.containerDefinitions[0].name' --output text)"
overrides="$(jq -nc --arg name "$container" \
  '{containerOverrides: [{name: $name, command: ["php", "artisan", "migrate", "--force"]}]}')"

started="$(aws ecs run-task \
  --cluster "$cluster" \
  --task-definition "$task_definition" \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[$subnets],securityGroups=[$security_group],assignPublicIp=DISABLED}" \
  --overrides "$overrides" \
  --started-by deploy-migration \
  --output json)"

task="$(jq -r '.tasks[0].taskArn // empty' <<<"$started")"
if [ -z "$task" ]; then
  echo "The migration task did not start:" >&2
  jq '.failures' <<<"$started" >&2
  exit 1
fi

echo "Migration task: $task"

# Each wait gives up after 10 minutes.
for attempt in 1 2 3; do
  if aws ecs wait tasks-stopped --cluster "$cluster" --tasks "$task"; then
    break
  fi
  echo "The migration task is still running after $((attempt * 10)) minutes."
done

stopped="$(aws ecs describe-tasks --cluster "$cluster" --tasks "$task" --output json)"
exit_code="$(jq -r '.tasks[0].containers[0].exitCode // empty' <<<"$stopped")"

log_options="$(aws ecs describe-task-definition --task-definition "$task_definition" \
  --query 'taskDefinition.containerDefinitions[0].logConfiguration.options' --output json)"
log_group="$(jq -r '.["awslogs-group"]' <<<"$log_options")"
log_stream="$(jq -r '.["awslogs-stream-prefix"]' <<<"$log_options")/$container/${task##*/}"

echo "Logs ($log_group, $log_stream):"
aws logs get-log-events \
  --log-group-name "$log_group" \
  --log-stream-name "$log_stream" \
  --start-from-head \
  --query 'events[].[message]' \
  --output text || echo "No logs."

if [ "$(jq -r '.tasks[0].lastStatus' <<<"$stopped")" != "STOPPED" ]; then
  echo "The migration task did not stop within 30 minutes." >&2
  exit 1
fi

if [ -z "$exit_code" ]; then
  echo "The migration task stopped without an exit code: $(jq -r '.tasks[0].stoppedReason' <<<"$stopped")" >&2
  exit 1
fi

echo "Exit code: $exit_code"
exit "$exit_code"
