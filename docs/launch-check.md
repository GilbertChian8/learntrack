# Launch check

The results of [ticket 15](tickets/15-launch-check.md) on the AWS environment: the pipeline deploys `main`, Claude Desktop runs Flows 1, 4, 5 and 6, the load script measures the targets, and the environment is destroyed and rebuilt from code.

Every slot below says "To be filled by the lead" until it holds a measured value, a link or a transcript. No number is estimated. A missed target gets a ticket with its measurements, and its verdict stays "not met".

The commands use `<domain>` for the environment's domain. Run them from the repository root with AWS credentials for the environment's account:

```
export AWS_REGION=eu-central-1
```

## Summary

| Item | Value |
|---|---|
| Date (UTC) | To be filled by the lead |
| Commit on `main` | To be filled by the lead |
| Environment | `prod` in `eu-central-1`, `https://<domain>` |
| Load client in the region | To be filled by the lead (EC2 instance type, or CloudShell) |
| Laptop | To be filled by the lead (place and connection) |

| Target | Measured | Verdict |
|---|---|---|
| groupProgress p95 under 300 ms on the largest seeded group, from `eu-central-1` | To be filled by the lead | To be filled by the lead |
| groupProgress p95 under 300 ms, from the laptop | To be filled by the lead | To be filled by the lead |
| Every MCP read tool p95 under 1 s, from `eu-central-1` (slowest tool) | To be filled by the lead | To be filled by the lead |
| Every MCP read tool p95 under 1 s, from the laptop (slowest tool) | To be filled by the lead | To be filled by the lead |
| `assign_content` under 1 s in the manual flow (slowest call) | To be filled by the lead | To be filled by the lead |
| `change_due_date` under 1 s in the manual flow | To be filled by the lead | To be filled by the lead |
| Flows 1, 4, 5 and 6 from Claude Desktop | To be filled by the lead | To be filled by the lead |
| No alarm in `ALARM` after the load runs | To be filled by the lead | To be filled by the lead |
| Destroy and rebuild with no manual step beyond the bootstrap | To be filled by the lead | To be filled by the lead |

Tickets opened for missed targets: To be filled by the lead.

## 1. Deploy

Deploy `main` through `deploy.yml`: the merge to `main` starts a run, or start one by hand.

```
gh workflow run deploy.yml --ref main
gh run list --workflow deploy.yml --branch main --limit 1     # wait a few seconds after the line above
gh run watch <run id> --exit-status
```

The run page's summary lists the app and housekeeping revisions. The image tag is the commit SHA.

| Item | Value |
|---|---|
| Run | To be filled by the lead |
| Image tag | To be filled by the lead |
| App task definition revision | To be filled by the lead |
| Housekeeping task definition revision | To be filled by the lead |

## 2. Seed

The pipeline runs the migrations only. Seed once with a one-off task: the housekeeping revision from step 1, with its command changed to `php artisan migrate --seed --force` (`--force` because the image runs in production). The task uses the app's subnets and security group, read from the service:

```
housekeeping=<housekeeping revision ARN from step 1>
network="$(aws ecs describe-services --cluster learntrack-prod --services learntrack-prod-app \
  --query 'services[0].networkConfiguration' --output json)"
task="$(aws ecs run-task --cluster learntrack-prod --task-definition "$housekeeping" \
  --launch-type FARGATE --network-configuration "$network" \
  --overrides '{"containerOverrides":[{"name":"housekeeping","command":["php","artisan","migrate","--seed","--force"]}]}' \
  --started-by launch-check-seed --query 'tasks[0].taskArn' --output text)"
echo "$task"
aws ecs wait tasks-stopped --cluster learntrack-prod --tasks "$task"
aws ecs describe-tasks --cluster learntrack-prod --tasks "$task" \
  --query 'tasks[0].[containers[0].exitCode,stoppedReason]' --output text
aws logs get-log-events --log-group-name /learntrack/prod/app \
  --log-stream-name "housekeeping/housekeeping/${task##*/}" --start-from-head \
  --query 'events[].message' --output text
```

Exit code 0 and the log lists PassportSeeder, ContentSeeder and DemoSeeder as DONE. Due dates count from the day of the seed. The seed runs the housekeeping task definition, so a seed that fails also sends the housekeeping failure email.

Seed a database once only. A second run adds the content library and the first institution again, then stops on a duplicate email. After that, a content title matches two items, so Flow 5 gets candidates instead of an assignment. The production image refuses `migrate:fresh`, so only a destroy and rebuild cleans it.

| Item | Value |
|---|---|
| Seed task | To be filled by the lead |
| Started and stopped (UTC) | To be filled by the lead |
| Exit code | To be filled by the lead |
| Log | To be filled by the lead |

## 3. Claude Desktop

Connect Claude Desktop as `anna.keller@example.edu` (password `password`): Settings, Connectors, add a custom connector with the URL `https://<domain>/mcp`. Keep a screenshot or the transcript of each step, and note the time of each write (for the write tool times below).

| Item | Value |
|---|---|
| Claude Desktop version, model | To be filled by the lead |
| Screenshots and transcripts | To be filled by the lead (link each one below) |

**Flow 1: connect.** Connect opens the LearnTrack login page; log in; the consent page names the client and what it can do; Approve; the seven tools appear in Claude.

| Step | Evidence | Result |
|---|---|---|
| Login page | To be filled by the lead | To be filled by the lead |
| Consent page | To be filled by the lead | To be filled by the lead |
| Tools listed in Claude | To be filled by the lead | To be filled by the lead |

**Flow 4: ask.**

| Question | Expected | Transcript | Result |
|---|---|---|---|
| Which learners in Cardiology Group A are behind, and why? | The behind learners with their reasons (overdue assignment, or low average with the scores) | To be filled by the lead | To be filled by the lead |
| How is Sara Lindqvist doing? | Her summary: behind in Cardiology Group A, her first question set overdue | To be filled by the lead | To be filled by the lead |
| Which assignment in Cardiology Group A is the hardest? | The ranking with completion rates and average scores | To be filled by the lead | To be filled by the lead |

**Flow 5: act.** Pick a due date in the future (D1) and a later one (D2).

| Step | Expected | Transcript | Result |
|---|---|---|---|
| a. "Assign the Hypertension Pharmacology Question Set to the learners who are behind in Cardiology Group A, due D1." Approve. | Claude asks first; the answer names the content, the learners and D1 | To be filled by the lead | To be filled by the lead |
| b. The exact same request again. Approve. | Nothing changed: they already have it | To be filled by the lead | To be filled by the lead |
| c. "Move the due date of that assignment to D2." Approve. | Changed, with D1 as the previous date and D2 as the new one | To be filled by the lead | To be filled by the lead |
| d. The request from step a again, with D1. Approve. | Already assigned with D2; nothing changes; change the due date to move it | To be filled by the lead | To be filled by the lead |

**Flow 6: someone else's group.** Anna Keller does not teach Cardiology Group C. Find its id with the API (as in the audit step below, logged in as `hannah.clarke@example.edu`), then ask about that id and about an id that does not exist.

| Question | Expected | Transcript | Result |
|---|---|---|---|
| "Show the progress of group <Cardiology Group C's id>." | Not found, the same answer as below; nothing about the group | To be filled by the lead | To be filled by the lead |
| "Show the progress of group 999999." | Not found | To be filled by the lead | To be filled by the lead |

**Write tool times.** Each request writes a "Request handled." log line with its route and `duration_ms`. List the `/mcp` lines of the Flow 5 window, and match each write to the line at the time of its approval:

```
aws logs start-query --log-group-name /learntrack/prod/app \
  --start-time <Flow 5 start, Unix seconds> --end-time <Flow 5 end, Unix seconds> \
  --query-string 'fields @timestamp, context.status, context.duration_ms | filter message = "Request handled." and context.route = "mcp" | sort @timestamp asc'
aws logs get-query-results --query-id <query id from the line above>
```

| Call | Time (UTC) | duration_ms |
|---|---|---|
| a. `assign_content` | To be filled by the lead | To be filled by the lead |
| b. `assign_content` | To be filled by the lead | To be filled by the lead |
| c. `change_due_date` | To be filled by the lead | To be filled by the lead |
| d. `assign_content` | To be filled by the lead | To be filled by the lead |

**Audit.** The writes that changed something are in the group's audit log with `channel: mcp`: one `assignment.created` (step a) and one `assignment.due_changed` (step c), nothing for steps b and d.

```
TOKEN="$(curl -s -X POST https://<domain>/api/v1/auth/login -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"anna.keller@example.edu","password":"password"}' | jq -r .data.token)"
GROUP="$(curl -s 'https://<domain>/api/v1/groups' -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  | jq '.data[] | select(.name == "Cardiology Group A") | .id')"
curl -s "https://<domain>/api/v1/groups/$GROUP/audit" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  | jq '.data[] | select(.channel == "mcp")'
```

| Item | Value |
|---|---|
| Rows with `channel: mcp` | To be filled by the lead |

## 4. Load script

[scripts/load/](../scripts/load/README.md) logs in as `hannah.clarke@example.edu`, who teaches the largest seeded group, Pediatrics Group B (50 learners, 12 assignments, 511 learner-assignment pairs). It runs groupProgress 200 times, 10 at once, then each of the five MCP read tools 100 times, with a token from the real OAuth flow. A run takes about 12 minutes. Run it once from `eu-central-1` and once from the laptop, not at the same time:

```
BASE_URL=https://<domain> EDUCATOR_EMAIL=hannah.clarke@example.edu EDUCATOR_PASSWORD=password \
  scripts/load/run.sh eu-central-1      # on the EC2 instance or CloudShell; use the label laptop on the laptop
```

Each run saves its raw k6 summary, report and log in `scripts/load/results/`. Commit them and link them here. A failed request writes its URL to the log, so first replace the domain with `<domain>` in any file that holds it (`grep -l <the domain> scripts/load/results/*`).

The seed's largest group is smaller than the requirement's largest group (60 learners, 100 assignments). The ticket measures the seeded group.

**From `eu-central-1`**

| Item | Value |
|---|---|
| Started and finished (UTC) | To be filled by the lead |
| k6 exit code | To be filled by the lead |
| Raw summary, report, log | To be filled by the lead |

| Operation | Count | p50 ms | p95 ms | p99 ms | Target | Verdict |
|---|---|---|---|---|---|---|
| groupProgress | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 300 ms | To be filled by the lead |
| list_my_groups | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |
| get_group_progress | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |
| find_learners_behind | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |
| get_learner_summary | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |
| find_hardest_assignments | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |

**From the laptop**

| Item | Value |
|---|---|
| Started and finished (UTC) | To be filled by the lead |
| k6 exit code | To be filled by the lead |
| Raw summary, report, log | To be filled by the lead |

| Operation | Count | p50 ms | p95 ms | p99 ms | Target | Verdict |
|---|---|---|---|---|---|---|
| groupProgress | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 300 ms | To be filled by the lead |
| list_my_groups | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |
| get_group_progress | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |
| find_learners_behind | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |
| get_learner_summary | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |
| find_hardest_assignments | To be filled by the lead | To be filled by the lead | To be filled by the lead | To be filled by the lead | under 1 s | To be filled by the lead |

## 5. Alarms and the load balancer

After the load runs, no alarm is in `ALARM`:

```
aws cloudwatch describe-alarms --alarm-name-prefix learntrack-prod \
  --query 'MetricAlarms[].[AlarmName,StateValue]' --output table
```

The load balancer's target response time p95 (in seconds) over each run, from its start and finish times. The period is the run's length in seconds, rounded up to a multiple of 60, so the answer is one number:

```
lb="$(aws elbv2 describe-load-balancers --names learntrack-prod --query 'LoadBalancers[0].LoadBalancerArn' --output text)"
aws cloudwatch get-metric-statistics --namespace AWS/ApplicationELB --metric-name TargetResponseTime \
  --dimensions Name=LoadBalancer,Value="${lb#*:loadbalancer/}" \
  --start-time <started, UTC> --end-time <finished, UTC> --period <seconds> \
  --extended-statistics p95 --output table
```

| Item | Value |
|---|---|
| Alarms and their states | To be filled by the lead |
| ALB target response time p95, `eu-central-1` run | To be filled by the lead |
| ALB target response time p95, laptop run | To be filled by the lead |

## 6. Destroy and rebuild

Destroy the environment, deploy it again, seed it again, and ask one question. Note the time of each step.

```
gh workflow run destroy.yml --ref main -f confirm=destroy
gh run watch <run id> --exit-status
gh workflow run deploy.yml --ref main
gh run watch <run id> --exit-status
```

Then seed as in step 2, with the new housekeeping revision, and ask Claude Desktop "Which groups do I teach?". The new database has no client and no tokens, so Claude must connect again; note whether it asked to log in by itself. During the rebuild the alarm email gets a few alarms until the deploy finishes ([infra/README.md](../infra/README.md), Destroy and rebuild); they are expected.

| Step | Run or evidence | Started (UTC) | Finished (UTC) |
|---|---|---|---|
| destroy.yml | To be filled by the lead | To be filled by the lead | To be filled by the lead |
| deploy.yml | To be filled by the lead | To be filled by the lead | To be filled by the lead |
| Seed | To be filled by the lead | To be filled by the lead | To be filled by the lead |
| First MCP answer | To be filled by the lead | To be filled by the lead | To be filled by the lead |

| Item | Value |
|---|---|
| Elapsed, destroy start to the first answer | To be filled by the lead |
| Manual steps beyond the bootstrap | To be filled by the lead |
