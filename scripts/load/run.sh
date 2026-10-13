#!/usr/bin/env bash
# Runs launch-check.js in the pinned k6 image. Saves the summary (.json, .txt) and the console log in scripts/load/results.
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "Usage: $0 <label>   (where it runs from, for example laptop or eu-central-1)" >&2
  exit 2
fi

: "${BASE_URL:?Set BASE_URL, for example https://<domain>}"
: "${EDUCATOR_EMAIL:?Set EDUCATOR_EMAIL}"
: "${EDUCATOR_PASSWORD:?Set EDUCATOR_PASSWORD}"
export BASE_URL EDUCATOR_EMAIL EDUCATOR_PASSWORD

image="grafana/k6:2.3.0@sha256:9c2dee7f8ed74d317e4027c06a10f169b625638189de8d4555d0b3486a5aeb34"
here="$(cd "$(dirname "$0")" && pwd)"
results="${RESULTS_DIR:-$here/results}"
name="$1-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$results"
results="$(cd "$results" && pwd)"
status=0

echo "Started $(date -u +%Y-%m-%dT%H:%M:%SZ)" | tee "$results/$name.log"

docker run --rm \
  --network "${K6_NETWORK:-bridge}" \
  --user "$(id -u):$(id -g)" \
  --volume "$here:/scripts:ro" \
  --volume "$results:/results" \
  --env BASE_URL --env EDUCATOR_EMAIL --env EDUCATOR_PASSWORD --env GROUP_NAME --env GROUP_PROGRESS_CONCURRENCY \
  --env SUMMARY_FILE="/results/$name.json" \
  "$image" run --quiet /scripts/launch-check.js 2>&1 | tee -a "$results/$name.log" || status=$?

echo "Finished $(date -u +%Y-%m-%dT%H:%M:%SZ), k6 exit code $status" | tee -a "$results/$name.log"
echo "Saved $results/$name.json, $name.txt and $name.log"
exit "$status"
