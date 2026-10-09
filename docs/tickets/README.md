# Tickets

One ticket per work item of the [TDD](../tdd.md) (Flow Walkthroughs with Estimates). One ticket is one branch and one pull request. Every ticket names the documents to read first, the files it may touch, the steps, and the acceptance tests that define "done". The rules for all of them are in [CLAUDE.md](../../CLAUDE.md).

| # | Ticket | Days | Depends on | Phase |
|---|---|---|---|---|
| 01 | [Repository and local development](01-repository-and-local-development.md) | 2.5 | none | 0 |
| 02 | [Data model, migrations and seed](02-data-model-migrations-and-seed.md) | 2 | 01 | 0 |
| 03 | [Authentication, roles and Policies](03-authentication-roles-and-policies.md) | 2 | 02 | 0 |
| 04 | [Terraform: network and data](04-terraform-network-and-data.md) | 3 | 01 | 1, lane C |
| 05 | [Terraform: compute, schedule and pipeline](05-terraform-compute-schedule-and-pipeline.md) | 3 | 04 | 1, lane C |
| 06 | [Groups, membership, learners and content](06-groups-membership-learners-and-content.md) | 2 | 03 | 1, lane A |
| 07 | [Assignments and the audit log](07-assignments-and-the-audit-log.md) | 2.5 | 06 | 1, lane A |
| 08 | [Learner endpoints](08-learner-endpoints.md) | 2 | 03, 06, 09 | 1, lane B |
| 09 | [GroupProgress and BehindRule](09-group-progress-and-behind-rule.md) | 3 | 03 | 1, lane B |
| 10 | [GraphQL groupProgress](10-graphql-group-progress.md) | 1.5 | 09 | 1, lane B |
| 11 | [MCP server and the OAuth flow](11-mcp-server-and-the-oauth-flow.md) | 3 | 03 | 2 |
| 12 | [Read tools](12-read-tools.md) | 3 | 06, 09, 10, 11 | 2 |
| 13 | [Write tools](13-write-tools.md) | 1.5 | 07, 11 | 2 |
| 14 | [Hardening and documentation](14-hardening-and-documentation.md) | 2 | 05, 08, 12, 13 | 3 |
| 15 | [Launch check](15-launch-check.md) | 1 | 14 | 3 |

Total: 34 days, the TDD's Release 1 estimate.

## Phases and lanes

Tickets inside one lane are sequential. Lanes run in parallel.

```
Phase 0  (one builder, sequential)      01 -> 02 -> 03
Phase 1  lane A (REST)                  06 -> 07
         lane B (progress engine)       09 -> 10 -> 08
         lane C (infrastructure)        04 -> 05          (may start right after 01)
Phase 2  (Claude)                       11, then 12 and 13 in parallel
Phase 3  (one builder, sequential)      14 -> 15
```

Why this order:

- 01 to 03 set the shape of everything (containers, tables, auth, the 404 rule, the error shape). Parallel work before they are merged produces merge conflicts, not speed.
- Lane C touches only `infra/` and `.github/`, so it never conflicts with the application lanes.
- 08 needs the pair query from 09 and the paging helper from 06, so it sits at the end of lane B, after lane A's first ticket has merged.
- 12 needs the query classes (09), the GraphQL answer to compare against (10), MyGroups (06) and the server (11). 13 needs the action classes (07) and the server (11).

## Working in parallel with worktrees

Each builder works in its own git worktree on its own branch. From the main checkout:

```
git fetch origin
git worktree add ../learntrack-06 -b feat/06-groups origin/main
cd ../learntrack-06
```

Each worktree needs its own test database, so parallel test runs do not collide. One MySQL container (from the main checkout's `docker compose up -d mysql`) serves them all:

```
docker compose -f ../learntrack/compose.yaml exec mysql \
  mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS learntrack_test_06"
cp .env.testing.example .env.testing   # then set DB_DATABASE=learntrack_test_06
```

When the pull request is merged:

```
cd ../learntrack
git worktree remove ../learntrack-06
git branch -d feat/06-groups
```

## How a ticket runs

1. Read the ticket, then the TDD sections and contract sections it lists under "Read first". Do not start from memory of the design; read it.
2. Create the branch and worktree as above.
3. Write the acceptance tests first. They are the definition of done.
4. Build until they pass, keeping to "Allowed paths".
5. Run every check in CLAUDE.md.
6. Open the pull request with the ticket's PR checklist filled in. The title starts with the ticket number: `06: Groups, membership, learners and content`.
7. Review comments are addressed on the same branch. Squash on merge.

A ticket that turns out to need a design change stops and raises it. It does not change the design quietly.
