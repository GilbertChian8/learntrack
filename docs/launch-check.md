# Launch check

The results of [ticket 15](tickets/15-launch-check.md) on the AWS environment: the pipeline deploys `main`, Claude Desktop runs Flows 1, 4, 5 and 6, the load script measures the targets, and the environment is destroyed and rebuilt from code.

Every value below was measured on 2026-10-13. No number is estimated. A missed target keeps the verdict "not met", and its follow-up is recorded in this document ([Follow-up](#follow-up)): this project opens no new tickets.

The transcripts are quoted in section 3. The screenshots are not committed: they show the real domain and the account's own Claude UI. The environment's logs do not survive a destroy (the log group belongs to the environment), so the values in this document are the record.

The commands use `<domain>` for the environment's domain. Run them from the repository root with AWS credentials for the environment's account:

```
export AWS_REGION=eu-central-1
```

## Summary

| Item | Value |
|---|---|
| Date (UTC) | 2026-10-13, 12:50 to 16:24 |
| Commit on `main` | `3d13c9e` (the merge of #17) deployed first. The merges of #18 and #19 then deployed through the same pipeline. Flow 5, the load runs and the destroy and rebuild ran on `c7e09d4` (the merge of #19) |
| Environment | `prod` in `eu-central-1`, `https://<domain>`: 2 app tasks of 0.5 vCPU each |
| Load client in the region | A one-off Fargate task in `learntrack-prod`: 1 vCPU, 2 GB, k6 2.3.0, the script at `eed7952` (unchanged since) |
| Laptop | A Mac in Vietnam, k6 2.3.0 in Docker through `run.sh` |

| Target | Measured | Verdict |
|---|---|---|
| groupProgress p95 under 300 ms on Full Size Group (60 learners, 100 assignments), 10 at once, from `eu-central-1` | p95 9,231 ms. Only 138 of 200 calls started in the 60-second window | Not met |
| groupProgress p95 under 300 ms, 10 at once, from the laptop | p95 7,766 ms. Only 148 of 200 calls started in the window | Not met |
| Every MCP read tool p95 under 1 s, from `eu-central-1` (slowest tool) | `find_hardest_assignments`, p95 417 ms | Met |
| Every MCP read tool p95 under 1 s, from the laptop (slowest tool) | `find_hardest_assignments`, p95 678 ms | Met |
| `assign_content` under 1 s in the manual flow | Step a, 14:04:30 UTC: the two `/mcp` requests of that second took 83 and 155 ms, and the write is one of them. Steps b and d: not recorded | Met |
| `change_due_date` under 1 s in the manual flow | Step c, 14:10:51 UTC: 189 and 113 ms, and the write is one of them | Met |
| Flows 1, 4, 5 and 6 from Claude Desktop | Every step passed. Flow 5 passed after the fix in #19, and its step d by a direct tool call (section 3) | Met |
| No alarm in `ALARM` after the load runs | All 8 alarms `OK` after the runs. `learntrack-prod-response-time` was in `ALARM` for 6 minutes during each verdict run | Met |
| Destroy and rebuild with no manual step beyond the bootstrap | About 55 minutes from the destroy start to the first answer. Beyond the bootstrap: only the seed task, and the reconnect a person makes | Met |
| Scheduled housekeeping (#14, test plan step 7) | The schedule started the task, it exited 0, and no failure email was sent (section 7) | Met |

Diagnostic, not the verdict: groupProgress with 1 call at once, from `eu-central-1`: p50 507 ms, p95 669 ms, p99 1,084 ms (section 4).

## Why groupProgress misses

What was measured:

- In the verdict run from `eu-central-1`, while groupProgress ran (14:35 to 14:36 UTC), the app service's CPU maximum was 100% and 99.3% in its two minutes. The RDS CPU maximum was 10.1%.
- The MCP tool `get_group_progress` computes the same `GroupProgress` for the same group. Its p95 was 267 ms in the same run and 274 ms in the diagnostic run.
- In the diagnostic run, one groupProgress call at a time took p50 507 ms and p95 669 ms. The app service's CPU was at most 51% (average 46%): one request uses one task's 0.5 vCPU.
- The answer is 588 KB (measured locally): 60 learners and 6,000 statuses.

The lead's reading: the app tasks are CPU-bound, the database is idle, and the MCP tool computes the same progress in a fraction of the time, so the cost is not the progress query. It is the GraphQL layer, which resolves the 588 KB answer field by field on 0.5 vCPU per task. One PHP request uses at most one CPU, so 10 calls at once on 2 tasks wait in a queue whatever the CPU size. A configuration change cannot reach the target. The lead decided not to change the CPU.

## Follow-up

This project opens no new tickets, so the follow-up for the missed target is recorded here: make the full-size groupProgress answer cheaper in the GraphQL layer (for example lighter resolvers for the statuses, or a page of learners per request), then measure again with [scripts/load/](../scripts/load/README.md). Until then the verdict stays "not met".

## Findings

The check found three defects. Each was fixed in its own pull request:

- **The consent page answered twice** ([#18](https://github.com/GilbertChian8/learntrack/pull/18)). In Flow 1 a second Approve, sent while the first was still loading, got 403 with Passport's raw text. Now the first click disables both buttons, and a request that is already answered shows the LearnTrack message page.
- **The MCP text had no ids** ([#19](https://github.com/GilbertChian8/learntrack/pull/19)). Claude Desktop gives the model only a tool's text content, and the text was a summary without ids. In Flow 5a Claude could not target the learners who are behind. The text is now the structured content as JSON.
- **The seed had no full-size group** ([#17](https://github.com/GilbertChian8/learntrack/pull/17)). The largest seeded group had 50 learners, smaller than the group the target names. The seed now has Full Size Group (60 learners, 100 assignments), and the production image can run the seed (Faker moved to `require`).

The check also showed a starter kit leftover: the login and consent pages showed the Laravel logo. [#20](https://github.com/GilbertChian8/learntrack/pull/20) replaces it, and the Laravel favicons, with a LearnTrack mark.

Observations, with no change:

- Claude Desktop's Auto mode ran `assign_content` in Flow 5a without asking. Auto mode runs the calls it assesses as lower risk, and the tool is annotated as not destructive and safe to repeat. The prompt is the client's choice. The server's guarantees are [ADR-004](adr/ADR-004-ai-writes.md): additive, idempotent writes, each with an audit row. Steps b to d ran in Manual mode.
- In Flow 5b Claude miscounted lists it wrote itself ("13 learners" over 12 names, "9 learners" for 10). The tool data was right (20 behind: 12 overdue, 10 with a low average, 2 both), and Claude corrected its counts in step c.
- After the rebuild, Claude Desktop did not start the login by itself. It told the educator to reconnect in Settings, Connectors (section 6).

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
| Run | [38400324128](https://github.com/GilbertChian8/learntrack/actions/runs/38400324128), `3d13c9e` (the merge of #17), 12:50:44 to 12:57:24 UTC |
| Image tag | `learntrack-app:3d13c9ecd51934c4d6aafae1b84cd4f7b61cb766` |
| App task definition revision | `learntrack-prod-app:6` |
| Housekeeping task definition revision | `learntrack-prod-housekeeping:6` |
| Environment built by | [38392184044](https://github.com/GilbertChian8/learntrack/actions/runs/38392184044), `f286341`: 70 resources added |
| Later deploys during the check | [38406416733](https://github.com/GilbertChian8/learntrack/actions/runs/38406416733) (`6e41246`, #18) and [38408282118](https://github.com/GilbertChian8/learntrack/actions/runs/38408282118) (`c7e09d4`, #19, app revision 8) |

## 2. Seed

A seeded environment is a demo (TDD, Launch plan): its accounts share the password published in this repository, it never holds real data, and real people need account management first, which is future work.

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

Seed once per environment. A second run adds the content library and the first institution again, then stops on a duplicate email. After that, a content title matches two items, so Flow 5 gets candidates instead of an assignment. The production image refuses `migrate:fresh`, so only a destroy and rebuild cleans it.

| Item | Value |
|---|---|
| Seed task | `94aae48fc81a4678bc45b648441aedc2`, started by `launch-check-seed` |
| Started and stopped (UTC) | 12:58:41 to 12:59:16 |
| Exit code | 0 |
| Log | Nothing to migrate. PassportSeeder 526 ms DONE, ContentSeeder 494 ms DONE, DemoSeeder 8,679 ms DONE |

## 3. Claude Desktop

Connect Claude Desktop as `anna.keller@example.edu` (password `password`): Settings, Connectors, add a custom connector with the URL `https://<domain>/mcp`. Keep a screenshot or the transcript of each step, and note the time of each write (for the write tool times below).

| Item | Value |
|---|---|
| Claude Desktop version, model | Version not recorded. Model Claude Opus 5.5 (the input bar showed "Opus 5.5" and "Extra") |
| Screenshots and transcripts | The transcripts are quoted below. The screenshots are not committed (the real domain, the account's own Claude UI) |

**Flow 1: connect** (13:02 to 13:04 UTC). Connect opens the LearnTrack login page; log in; the consent page names the client and what it can do; Approve; the seven tools appear in Claude.

| Step | Evidence | Result |
|---|---|---|
| Add the connector | Claude Desktop found the OAuth sign-in and registered itself: client registration 201 at 13:03:31 and 13:03:41 | Pass |
| Login page | Authorize 302 to the login page. One failed login (302), then login 409 (Inertia's redirect to the consent page) | Pass |
| Consent page | Authorize 200 with the consent page. Approve 302 at 13:04:19.8, token 200 at 13:04:21, `/mcp` 200 at 13:04:22. A second Approve at 13:04:20.4 and a third at 13:04:30 got 403 with Passport's raw text: the double submit, fixed in #18 | Pass, with a defect fixed in #18 |
| Tools listed in Claude | 5 read tools and 2 write tools | Pass |

Reconnect at about 13:11 UTC: disconnect, connect, log in, one Approve. Claude showed its "Connected" page, with no 403. So the 403 needs a second submit.

**Flow 4: ask** (about 13:16 UTC).

| Question | Expected | Transcript | Result |
|---|---|---|---|
| Which learners in Cardiology Group A are behind, and why? | The behind learners with their reasons (overdue assignment, or low average with the scores) | "20 of 43 learners in Cardiology Group A are behind." Then: overdue ECG Rhythm Strips Question Set (due Sep 20, 23 days late), 12 learners; average below 50, 10 learners; Howard Blanda and Leland Brekke both. The README's seed table says the same: 43 learners, 20 behind (10 overdue only, 8 low only, 2 both) | Pass |
| How is Sara Lindqvist doing? | Her summary: behind in Cardiology Group A, her first question set overdue | "Sara Lindqvist is behind in Cardiology Group A. Overdue: ECG Rhythm Strips Question Set, 23 days late (due Sep 20). Progress: 0 completed, 0 in progress, 3 not started, 1 overdue. Scores: none yet." | Pass |
| Which assignment in Cardiology Group A is the hardest? | The ranking with completion rates and average scores | Hardest first: Atrial Fibrillation Management 35% (15 of 43); Valvular Heart Disease 40% (2 of 5); Heart Failure Question Set 42%, average 63.1; ECG Interpretation Basics 42%; ECG Rhythm Strips Question Set 72%, average 64.7. Average scores for the question sets only | Pass |

**Flow 5: act.** Pick a due date in the future (D1) and a later one (D2). The check used D1 = 2026-10-27 and D2 = 2026-11-03, on `c7e09d4` (app revision 8). Steps b to d ran in a new chat in Manual mode ("Allow once").

| Step | Expected | Transcript | Result |
|---|---|---|---|
| a. "Assign the Hypertension Pharmacology Question Set to the learners who are behind in Cardiology Group A, due D1." Approve. | Claude asks first; the answer names the content, the learners and D1 | First try (about 13:20 UTC, before #19): nothing written. Claude: "The assign tool needs learner IDs to target only some learners, and the tools I used only return names." After #19 (14:04 UTC, 4 tool calls): "I assigned the Hypertension Pharmacology Question Set to the 20 learners in Cardiology Group A who are behind. It's due 2026-10-27 at 23:59 in your institution's time zone. None of them had it already, and the other 23 learners in the group didn't get it." Claude did not ask first: Claude Desktop was in Auto mode (Findings) | Pass after #19 |
| b. The exact same request again. Approve. | Nothing changed: they already have it | In the same chat Claude did not call `assign_content` (it remembered step a), so that was not a server test. In a new chat: "Nothing needed changing ... My call went through and LearnTrack reported 'unchanged', so nobody was added or assigned twice." | Pass |
| c. "Move the due date of that assignment to D2." Approve. | Changed, with D1 as the previous date and D2 as the new one | "now due 2026-11-03 instead of 2026-10-27, at 23:59 school time (UTC+01:00). Only the due date changed." | Pass |
| d. The request from step a again, with D1. Approve. | Already assigned with D2; nothing changes; change the due date to move it | Claude Desktop read the data (25 reads) and answered "already assigned ... due 2026-11-03 ... assigning again would change nothing", offering `change_due_date`, without calling `assign_content`. So the lead called the tool directly at 14:29:56 UTC, with a token from the real OAuth flow (client registration, web login, consent, PKCE): `assign_content` with group 1, content item 7, due date 2026-10-27 and the 20 learner ids. The answer: `isError` false, `changed` false, `result` `already_assigned`, message "Hypertension Pharmacology Question Set is already assigned to Cardiology Group A, due 2026-11-03T23:59:59+01:00, not 2026-10-27. Nothing changed. To move it, call change_due_date with assignment_id 195." The text content decodes to exactly the structured content | Pass |

**Flow 6: someone else's group.** Anna Keller does not teach Cardiology Group C. Find its id with the API (as in the audit step below, logged in as `hannah.clarke@example.edu`), then ask about that id and about an id that does not exist.

| Question | Expected | Transcript | Result |
|---|---|---|---|
| "Show the progress of group 8" (Cardiology Group C) | Not found, the same answer as below; nothing about the group | `get_group_progress` answered the tool error "Group not found." Claude: "Group 8 was not found. It may not exist, or it may not be one of your groups." No name, no count, nothing about the group | Pass |
| "Show the progress of group 999999." | Not found | The same tool error, "Group not found." Claude: "Group 999999 was not found either." | Pass |

**Write tool times.** Each request writes a "Request handled." log line with its route and `duration_ms`. List the `/mcp` lines of the Flow 5 window, and match each write to the line at the time of its approval:

```
aws logs start-query --log-group-name /learntrack/prod/app \
  --start-time <Flow 5 start, Unix seconds> --end-time <Flow 5 end, Unix seconds> \
  --query-string 'fields @timestamp, context.status, context.duration_ms | filter message = "Request handled." and context.route = "mcp" | sort @timestamp asc'
aws logs get-query-results --query-id <query id from the line above>
```

Claude Desktop sends its `/mcp` requests in pairs, about 0.6 s apart, for each tool use. So a write is one of the two lines of its second, and both are under 1 s.

| Call | Time (UTC) | duration_ms |
|---|---|---|
| a. `assign_content` | 14:04:30 | 83 (14:04:30.278) and 155 (14:04:30.938): the write is one of them |
| b. `assign_content` | Not recorded | Not recorded: the destroy deleted the log group before these lines were read |
| c. `change_due_date` | 14:10:51 | 189 (14:10:51.206) and 113 (14:10:51.856): the write is one of them |
| d. `assign_content` | 14:29:56 (the direct call) | Not recorded, for the same reason |

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
| Rows with `channel: mcp` | Two, nothing for steps b and d. Id 1, `assignment.created` at 14:04:30 UTC: assignment 195, content item 7, scope `learners`, 20 learner ids, due at 2026-10-27T22:59:59Z (23:59:59 in Berlin, CET after the change from summer time). Id 2, `assignment.due_changed` at 14:10:51 UTC: assignment 195, from 2026-10-27T22:59:59Z to 2026-11-03T22:59:59Z |

## 4. Load script

[scripts/load/](../scripts/load/README.md) logs in as `full.size.educator@example.edu`, who teaches Full Size Group: 60 learners and 100 assignments, the largest group the targets name. It runs groupProgress 200 times, 10 at once, then each of the five MCP read tools 100 times, with a token from the real OAuth flow. A run takes about 12 minutes. Run it once from `eu-central-1` and once from the laptop, not at the same time.

From `eu-central-1`, the launch check runs it as a one-off Fargate task in the `learntrack-prod` cluster: the same region as the app, no new infrastructure, no instance to manage. The task uses the app's private subnets and reaches the public load balancer through the NAT gateways. It runs the pinned k6 image with `sh -c`: it downloads the script from `raw.githubusercontent.com` at a fixed commit, runs it, and prints the summary JSON between two marker lines to its log. Its task definition (`learntrack-prod-loadcheck`) is temporary: it is deregistered and deleted afterwards. The task definition JSON and the commands are in [scripts/load/README.md](../scripts/load/README.md) (From eu-central-1, as a one-off Fargate task). An EC2 instance or CloudShell with `run.sh` works too.

From the laptop:

```
BASE_URL=https://<domain> EDUCATOR_EMAIL=full.size.educator@example.edu EDUCATOR_PASSWORD=password \
  scripts/load/run.sh laptop
```

Each run saves its raw k6 summary, report and log in `scripts/load/results/` (the Fargate run: the task's log, saved and split there by the commands in the README). Commit them and link them here. A failed request writes its URL to the log, so first replace the domain with `<domain>` in any file that holds it (`grep -l <the domain> scripts/load/results/*`).

The groupProgress call is the contract's query ([api-contract.md](api-contract.md), GraphQL, groupProgress), sent unchanged. It asks for every field the schema defines, the whole progress table a client dashboard shows:

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

The verdict is the run with 10 at once, the ticket's protocol. The diagnostic run below changes only that number.

In both verdict runs, a groupProgress call took about 4 seconds at p50, so only 138 and 148 of the 200 calls started in the 60-second window, and k6 exited 99 (a threshold failed). No request failed and no answer was a 429.

**From `eu-central-1`**

| Item | Value |
|---|---|
| Client | Fargate task `173beea37b084edf958a91e59e622d8d` (`learntrack-prod-loadcheck:1`, deregistered and deleted afterwards) |
| Started and finished (UTC) | 14:35:17 to 14:47:14 |
| k6 exit code | 99 |
| Requests | 652, none failed, no 429; checks 1,276 of 1,276 |
| Raw summary, report, log | [json](../scripts/load/results/eu-central-1-20261013T143517Z.json), [txt](../scripts/load/results/eu-central-1-20261013T143517Z.txt), [log](../scripts/load/results/eu-central-1-20261013T143517Z.log) |

| Operation | Count | p50 ms | p95 ms | p99 ms | Target | Verdict |
|---|---|---|---|---|---|---|
| groupProgress | 138 of 200 | 4,052.1 | 9,231.4 | 9,694.2 | under 300 ms | Not met |
| list_my_groups | 100 | 71.0 | 135.5 | 174.0 | under 1 s | Met |
| get_group_progress | 100 | 197.8 | 267.4 | 342.6 | under 1 s | Met |
| find_learners_behind | 100 | 197.0 | 247.5 | 262.9 | under 1 s | Met |
| get_learner_summary | 100 | 72.5 | 112.7 | 223.9 | under 1 s | Met |
| find_hardest_assignments | 100 | 197.3 | 417.4 | 476.0 | under 1 s | Met |

**From the laptop**

| Item | Value |
|---|---|
| Client | A Mac in Vietnam, k6 2.3.0 in Docker through `run.sh` |
| Started and finished (UTC) | 15:07:31 to 15:19:10 |
| k6 exit code | 99 |
| Requests | 662, none failed, no 429; checks 1,296 of 1,296 |
| Raw summary, report, log | [json](../scripts/load/results/laptop-20261013T150731Z.json), [txt](../scripts/load/results/laptop-20261013T150731Z.txt), [log](../scripts/load/results/laptop-20261013T150731Z.log) |

| Operation | Count | p50 ms | p95 ms | p99 ms | Target | Verdict |
|---|---|---|---|---|---|---|
| groupProgress | 148 of 200 | 4,078.4 | 7,766.1 | 8,627.3 | under 300 ms | Not met |
| list_my_groups | 100 | 310.9 | 393.2 | 483.1 | under 1 s | Met |
| get_group_progress | 100 | 433.1 | 530.9 | 568.4 | under 1 s | Met |
| find_learners_behind | 100 | 437.3 | 546.6 | 686.2 | under 1 s | Met |
| get_learner_summary | 100 | 316.1 | 546.7 | 648.4 | under 1 s | Met |
| find_hardest_assignments | 100 | 433.7 | 678.1 | 879.3 | under 1 s | Met |

**Diagnostic, not the verdict: groupProgress 1 at once, from `eu-central-1`**

The same run with one groupProgress call at a time. It shows a call's own time, without waiting behind other calls, so the two runs together separate the work from the queueing. It takes about 17 minutes. Run it alone, not next to another run:

```
GROUP_PROGRESS_CONCURRENCY=1 BASE_URL=https://<domain> EDUCATOR_EMAIL=full.size.educator@example.edu EDUCATOR_PASSWORD=password \
  scripts/load/run.sh eu-central-1-diagnostic
```

As a Fargate task, add `{"name":"GROUP_PROGRESS_CONCURRENCY","value":"1"}` to the `environment` in the `run-task` overrides.

| Item | Value |
|---|---|
| Client | Fargate task `dc9bbad607b54e72a7226a4fa2e84c7f` |
| Started and finished (UTC) | 14:48:57 to 15:06:08 |
| k6 exit code | 0 |
| Requests | 714, none failed, no 429; checks 1,400 of 1,400 |
| Raw summary, report, log | [json](../scripts/load/results/eu-central-1-diagnostic-20261013T144857Z.json), [txt](../scripts/load/results/eu-central-1-diagnostic-20261013T144857Z.txt), [log](../scripts/load/results/eu-central-1-diagnostic-20261013T144857Z.log) |
| groupProgress p50, p95, p99 (ms), 1 at once | 507.3, 669.0, 1,084.2 (200 of 200 calls) |
| MCP read tools p95 (ms) | list_my_groups 151.6, get_group_progress 274.0, find_learners_behind 232.5, get_learner_summary 91.6, find_hardest_assignments 380.8: all under 1 s |
| App service CPU while groupProgress ran (14:49 to 14:50) | At most 51%, average 46% |

## 5. Alarms and the load balancer

After the load runs, no alarm is in `ALARM`:

```
aws cloudwatch describe-alarms --alarm-name-prefix learntrack-prod \
  --query 'MetricAlarms[].[AlarmName,StateValue]' --output table
```

The load balancer's target response time p95 (in seconds) for each 5 minutes of the runs. Round the first run's start down and the last run's finish up to 5 minutes:

```
lb="$(aws elbv2 describe-load-balancers --names learntrack-prod --query 'LoadBalancers[0].LoadBalancerArn' --output text)"
aws cloudwatch get-metric-statistics --namespace AWS/ApplicationELB --metric-name TargetResponseTime \
  --dimensions Name=LoadBalancer,Value="${lb#*:loadbalancer/}" \
  --start-time <start, rounded down, UTC> --end-time <finish, rounded up, UTC> --period 300 \
  --extended-statistics p95 --output table
```

| Item | Value |
|---|---|
| Alarms and their states | All 8 alarms `OK` after the runs. `learntrack-prod-response-time` went to `ALARM` once in each verdict run, 14:38:55 to 14:44:55 and 15:10:55 to 15:16:55 UTC, with an email each time |
| ALB target response time p95, `eu-central-1` run | 8.75 s for 14:35 to 14:40, the groupProgress part (per minute: 8.97 s and 9.35 s). 0.22 to 0.31 s per minute in the MCP part |
| ALB target response time p95, laptop run | 7.13 s for 15:05 to 15:10, the groupProgress part |
| ALB target response time p95, the other 5-minute periods | 0.23 to 0.68 s |

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
| destroy.yml | [38421111896](https://github.com/GilbertChian8/learntrack/actions/runs/38421111896) (by hand, `confirm=destroy`): 70 resources destroyed | 15:19:47 | 15:26:13 |
| deploy.yml | [38422093164](https://github.com/GilbertChian8/learntrack/actions/runs/38422093164) (by hand, `c7e09d4`): 70 resources added, migrations exit 0, `learntrack-prod-app:10`, `learntrack-prod-housekeeping:10`. `/up` answered 200 at 15:48 | 15:26:34 | 15:47:38 |
| Seed | Task `abcc568d2e284e949c3e9019e3f627ec`, exit 0: PassportSeeder 546 ms, ContentSeeder 514 ms, DemoSeeder 10,991 ms | 15:48:47 | 15:49:23 |
| First MCP answer | Claude Desktop, "Which groups do I teach?", after the reconnect (below) | About 16:12 | About 55 minutes after the destroy start |

Claude Desktop, after the rebuild:

1. The call failed. Claude said the connection "had been invalidated. Only you can fix that by reconnecting it", and pointed to Settings, Connectors. It did not start the login by itself.
2. Reconnect: the login page, then the consent page: "Connect Claude. You are signed in as Anna Keller. Claude will be able to: Read the progress of your groups, Assign content, Change due dates". One Approve, then Claude's "Connected" page.
3. In the same chat: "LearnTrack is working again. You teach three groups at Northside Medical School": Cardiology Group A, 43 learners and 6 active assignments; Nephrology Group A, 20 and 7; Neurology Group A, 50 and 5. These are Anna Keller's groups and learner counts in the README's seed table.

| Item | Value |
|---|---|
| Elapsed, destroy start to the first answer | About 55 minutes |
| Manual steps beyond the bootstrap | None in the environment: two workflow runs started by hand and the one-off seed task. In Claude Desktop, the reconnect a person makes |

## 7. Scheduled housekeeping (#14, test plan step 7)

Step 7 of the test plan in [#14](https://github.com/GilbertChian8/learntrack/pull/14) (ticket 05): the scheduled housekeeping run writes its log lines and sends no failure email. On the rebuilt environment it was checked once at a near time instead of 03:00 UTC: the real schedule, nothing else changed.

| Item | Value |
|---|---|
| Schedule | `learntrack-prod-housekeeping`, `cron(0 3 * * ? *)` UTC. Moved once to `cron(21 16 13 10 ? 2026)` at 16:15 UTC, with everything else unchanged |
| Task | `94004cfa94f844d4a1aeeee2f88fc92e`, started by EventBridge Scheduler at 16:21:44 UTC (`startedBy` `chronos-schedule/learntrack-prod-hou`), revision `learntrack-prod-housekeeping:10`, the pipeline's revision |
| Ran (UTC) | 16:22:12 to 16:22:37, exit 0 |
| Log | "Purged revoked items and items expired for more than 0 seconds." and "Deleted 6 cache rows, 0 cache locks and 0 sessions." |
| Failure email | None: both failure rules had 0 invocations, and `housekeeping-not-started` stayed `OK` |
| Restored | `cron(0 3 * * ? *)` UTC at 16:24 UTC. `terraform plan` exit 0, "No changes" |
