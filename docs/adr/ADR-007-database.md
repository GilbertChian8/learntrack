# ADR-007: Database engine

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

The data is relational: institution, group, learner, content item, assignment, progress, audit log, OAuth tokens. Status and the behind rule are aggregates over learner and assignment pairs. Scale is small: 5,000 learners, 300 groups, 50,000 progress updates a day, which is under 1 write per second. The requirements fix MySQL for local development (docker compose with app and MySQL). The uptime target is 99.9%, and the database is the only stateful part of the chain.

## Decision required

Which database stores LearnTrack's data?

## Alternatives evaluated

### Option 1: MySQL 8 on RDS, Multi-AZ

**Pros**

- Relational data and aggregates are plain SQL with indexes
- MySQL 8 has window functions, CTEs and generated columns, which the "unique while active" rule on assignments needs
- The same engine runs in docker compose and in AWS, so every query behaves the same in both
- RDS Multi-AZ gives the 99.95% the uptime math needs, with automatic failover

**Cons**

- We pay for a standby that is idle most of the time
- MySQL has no partial indexes, so the active-assignment rule uses a generated column
- A failover takes about a minute

### Option 2: PostgreSQL on RDS

**Pros**

- Partial indexes and a richer SQL dialect

**Cons**

- A second engine next to the MySQL the requirements name for local development, so development and production would differ
- Nothing in our queries needs what MySQL lacks

### Option 3: DynamoDB

**Pros**

- No instance to run
- Very high write capacity

**Cons**

- We need under 1 write per second
- The behind rule is a join and an aggregate across pairs, which DynamoDB cannot do in one query, so we would keep counters in sync by hand
- The "no N+1" requirement turns into many GetItem calls

## Decision

- Option 1.
- Every hard query here (status per pair, behind per learner, hardest assignment) is a GROUP BY over a few thousand rows, which an indexed MySQL table answers in milliseconds. A different engine buys nothing these queries use.
- One engine from laptop to production keeps the N+1 and performance tests honest.
- Multi-AZ is the part of the chain we cannot skip: the database is the only stateful piece, and its 99.95% is what makes 99.93% possible.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)
- Amended 10/10/2026: the active-assignment rule uses a plain removed_key column (0 while active, the row's id once removed) with a CHECK, not a generated column, because MySQL forbids a generated column based on the auto-increment id. The decision is unchanged.
