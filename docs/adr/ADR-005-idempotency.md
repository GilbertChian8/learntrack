# ADR-005: How writes stay safe to repeat

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

Requirement 8 and the reliability section say a repeated write gives the same result and never creates a duplicate record. Repeats are normal here: Claude may retry a tool call after a timeout, an educator may say the same thing twice, and a learner's app may resend a progress update. The usual API answer is a client-generated Idempotency-Key header. The MCP client is an AI: it builds each tool call's arguments from the conversation, so a key it made once is not reliably the same key on a retry.

## Decision required

How do we make repeated writes harmless?

## Alternatives evaluated

### Option 1: A client-supplied idempotency key (a field in the tool input, a header in REST)

**Pros**

- The standard pattern for payment APIs
- Any payload can be made safe

**Cons**

- Claude does not reuse a key across retries, so the protection is theory
- A key the educator must say out loud defeats the purpose
- One more column and index on every write path

### Option 2: Natural idempotency: each write is a "make it so" operation keyed by what it means, enforced by unique constraints

**Pros**

- assign_content means "this group, or these learners, has this content by this date": a repeat finds the existing assignment and reports that nothing changed
- change_due_date to the same date is a no-op by definition
- A progress update is a PUT of a state (started, or completed with a score), so a repeat sets the same state
- The database enforces all of it

**Cons**

- Each write needs a clear natural key
- Each write needs a written rule for a repeat that carries a different value, for example the same content with a different due date

### Option 3: Both

**Pros**

- Belt and braces

**Cons**

- The key adds complexity without adding safety here, for the reason in option 1

## Decision

- Option 2.
- The protection comes from unique constraints and from the shape of the operations: an assignment is unique per group and content item while it is active, a progress row is unique per learner and assignment, and a due date is a value.
- The answer of every write says exactly what changed, including "nothing", so a retry is harmless and honest.
- The rules for a repeat with different values (same content, different due date; completed with a different score) are in the TDD, and each one is "report, do not overwrite".
- Ratified by: Gilbert Lavensky Chan (09/10/2026)
