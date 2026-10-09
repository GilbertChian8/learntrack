# ADR-004: What the AI is allowed to change

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

Requirement 8 lists two write tools, assign_content and change_due_date, and says write tools only add or update data and never delete it. The REST API lets an educator do more, including removing learners and assignments. An AI client can misread an instruction, and the educator approves a tool call in a dialog, often without reading every argument.

## Decision required

Which actions does the MCP server expose for writing?

## Alternatives evaluated

### Option 1: Read-only MCP, no write tools

**Pros**

- Zero risk of a wrong change through the AI

**Cons**

- The main flow stops at "who is behind"
- The educator leaves the conversation to act, and the remediation loop that gives the product its value is broken

### Option 2: Full parity with REST: create, update, remove

**Pros**

- Everything in one place

**Cons**

- "Remove Sara from Group A" or "remove the ECG assignment" is one misread away from losing sight of a learner's progress
- An undo needs a restore flow we do not have

### Option 3: Additive and reversible writes only: assign content, change a due date

**Pros**

- Every change the AI can make can be undone by the educator with another additive change or in the REST API
- No data disappears
- Approval in Claude, natural idempotency (ADR-005) and an audit row make each change visible and safe to repeat

**Cons**

- Removing anything means leaving the conversation for the REST API

## Decision

- Option 3.
- The two writes cover the whole remediation loop: "assign ECG basics to the learners who are behind, due Friday" and "give them until Monday". That loop is the reason the write tools exist.
- Every effect is reversible without a restore feature: a new assignment can be removed in REST, a due date can be moved back.
- The tools are annotated as not destructive and idempotent, and every change writes an audit row (who, what, when, through which channel), so the support question "who changed this due date" always has an answer.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)
