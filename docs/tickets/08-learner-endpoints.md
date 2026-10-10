# 08: Learner endpoints

**Estimate:** 2 days | **TDD item:** 8 | **ADRs:** ADR-005, ADR-008 | **Depends on:** 03, 06, 09 | **Phase:** 1, lane B

## Goal

A learner sees their own assignments with the derived status, and records progress through one idempotent, forward-only action with the score rules per content type.

## Read first

- TDD: Learner endpoints (the table and the RecordProgress rules), Status and the behind rule (the status table), Query counts (`GET /api/v1/me/assignments`), Concurrency (the second and fifth bullets).
- api-contract.md: section 4, the `already_completed` error.
- ADR-005, ADR-008.

## Allowed paths

`app/Actions/RecordProgress.php`, `app/Actions/Results/RecordProgressResult.php`, `app/Exceptions/AlreadyCompletedException.php`, `app/Queries/LearnerAssignments.php`, `app/Http/Controllers/Api/Me/**`, `app/Http/Requests/RecordProgressRequest.php`, `app/Http/Resources/{LearnerAssignmentResource,ProgressResource}.php`, `app/Policies/AssignmentPolicy.php` (only if a method is missing), `routes/api.php`, `tests/Feature/Api/Me/**`, `tests/Unit/Actions/RecordProgressTest.php`.

## Steps

1. `LearnerAssignments` query: the pair rows for one learner across all their groups, from `PairRows::forLearner($learnerId)` (ticket 09; with no group ids it joins the learner's `group_learners` rows itself), active assignments only, ordered by `due_at` then assignment id, with the group and the content item in the same query. One query, paged with the cursor helper from ticket 06.
2. `GET /api/v1/me/assignments` (`role:learner`), rendering the contract's rows with the derived `status`.
3. `RecordProgress(User $learner, Assignment $assignment, ProgressStatus $status, ?int $score): RecordProgressResult` in one transaction. The controller resolves the assignment with `Scope::learnerAssignment($learner, $id)` (ticket 03, `AssignmentPolicy::targeting`; `recordProgress` is its yes/no check), so a removed, foreign or non-targeting assignment is 404 before the action runs; the action re-checks `removed_at` inside its transaction (TDD, Concurrency). Then the score rules (`ValidationException` on `score`); lock the existing progress row with `lockForUpdate`, or insert when there is none, catching the duplicate key error and re-reading; apply the transition table; `AlreadyCompletedException` (409, with the row's `status`, `score`, `completed_at` as details) for a backward move or a changed score; `changed` false for a no-op. Progress has no audit row (the audit log records educator actions).
4. `PUT /api/v1/me/assignments/{assignment}/progress`, `RecordProgressRequest` with the contract's validation, the response with the derived status (the same derivation as the pair query: an in-progress row past its due date answers `overdue`).

## Acceptance tests

- `GET /me/assignments` lists every active assignment that targets the caller across two groups, excludes removed assignments, excludes subset assignments the caller is not in, and shows `not_started` (no row), `in_progress`, `completed` and `overdue` correctly under a fixed clock; paged; one query regardless of the number of assignments (asserted at 5 and at 100).
- An educator on `GET /me/assignments` answers 403.
- `PUT` `in_progress` with no row: 200, `changed: true`, `started_at` set, `completed_at` null, status `in_progress`; the same call again: `changed: false`, still one row.
- `PUT` `completed` with a score on a question set: 200, `changed: true`, `completed_at` set; the same score again: `changed: false`; a different score: 409 `already_completed` with the contract's details; `in_progress` after completion: 409.
- `PUT` `completed` with no row: one row created with both `started_at` and `completed_at`.
- A question set completed without a score: 422 on `score`; an article completed with a score: 422; `in_progress` with a score: 422; score 101 or -1: 422.
- Completing after the due date: 200, status `completed`, `completed_at` later than `due_at`.
- `in_progress` on an overdue assignment: 200 with `status: overdue`.
- A removed assignment, an assignment in a group the caller is not in, and a subset assignment that does not target the caller: 404 with identical bodies.
- Two concurrent first writes for the same pair (two connections, or a pre-inserted row inside the window) leave exactly one row.

## Out of scope

Educator views of progress (09, 10, 12), any write by educators.

## PR checklist

- [ ] Title `08: Learner endpoints`
- [ ] Every row of the contract's transition table has a test
- [ ] Status in responses comes from the shared derivation, not a second implementation
- [ ] All checks green
- [ ] Documents changed, if any, listed with the reason
