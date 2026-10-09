# ADR-014: Background work

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

Overdue and behind are computed at read time (ADR-008), so no job flips statuses. What remains is housekeeping: purging expired and revoked OAuth tokens and deleting expired cache rows. Laravel's scheduler expects a process that runs schedule:run every minute. With two web tasks, running it in both runs every job twice.

## Decision required

Where does scheduled work run?

## Alternatives evaluated

### Option 1: An EventBridge Scheduler rule that runs a one-off ECS task once a day with the housekeeping command

**Pros**

- Runs exactly once
- Nothing runs inside the web tasks
- The task uses the same image and the same secrets

**Cons**

- One schedule rule, one task definition and one IAM role in Terraform
- A job cannot run more often than the rule says

### Option 2: The Laravel scheduler inside both web tasks, with onOneServer() and the cache lock

**Pros**

- No extra infrastructure

**Cons**

- A per-minute cron process in every web task
- The lock lives in the database cache, so the web tasks spend effort every minute on a job that runs once a day

### Option 3: A dedicated always-on scheduler task

**Pros**

- The usual Laravel deployment shape

**Cons**

- A third Fargate task running 24 hours a day to run one command a day

### Option 4: No background work at all

**Pros**

- Simplest

**Cons**

- Expired tokens and cache rows accumulate forever
- Small today, but a table that only grows is a leak

## Decision

- Option 1.
- The whole scheduled workload is one command a day, so its shape should cost one rule, not one process.
- A daily purge keeps the OAuth tables honest, and the same task is where a future daily report would go.
- If a later release adds per-minute jobs, for example email reminders, option 3 is the upgrade, and the app does not change: the schedule stays in one place in the code.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)
