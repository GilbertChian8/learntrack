# 07: Assignments and the audit log

**Estimate:** 2.5 days | **TDD item:** 7 | **ADRs:** ADR-004, ADR-005 | **Depends on:** 06 | **Phase:** 1, lane A

## Goal

The three assignment actions (AssignContent with its five rules, ChangeDueDate, RemoveAssignment), the assignment endpoints, the audit endpoint, and the due date parsing that REST and MCP share. After this ticket the write side of the system is complete and idempotent.

## Read first

- TDD: Educator endpoints (the last five rows and the AssignContent rules), Database Design notes (Soft delete, Subset assignments, Time zones, Audit log actions), Concurrency (the first and the sixth bullet).
- api-contract.md: Dates and times, Shared objects (`assignment`, `audit_entry`, the `changes` table), section 3 from `GET /api/v1/groups/{group}/assignments` to the end.
- ADR-004, ADR-005.

## Allowed paths

`app/Actions/{AssignContent,ChangeDueDate,RemoveAssignment}.php`, `app/Actions/Results/**`, `app/Exceptions/{AlreadyAssignedException,DueDateException}.php`, `app/Support/DueDate.php`, `app/Http/Controllers/Api/{AssignmentController,GroupAssignmentController,AuditController}.php`, `app/Http/Requests/**`, `app/Http/Resources/{AssignmentResource,AuditEntryResource,AssignResultResource}.php`, `app/Policies/AssignmentPolicy.php` (only if a method is missing), `routes/api.php`, `tests/Feature/Api/{Assignments,Audit}/**`, `tests/Unit/Actions/**`, `tests/Unit/Support/DueDateTest.php`.

## Steps

1. `DueDate::parse(string $input, Institution $institution): CarbonImmutable` accepts `YYYY-MM-DD` (23:59:59 in the institution's time zone, converted to UTC) and ISO 8601 with an offset; anything else throws `DueDateException::notADate($input)`, and a value not in the future throws `DueDateException::inThePast($today)`. The exception carries the case and the inputs, not a sentence: the REST layer renders 422 on `due_at` with the contract's fixed strings (`The due date must be in the future.`, `The due date must be a date (YYYY-MM-DD) or an ISO 8601 date-time.`), and the MCP tools (ticket 13) render mcp-tools.md's messages, which include today's date.
2. `AssignContent(User $educator, Group $group, ContentItem $item, CarbonImmutable $dueAt, array $learnerIds, AuditChannel $channel): AssignResult` implements the five rules of the TDD inside one transaction. Validation first: every learner id is a current member (else `ValidationException` on `learner_ids.N`). Then: no active row, create it (scope by the presence of learner ids, `assignment_learners` rows for scope learners), audit `assignment.created`; active row with a different `due_at`, throw `AlreadyAssignedException` carrying the existing assignment (REST renders 409 through ticket 03's handler, MCP renders `result: already_assigned`); same due date and scope group, `unchanged`; same due date and scope learners with ids, add the missing `assignment_learners` rows, audit `assignment.learners_added` with the added ids, result `learners_added` (or `unchanged` when nothing was added); same due date, scope learners, no ids, set scope to group (always, even when every current member already had it, because members who join later now get it), delete nothing, audit `assignment.learners_added` with the newly covered ids (possibly empty) and the scope change, result `learners_added`. "Same due date" compares UTC instants. The insert is wrapped so a duplicate key error (two requests racing) is caught, the row re-read, and the rules applied to it.
3. `AssignResult` carries `result`, `assignment`, `added` (learners), `already_had_it` (learners); it is what both REST and MCP format.
4. `ChangeDueDate(User $educator, Assignment $assignment, CarbonImmutable $dueAt, AuditChannel $channel): ChangeDueDateResult` (`changed`, `previous_due_at`, `assignment`) writes `assignment.due_changed` only when the instant differs. Callers resolve the assignment with `Scope::assignment` (ticket 03), so a removed or foreign assignment is not found before the action runs.
5. `RemoveAssignment(User $educator, Assignment $assignment, AuditChannel $channel)`, if `removed_at` is null, sets `removed_at` and `removed_key` (the assignment's own id) in the same write and writes `assignment.removed`; already removed, nothing. `chk_assignments_removed` refuses one without the other. Progress rows are never touched.
6. Endpoints: `GET /groups/{group}/assignments` (active, ordered by `due_at` then id, paged, `AssignmentResource` with `targeted_count` and `learners` as in the contract, computed without an N+1: one query for the targeted counts of the page), `POST /groups/{group}/assignments` (201 on created, 200 otherwise, 404 for an unknown content item with the contract's message), `PATCH /assignments/{assignment}`, `DELETE /assignments/{assignment}`, `GET /groups/{group}/audit` (newest first, paged, actor names resolved in one query).
7. Audit `changes` JSON exactly as the contract's table, with UTC `Z` instants.

## Acceptance tests

- `DueDate::parse`: `2026-10-16` for Europe/Berlin equals `2026-10-16T21:59:59Z`; `2026-10-16T18:00:00+02:00` equals `2026-10-16T16:00:00Z`; `next friday` throws the format message; yesterday throws the future message naming today's date; tests use a fixed clock.
- Assign to a group with no learner ids: 201, `result: created`, `assignment.scope: group`, `learners: null`, `added` lists every current member, one `assignment.created` audit row with `learner_ids: []`.
- Assign with learner ids: 201, scope `learners`, `learners` lists them, `targeted_count` equals their number, `assignment_learners` rows exist.
- The same request twice: the second answers 200 `unchanged` with `already_had_it` filled, one assignment row, no second audit row.
- Same content, different due date: 409 `already_assigned`, `details.assignment` is the existing one, nothing changed, no audit row.
- Subset assignment, then the same call with one new learner id: 200 `learners_added`, `added` has only the new one, audit `assignment.learners_added` with only that id.
- Subset assignment, then the same call with no learner ids: scope becomes `group`, `result: learners_added`, `added` lists the members not yet targeted, the audit row carries `scope.from` and `scope.to`. The same again when the subset already covered every current member: `added` is empty, the scope still changes, and the audit row exists.
- A learner id that is not a member, a due date in the past, or `next friday` as a date: 422 with the contract's field name and the fixed message, nothing written. An unknown `content_item_id`: 404 with the message `Content item not found.`
- Two parallel inserts of the same assignment (simulate with a pre-inserted row inside the transaction window, or a DB-level test using two connections): one row exists and the second caller gets the already-assigned outcome.
- `PATCH` with a new date: 200 `changed: true`, `previous_due_at`, `assignment.due_at` updated, one `assignment.due_changed` audit row with `from` and `to`; the same date again: `changed: false`, no audit row; a past date: 422; another educator's assignment and a removed assignment: 404.
- `DELETE`: 204, `removed_at` set, progress rows for it still exist, one `assignment.removed` audit row; `DELETE` again: 204 and still one row; the removed assignment no longer appears in `GET /groups/{group}/assignments`; assigning the same content again afterwards creates a new row with a new id.
- `assignment.learners` lists only targeted learners who are still group members: after a targeted learner leaves the group, `learners` and `targeted_count` both drop by one.
- `GET /groups/{group}/audit`: newest first, actor as `{ id, name }`, `changes` exactly as the contract's table for every action produced in these tests, paged, 404 for another educator's group.
- `GET /groups/{group}/assignments` runs a fixed number of queries at 5 and at 100 assignments.
- No response in this ticket contains an `email` key.

## Out of scope

Progress writes (ticket 08), MCP tools (13), the status derivation (09).

## PR checklist

- [ ] Title `07: Assignments and the audit log`
- [ ] The five AssignContent rules each have a test that names the rule
- [ ] Every action writes its audit row inside its transaction (test proves a failed write leaves no audit row)
- [ ] All checks green
- [ ] Documents changed, if any, listed with the reason
