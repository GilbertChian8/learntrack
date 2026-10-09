# ADR-009: GraphQL library

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

Requirement 7 asks for one GraphQL query that returns a group, its assignments, every learner's status and the behind flag. There is no other GraphQL in v1. The query must reuse the Policy (ADR-006) and the behind rule (ADR-008), and it must not create N+1 queries.

## Decision required

Which library serves GraphQL in the Laravel application?

## Alternatives evaluated

### Option 1: Lighthouse

**Pros**

- Schema-first SDL with Laravel directives
- Built-in batching against N+1
- The most used and best maintained Laravel GraphQL package
- A custom resolver can call our query service directly

**Cons**

- A large dependency for one query
- Its directives need reading before debugging

### Option 2: rebing/graphql-laravel

**Pros**

- Code-first in PHP
- Smaller

**Cons**

- N+1 protection and authorization are hand-written
- A smaller community

### Option 3: webonyx/graphql-php directly

**Pros**

- No framework glue, full control

**Cons**

- We write the schema, the HTTP endpoint, the auth and the error format ourselves, for one query

## Decision

- Option 1.
- One query makes this a low-impact decision. What matters is that the resolver calls the same query service as REST and MCP, which every option allows.
- Lighthouse adds the two things we would otherwise write by hand: a guarded endpoint that shares the auth:api guard, and a schema a client team can read. The resolver does the group lookup through the Policy, so the answer for a group outside the caller's scope is the same "not found" as REST (ADR-006).
- Honest note: if Lighthouse's upgrade cost ever exceeds its value for one query, option 3 is about a day of work.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)
