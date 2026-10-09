# 15: Launch check

**Estimate:** 1 day | **TDD item:** 15 | **ADRs:** ADR-016 | **Depends on:** 14 | **Phase:** 3

## Goal

Prove the system against its targets on the real infrastructure: the pipeline deploys it, Claude Desktop runs the four Claude flows end to end, the performance numbers meet the blueprint's targets, and the environment can be destroyed and rebuilt from code. The results are written down.

## Read first

- Blueprint: User Flows (Flows 1, 4, 5 and 6), the success metrics.
- TDD: item 15, Launch plan (Success check after launch), Testing strategy (Timing check, Manual pass before release).
- Requirements: Performance, Availability.

## Allowed paths

`docs/launch-check.md` (new), `scripts/load/**`, `README.md` (a link to the results), `infra/**` only for a fix that the check uncovers, with the reason in the PR.

## Steps

1. Deploy `main` through `deploy.yml`. Record the run link, the image tag and the task definition revision.
2. Seed the deployed database once (`php artisan migrate --seed` as a one-off ECS task with the housekeeping task definition and a command override), and record how.
3. Connect Claude Desktop to `https://<domain>/mcp` as the demo educator and run, with screenshots or transcripts: Flow 1 (connect, login, consent); Flow 4 (which learners in Cardiology Group A are behind, and why; how is one named learner doing; which assignment is hardest); Flow 5 (assign content to the learners who are behind with a due date; repeat the exact same assign and confirm Claude reports nothing changed; then move the due date; then repeat the original assign once more and confirm Claude reports it is already assigned with the new date and changes nothing); Flow 6 (ask about a group id the educator does not teach and confirm "not found" with no leak). Check the audit endpoint afterwards and record the rows with `channel: mcp`.
4. `scripts/load/`: a small script (k6, or a short Node script with `autocannon`) that logs in as the demo educator, runs `groupProgress` on the largest seeded group 200 times at 10 concurrent, and calls each of the five MCP read tools 100 times each with a valid OAuth token. Report p50, p95 and p99 per operation.
5. Targets: `groupProgress` p95 under 300 ms, every MCP tool p95 under 1 s, measured from a client in the same region (an EC2 instance or a Cloud Shell in `eu-central-1`) and once from a laptop, both recorded.
6. Check the alarms page: no alarm in `ALARM` state after the load run. Record the ALB target response time p95 from CloudWatch for the same window.
7. Run `destroy.yml`, then `deploy.yml`, then seed again, then one MCP question. Record the elapsed time and that no manual step was needed beyond the bootstrap.
8. Write `docs/launch-check.md`: date, commit, environment, every number above, every screenshot or transcript reference, and a verdict per target (met or not met). If a target is not met, open a ticket with the measurements and leave the verdict honest.

## Acceptance tests

- `docs/launch-check.md` exists with every item from the steps filled in; no number is estimated.
- `groupProgress` p95 under 300 ms on the largest seeded group; every MCP read tool p95 under 1 s; both write tools answered under 1 s in the manual flow.
- The four flows ran against the deployed environment with the transcripts attached, including the repeated `assign_content` answering unchanged and the foreign group answering not found.
- The destroy and apply round trip completed with no manual step and the application answered afterwards.
- The pipeline run, the load script and its raw output are linked from the document.

## Out of scope

Tuning beyond a configuration fix; any new feature. A missed target produces a ticket, not a last-minute redesign.

## PR checklist

- [ ] Title `15: Launch check`
- [ ] Every target has a measured number and a verdict
- [ ] Flows 1, 4, 5 and 6 evidenced
- [ ] Destroy and apply round trip evidenced
- [ ] Documents changed, if any, listed with the reason
