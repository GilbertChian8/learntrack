# ADR-008: Where status and the behind rule are computed

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

Overdue is derived: the due date has passed and the assignment is not completed (requirement 5). Behind is derived from overdue and from the average question set score (requirement 6), and the rule must live in one place. The GraphQL group query must answer in under 300 ms for 60 learners and 100 assignments (6,000 pairs), with a query count that does not grow with learners or assignments. MCP tools must answer in under 1 s and give the reason behind each answer.

## Decision required

Is status (and behind) stored, or computed when read, and where does the computation live?

## Alternatives evaluated

### Option 1: Computed in SQL at read time, in one query class

**Pros**

- Always correct, because "now" is part of the query, so no job has to flip statuses at midnight
- One class owns the SQL that REST, GraphQL and MCP all call, so the rule lives in one place by construction
- 6,000 pairs is a few aggregate queries of a few milliseconds each with the right indexes

**Cons**

- Every read recomputes; a cache can be added later if a dashboard starts polling
- The SQL is the hardest code in the system and needs tests at full size

### Option 2: A stored status, updated by a scheduled job

**Pros**

- Reads are a plain SELECT

**Cons**

- Wrong between runs: an assignment due at 09:00 still reads "in progress" until the job runs
- The job is one more moving part
- The rule lives in two places, the job and the writes

### Option 3: Load the rows into PHP and compute there

**Pros**

- Readable PHP

**Cons**

- 6,000 rows loaded per request
- The rule is split: SQL decides which rows, PHP decides the status
- Easy to grow into N+1 the first time someone adds a relation

## Decision

- Option 1.
- "Overdue" is a fact about time, and the only place it is always right is the query that reads it.
- The requirement that the rule lives in one place is met structurally: one query service owns the SQL for status, behind and the reasons, and the three interfaces only format its result.
- At our scale the cost is milliseconds, and the TDD's query-count test locks the number of queries so it cannot grow with the group.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)
