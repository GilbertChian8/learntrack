#!/usr/bin/env bash
# Moves the service to a revision and waits. Fails when the circuit breaker rolled the revision back.
set -euo pipefail

if [ "$#" -ne 3 ]; then
  echo "Usage: $0 <cluster> <service> <task-definition-arn>" >&2
  exit 2
fi

cluster="$1"
service="$2"
task_definition="$3"

aws ecs update-service \
  --cluster "$cluster" \
  --service "$service" \
  --task-definition "$task_definition" \
  --query 'service.serviceName' \
  --output text >/dev/null

# Each wait gives up after 10 minutes; a rollback by the circuit breaker can take longer.
for attempt in 1 2 3; do
  if aws ecs wait services-stable --cluster "$cluster" --services "$service"; then
    break
  fi
  if [ "$attempt" -eq 3 ]; then
    echo "The service is not stable after 30 minutes." >&2
    exit 1
  fi
done

primary="$(aws ecs describe-services --cluster "$cluster" --services "$service" \
  --query "services[0].deployments[?status=='PRIMARY'] | [0]" --output json)"
running="$(jq -r '.taskDefinition' <<<"$primary")"
rollout="$(jq -r '.rolloutState' <<<"$primary")"

if [ "$running" != "$task_definition" ] || [ "$rollout" != "COMPLETED" ]; then
  echo "The deployment of $task_definition did not complete. The service runs $running ($rollout)." >&2
  exit 1
fi

echo "The service runs $task_definition."
