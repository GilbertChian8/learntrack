# ADR-006: Answer for data the caller may not see

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

An educator may only see and act on the groups they teach, and a learner only on their own assignments (requirement 1). Flow 6 asks that a question about someone else's group behaves like a question about a group that does not exist. REST, GraphQL and MCP all face the same choice.

## Decision required

What does the system answer when a resource exists but the caller may not see it?

## Alternatives evaluated

### Option 1: 403 Forbidden

**Pros**

- Honest about the reason
- Standard HTTP meaning

**Cons**

- Confirms the group exists, so a caller can enumerate ids and learn how many groups other educators run
- For an AI it invites a retry with another id

### Option 2: 404 Not Found for anything outside the caller's scope

**Pros**

- No existence leak
- The same answer for "not yours" and "not there", which is what most multi-tenant APIs do
- For Claude, "not found" ends the attempt cleanly

**Cons**

- A typo in an id looks the same as a permission problem, so support reads the request log to tell them apart

### Option 3: 404 for reads, 403 for writes

**Pros**

- Writes get a clearer error

**Cons**

- Two rules to remember
- The write still confirms the resource exists

## Decision

- Option 2, with one exception.
- The same answer in every interface, from one Policy: REST answers 404, GraphQL answers a null result with a "not found" error, and the MCP tool says the group, learner or assignment was not found. No interface leaks what another hides.
- The exception is about the role, not the resource: a learner calling an educator route gets 403, because the route itself is not for them and no specific resource is revealed.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)
