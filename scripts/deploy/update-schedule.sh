#!/usr/bin/env bash
# Moves a schedule in the default group to another task definition revision.
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "Usage: $0 <schedule> <task-definition-arn>" >&2
  exit 2
fi

schedule="$1"
task_definition="$2"

current="$(aws scheduler get-schedule --name "$schedule" --output json)"

updated="$(jq --arg arn "$task_definition" '
  del(.Arn, .CreationDate, .LastModificationDate)
  | .Target.EcsParameters.TaskDefinitionArn = $arn
' <<<"$current")"

aws scheduler update-schedule --cli-input-json "$updated" --query ScheduleArn --output text >/dev/null

echo "The schedule $schedule runs $task_definition."
