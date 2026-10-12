#!/usr/bin/env bash
# Copies the latest revision of a family with a new image and prints the new revision's ARN.
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "Usage: $0 <family> <image>" >&2
  exit 2
fi

family="$1"
image="$2"

current="$(aws ecs describe-task-definition --task-definition "$family" --include TAGS --output json)"

revision="$(jq --arg image "$image" '
  .tags as $tags
  | .taskDefinition
  | del(.taskDefinitionArn, .revision, .status, .requiresAttributes, .compatibilities,
        .registeredAt, .registeredBy, .deregisteredAt)
  | .containerDefinitions |= map(.image = $image)
  | if ($tags | length) > 0 then .tags = $tags else . end
' <<<"$current")"

aws ecs register-task-definition \
  --cli-input-json "$revision" \
  --query taskDefinition.taskDefinitionArn \
  --output text
