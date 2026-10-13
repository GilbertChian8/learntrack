# Load check

`launch-check.js` measures the launch targets of [ticket 15](../../docs/tickets/15-launch-check.md): groupProgress p95 under 300 ms on the largest seeded group (60 learners, 100 assignments), and every MCP read tool p95 under 1 s. It runs in the official k6 image, `grafana/k6:2.3.0`, pinned by digest in `run.sh`. Nothing is added to the app. The results go in [docs/launch-check.md](../../docs/launch-check.md).

## What it does

1. **Setup, as a real client.** It gets the MCP token the way Claude does: it reads the discovery documents, registers a client (`POST /oauth/register`), opens the authorize URL, logs in on the web login page, approves the consent page and exchanges the code with its PKCE verifier. Then `initialize` and `tools/list` on `/mcp`. It gets the API token with `POST /api/v1/auth/login`. It finds the group by name, and its learner with the lowest id for `get_learner_summary`.
2. **groupProgress:** the contract's GraphQL query (below), 200 times, 10 at once.
3. **MCP read tools:** each of the five tools 100 times, in turn, one call at a time, and at most one call every 1.2 seconds (50 a minute).

The groupProgress query is the one in [docs/api-contract.md](../../docs/api-contract.md) (GraphQL, groupProgress), sent unchanged:

```graphql
query ($groupId: ID!) {
  groupProgress(groupId: $groupId) {
    group { id name institution }
    generatedAt
    assignments { id title topic type dueAt scope targetedCount completedCount completionRate averageScore }
    learners {
      learner { id name }
      behind
      reasons
      averageScore
      counts { notStarted inProgress completed overdue }
      statuses { assignmentId status score completedAt }
    }
  }
}
```

It asks for every field the schema defines: the group, each assignment's numbers, and each learner's behind flag, reasons, counts and one status per assignment. That is the whole progress table a client dashboard shows, so it is the call the target is about. A smaller query would measure less than a client gets. For Full Size Group the answer is 588 KB (measured locally).

It reports p50, p95, p99 and max per operation, and writes the raw k6 summary to a JSON file. The time is k6's `http_req_duration`: from sending the request to the last byte of the answer. Connection setup (DNS, TCP, TLS) is not in it.

## Rate limits and run time

A run takes about 12 minutes, or about 17 with `GROUP_PROGRESS_CONCURRENCY=1`. The limits stay on:

- REST and GraphQL allow 300 requests a minute per user. groupProgress makes 200, plus 2 setup calls, so it runs at full speed with 10 at once and stays under the limit. It has 2 seconds per round of calls to start them, at least 60 seconds (60 at 10 at once, 6 minutes 40 at 1), plus 30 to finish them.
- `/mcp` allows 60 calls a minute per educator. The 500 tool calls start 30 seconds after the groupProgress time ends (90 seconds after setup's 2 calls at 10 at once). Each call starts at least 1.2 seconds after the one before, so they take at least 10 minutes and no minute holds more than 51.
- Client registration allows 10 an hour per IP address, so at most 10 runs an hour from one address.

Do not run two at once, for example the laptop and the in-region run: both use the same educator's limits.

## Pass or fail

Every target and count is a k6 threshold. Exit code 0: every request answered 2xx, every check passed, every operation ran its full count and met its target. Exit code 99: a threshold failed, so a target was missed, a count is short, or a request got a non-2xx answer or a 429. The report lists the failed thresholds. Any other code: the run stopped early, often in setup, and the log says why.

## Which group and educator

The targets are for the largest group, 60 learners and 100 assignments. The seed has one group of exactly that size, **Full Size Group**, and only its own educator, `full.size.educator@example.edu`, teaches it. So the run logs in as that educator. The demo educators' groups stay as the README describes them.

## Settings

| Variable | Required | Meaning |
|---|---|---|
| `BASE_URL` | yes | The app's public URL, the same as its `APP_URL`: `https://<domain>`, or `http://localhost:8080` locally. The run stops if the discovery documents name another URL |
| `EDUCATOR_EMAIL`, `EDUCATOR_PASSWORD` | yes | An educator who teaches the group. Seed data: `full.size.educator@example.edu`, `password` |
| `GROUP_NAME` | no | Default `Full Size Group` |
| `GROUP_PROGRESS_CONCURRENCY` | no | How many groupProgress calls run at once. Default 10, the ticket's value: that run gives the verdict. 1 is a diagnostic, not the verdict: it shows a call's own time, without waiting behind other calls. Any value other than 10 is a diagnostic: the report says so, and groupProgress gets no 300 ms threshold and no met or not met |
| `RESULTS_DIR` | no | Where `run.sh` saves the files. Default `scripts/load/results` |
| `K6_NETWORK` | no | The Docker network of the k6 container. Default `bridge` |

## Run it

Needs Docker and a seeded database. Each run saves three files named after the label and the start time: `<label>-<time>.json` (the raw k6 summary), `.txt` (the report) and `.log` (the console, with the start and finish times). A failed request writes its URL to the log: replace the domain with `<domain>` before you commit a file that holds it.

**Locally,** against the compose app (`docker compose up -d`, then `php artisan migrate:fresh --seed`). The discovery documents and redirects name `http://localhost:8080`, so the k6 container joins the app container's network, where that address is the app:

```
BASE_URL=http://localhost:8080 EDUCATOR_EMAIL=full.size.educator@example.edu EDUCATOR_PASSWORD=password \
  K6_NETWORK="container:$(docker compose ps -q app)" scripts/load/run.sh local
```

**From a laptop,** against AWS:

```
BASE_URL=https://<domain> EDUCATOR_EMAIL=full.size.educator@example.edu EDUCATOR_PASSWORD=password \
  scripts/load/run.sh laptop
```

**From eu-central-1, as a one-off Fargate task.** This is how the launch check ran it: the same region as the app, no new infrastructure and no instance to manage. The task runs in the `learntrack-prod` cluster, in the app's private subnets with the app's security group, and reaches the public load balancer through the NAT gateways, like any client on the internet. It uses the pinned k6 image: it downloads the script at a fixed commit, runs it, and prints the report, then the raw summary JSON between two marker lines, then the exit code, to its log. Its task definition is temporary.

Save this as `loadcheck-task.json`, with your values for `<account id>` and `<commit>` (a pushed commit that has this script). 1 vCPU keeps the load client from being the bottleneck: k6 reads every 588 KB groupProgress answer.

```json
{
  "family": "learntrack-prod-loadcheck",
  "requiresCompatibilities": ["FARGATE"],
  "networkMode": "awsvpc",
  "cpu": "1024",
  "memory": "2048",
  "executionRoleArn": "arn:aws:iam::<account id>:role/learntrack-prod-task-execution",
  "containerDefinitions": [
    {
      "name": "k6",
      "image": "grafana/k6:2.3.0@sha256:9c2dee7f8ed74d317e4027c06a10f169b625638189de8d4555d0b3486a5aeb34",
      "essential": true,
      "entryPoint": ["sh", "-c"],
      "command": ["wget -qO /tmp/launch-check.js \"$SCRIPT_URL\" && k6 run --quiet /tmp/launch-check.js; status=$?; echo SUMMARY-JSON-BEGIN; cat /tmp/summary.json; echo SUMMARY-JSON-END; echo \"k6 exit code $status\"; exit $status"],
      "environment": [
        { "name": "SCRIPT_URL", "value": "https://raw.githubusercontent.com/GilbertChian8/learntrack/<commit>/scripts/load/launch-check.js" },
        { "name": "SUMMARY_FILE", "value": "/tmp/summary.json" }
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group": "/learntrack/prod/app",
          "awslogs-region": "eu-central-1",
          "awslogs-stream-prefix": "loadcheck"
        }
      }
    }
  ]
}
```

Then register it, run it with the app service's network settings and the run's settings as overrides, wait, save the log, and deregister it:

```
export AWS_REGION=eu-central-1
aws ecs register-task-definition --cli-input-json file://loadcheck-task.json \
  --query 'taskDefinition.taskDefinitionArn' --output text
network="$(aws ecs describe-services --cluster learntrack-prod --services learntrack-prod-app \
  --query 'services[0].networkConfiguration' --output json)"
task="$(aws ecs run-task --cluster learntrack-prod --task-definition learntrack-prod-loadcheck \
  --launch-type FARGATE --network-configuration "$network" --started-by launch-check-load \
  --overrides '{"containerOverrides":[{"name":"k6","environment":[{"name":"BASE_URL","value":"https://<domain>"},{"name":"EDUCATOR_EMAIL","value":"full.size.educator@example.edu"},{"name":"EDUCATOR_PASSWORD","value":"password"}]}]}' \
  --query 'tasks[0].taskArn' --output text)"
until aws ecs wait tasks-stopped --cluster learntrack-prod --tasks "$task"; do echo "still running"; done
aws ecs describe-tasks --cluster learntrack-prod --tasks "$task" \
  --query 'tasks[0].[containers[0].exitCode,startedAt,stoppedAt]' --output text
log="scripts/load/results/eu-central-1-<start time, YYYYMMDDTHHMMSSZ>.log"
aws logs get-log-events --log-group-name /learntrack/prod/app \
  --log-stream-name "loadcheck/k6/${task##*/}" --start-from-head \
  --query 'events[].[message]' --output text > "$log"
sed '/^SUMMARY-JSON-BEGIN$/,$d' "$log" > "${log%.log}.txt"
sed -n '/^SUMMARY-JSON-BEGIN$/,/SUMMARY-JSON-END$/p' "$log" | sed '1d;$s/SUMMARY-JSON-END$//' > "${log%.log}.json"
aws ecs deregister-task-definition --task-definition learntrack-prod-loadcheck:<revision>
```

The exit code means the same as with `run.sh`. The start and stop times give the window for the load balancer's p95. The two `sed` lines split the log into the report, everything before the first marker, and the raw summary, the text between the markers (`cat` prints no final newline, so the end marker follows the closing brace). For the diagnostic run at 1 at once, use the label `eu-central-1-diagnostic` and add `{"name":"GROUP_PROGRESS_CONCURRENCY","value":"1"}` to the overrides' `environment`.

**From eu-central-1, on an EC2 instance,** the same `run.sh` command with the label `eu-central-1` (Amazon Linux 2023):

```
sudo dnf install -y docker git
sudo systemctl start docker
sudo usermod -aG docker "$USER"     # then log out and in again
git clone https://github.com/GilbertChian8/learntrack.git && cd learntrack
```

CloudShell in `eu-central-1` works the same way if `docker version` answers there.

## What a run leaves behind

One OAuth client named `LearnTrack load check` with its tokens, and one API token. They do no harm: the tokens expire (1 hour and 8 hours), and the daily housekeeping run deletes expired tokens. The client stays.
