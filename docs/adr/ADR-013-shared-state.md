# ADR-013: Shared state across tasks

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

Two stateless tasks run behind an ALB with no sticky sessions. Three things in Laravel keep state between requests: the web session, which the login and consent pages use during the OAuth flow, the cache, and the rate-limit counters, which the login and MCP endpoints need. If any of these lives in a task's memory or disk, the next request can land on the other task, so the login fails at random or each task counts limits on its own. MCP itself keeps no session over HTTP: every call carries the token. Load is about 1 write per second.

## Decision required

Where do sessions, cache and rate-limit counters live?

## Alternatives evaluated

### Option 1: The database, through Laravel's database drivers for session, cache and rate limiting

**Pros**

- Already there, Multi-AZ and backed up
- One less service in the request path and in the uptime chain
- The database cache is Laravel's default driver, so no custom code
- At our load the extra writes are noise

**Cons**

- Every rate-limit check is a database write, and a cache hit is a query, not a memory read
- Redis is the move at tens of requests per second per task

### Option 2: ElastiCache Redis

**Pros**

- The industry default for sessions, cache and counters
- Atomic increments, sub-millisecond reads

**Cons**

- A cluster to pay for and to add to the uptime chain; Multi-AZ Redis for the 99.9% target doubles its cost
- More Terraform and one more container in docker compose, for a workload that would never notice the difference

### Option 3: DynamoDB

**Pros**

- Serverless, and Laravel has a driver

**Cons**

- A second store that does not run locally without an emulator
- The same complexity as Redis for less benefit

## Decision

- Option 1.
- The failure we protect against is "login breaks because two tasks disagree", and the database is the one store both tasks already share.
- At 1 write per second, Redis would be idle hardware in the critical path. The signal to revisit is database CPU spent on cache traffic.
- Everything else that could be task-local is removed too: APP_KEY and the Passport keys come from Secrets Manager, so a token signed by one task verifies on the other.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)
