# 06: Groups, membership, learners and content

**Estimate:** 2 days | **TDD item:** 6 | **ADRs:** ADR-006 | **Depends on:** 03 | **Phase:** 1, lane A

## Goal

The educator endpoints for groups, membership, the learner directory and the content library, exactly as in api-contract.md section 3, with cursor paging, the 404 rule on every route, and audit rows for the group actions.

## Read first

- TDD: Application structure, API Endpoints (Common rules, Educator endpoints: the first seven rows), Database Design notes (Removing a learner from a group, Audit log actions), Query counts (list_my_groups).
- api-contract.md: Conventions (all), Shared objects, section 3 up to `GET /api/v1/groups/{group}/assignments` (not included).
- ADR-006.

## Allowed paths

`app/Actions/CreateGroup.php`, `app/Actions/AddLearners.php`, `app/Actions/RemoveLearner.php`, `app/Actions/Concerns/**`, `app/Actions/Results/**`, `app/Support/Audit*.php`, `app/Queries/MyGroups.php`, `app/Http/Controllers/Api/{GroupController,GroupLearnerController,LearnerController,ContentController}.php`, `app/Http/Requests/**`, `app/Http/Resources/**`, `app/Http/Pagination/**`, `routes/api.php`, `tests/Feature/Api/{Groups,Learners,Content}/**`, `tests/Unit/Queries/MyGroupsTest.php`.

## Steps

1. Cursor paging once, reused everywhere: a `ListRequest` (`limit` integer 1 to 200 default 50, `cursor` string) and a `CursorPage` resource collection that renders `{ "data": [...], "meta": { "next_cursor": ... } }` from Laravel's `cursorPaginate`. A malformed cursor answers 422 `validation_failed` on `cursor`.
2. Route model binding for `{group}` resolves through `Scope::group($educator, $id)` (ticket 03), so a foreign or missing group is 404 before the controller runs. Every route in this ticket uses it; a learner on any of them gets 403 from `role:educator` first.
3. `MyGroups` query: the groups the educator teaches with `learner_count` and `assignment_count` (active only) in one query (joins with grouped counts, no sub-selects per row), ordered by name then id. Used by `GET /api/v1/groups` now and by the `list_my_groups` tool in ticket 12.
4. Actions, each in one transaction with its audit row written by a shared `AuditWriter` (`app/Support/AuditWriter.php`): `CreateGroup(User $educator, string $name, AuditChannel $channel)` creates the group in the educator's institution and the `group_educators` row, audit `group.created`; `AddLearners(User $educator, Group $group, array $userIds, AuditChannel $channel)` validates every id is a learner of the same institution (else a `ValidationException` with the contract's message on `user_ids.N`, nothing written), inserts the missing `group_learners` rows, writes one `group.learner_added` row per added learner, returns `added` and `already_members`; `RemoveLearner(User $educator, Group $group, int $userId, AuditChannel $channel)` deletes the pivot row if present and writes `group.learner_removed` only then.
5. Controllers for `GET /groups`, `POST /groups`, `GET /groups/{group}`, `POST /groups/{group}/learners`, `DELETE /groups/{group}/learners/{user}`, `GET /learners`, `GET /content`. Resources: `GroupResource`, `GroupDetailResource`, `LearnerResource` (id, name), `LearnerWithEmailResource` (only for `GET /learners`), `ContentResource`. Date-times through one helper that renders ISO 8601 with the institution's offset (`app/Support/InstitutionTime.php`), reused by every later ticket.
6. `GET /learners` and `GET /content` search as the contract says (case-insensitive, anywhere in the string), with the stated orders.

## Acceptance tests

- `GET /groups` returns only the caller's groups, ordered by name then id, with correct `learner_count` and `assignment_count` (removed assignments not counted), paged: with `limit=1` the first page has a `next_cursor` and the second page has the second group and `next_cursor: null`. `limit=201` and `limit=0` answer 422; a garbage cursor answers 422.
- `MyGroups` is one query whatever the number of groups, asserted with a query counter at 3 and at 30 groups (`Passport::actingAs` adds no auth query).
- `POST /groups` with a valid name: 201, the body matches the contract, the caller is in `group_educators`, one audit row `group.created` with `changes.name`. An empty or 256-character name: 422.
- `GET /groups/{group}`: educators and learners ordered by name; another educator's group and a non-existent id both answer 404 with identical bodies; a learner's token answers 403.
- `POST /groups/{group}/learners`: new ids under `added`, existing members under `already_members`; one audit row per added learner; the same call again answers 200 with everyone under `already_members` and no new audit row; an id of a learner from another institution, an educator's id, and a non-existent id each answer 422 on `user_ids.N` with the contract's message and no row written (the whole request is rejected, including valid ids in the same body); a duplicate id in the array answers 422.
- `DELETE /groups/{group}/learners/{user}`: 204, the pivot row is gone, the learner's progress rows for the group's assignments still exist, one audit row; a second call answers 204 with no second audit row.
- `GET /learners` returns only learners of the caller's institution with `email`; `?search=` matches part of a name and part of an email, case-insensitive; paged.
- `GET /content` returns the library ordered by topic, title, id; `?topic=cardiology` matches `Cardiology`; `?search=ecg` matches `ECG Basics`; paged.
- A test over every response in this ticket asserts no `email` key except in `GET /learners` and `GET /me`.
- Every date-time in these responses carries the institution's offset (a group in Europe/Berlin renders `+02:00` or `+01:00` according to the date).

## Out of scope

Assignments and the audit endpoint (ticket 07), MCP, GraphQL.

## PR checklist

- [ ] Title `06: Groups, membership, learners and content`
- [ ] JSON compared field by field with api-contract.md
- [ ] No business logic in controllers; actions carry the audit writes
- [ ] All checks green
- [ ] Documents changed, if any, listed with the reason
