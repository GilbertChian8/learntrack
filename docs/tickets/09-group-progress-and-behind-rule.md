# 09: GroupProgress and BehindRule

**Estimate:** 3 days | **TDD item:** 9 | **ADRs:** ADR-008 | **Depends on:** 03 | **Phase:** 1, lane B

## Goal

The progress engine: the pair query, the per learner and per assignment aggregates, the reasons, and BehindRule as the one place that holds the threshold and the predicate. GraphQL (10), the learner endpoints (08) and every MCP read tool (12) are formatting over this ticket.

## Read first

- TDD: Status and the behind rule (all of it, including the SQL and the query-count table), Database Design notes (Status is not stored, Behind, Subset assignments, Time zones), Testing strategy (Unit tests, Query-count tests).
- api-contract.md: section 5 (the rules under the example) and section 6 (Reasons).
- ADR-008.

## Allowed paths

`app/Queries/GroupProgress.php`, `app/Queries/BehindRule.php`, `app/Queries/PairRows.php`, `app/Queries/LearnerAggregation.php`, `app/Queries/Results/**` (`GroupProgressResult`, `LearnerProgress`, `AssignmentStats`, `StatusCounts`, `Reason`, `OverdueReason`, `LowAverageReason`), `app/Support/InstitutionTime.php` (if ticket 06 has not merged yet, create it here and reconcile on merge), `tests/Unit/Queries/**`, `tests/Feature/Queries/**`.

## Steps

1. `PairRows`: the pair SQL from the TDD as a query builder with bindings, with two entry points: `forGroup(int $groupId)` and `forLearner(int $learnerId, ?array $groupIds = null)` (the learner variant described under the SQL in the TDD; with `null` it joins the learner's `group_learners` rows itself, so the learner endpoints stay at one query; with ids it restricts to those groups). It returns plain row objects with exactly the TDD's columns (`group_id`, `group_name`, `user_id`, `learner_name`, `assignment_id`, `scope`, `due_at`, `content_item_id`, `title`, `topic`, `type`, `estimated_minutes`, `progress_status`, `score`, `started_at`, `completed_at`, `status`). The `CASE` runs in SQL against `UTC_TIMESTAMP()`; in tests the clock is fixed by passing `now` as a bound parameter instead of `UTC_TIMESTAMP()` (one optional argument, default the real clock), so the derivation is testable without sleeping.
2. `BehindRule`: `public const int SCORE_THRESHOLD = 50;` `isBehind(int $overdueCount, ?float $averageScore): bool`; `reasons(array $overduePairs, array $completedQuestionSets, DateTimeZone $tz, CarbonImmutable $now): list<Reason>`; `ruleText(): string` (the sentence in api-contract.md section 6). Nothing else in the code base mentions 50.
3. Reason objects with `toArray()` (the structured form from the contract) and `text()` (the exact string formats from the contract, including `days_overdue` as calendar days in the institution's time zone and the date as `YYYY-MM-DD` in that zone).
4. `GroupProgress::for(User $educator, int $groupId): GroupProgressResult` runs exactly two queries. Query 1 starts from `GroupPolicy::taughtBy($educator)` (ticket 03) and joins the institution and the current members (`group_learners`, `users`): zero rows means `NotFoundException` with subject `Group`; otherwise the rows give the group header, the institution's time zone and the full member list, including members that no assignment targets. Query 2 is `PairRows::forGroup`. Then it builds in PHP: `learners` (every current member, ordered by name then id, each through `LearnerAggregation`), `assignments` (every active assignment ordered by `due_at` then id, with `targeted_count`, `completed_count`, `completion_rate` rounded to 2 decimals and 0 when nobody is targeted, `average_score` null for articles), `generated_at`, `learner_count`, `behind_count`. Rows with a null learner count for the assignment list and are skipped for learner statistics. No other query runs.
5. `LearnerAggregation::fromRows(iterable $pairRows, DateTimeZone $tz, CarbonImmutable $now): LearnerProgress` is the per learner aggregation written once: `counts`, `average_score` over completed question sets rounded to 1 decimal, `behind` and `reasons` through `BehindRule`, and the ordered `statuses`. A member with no rows gets zero counts, a null average and `behind: false`. `GroupProgress` uses it per member; ticket 12's `LearnerSummary` uses it per group.
6. Every date-time in the result is a `CarbonImmutable` in the institution's time zone, rendered by `InstitutionTime`, so formatting is the same in every interface.

## Acceptance tests

Unit tests for the derivation and the rule, with a fixed clock:

- Status: no row and not due, `not_started`; in-progress row and not due, `in_progress`; completed row, `completed` whatever the due date; no row or in-progress row and due date passed, `overdue`; a completion after the due date is `completed`.
- Behind by one overdue pair; behind by an average of 49.9; exactly 50 is not behind; no completed question set gives a null average and is not behind by score; the average counts completed question sets only (articles and in-progress rows never contribute); a subset assignment counts only for its learners; a removed assignment counts for nobody.
- `days_overdue`: due `2026-10-02` in Europe/Berlin with now `2026-10-09T08:00:00Z` is 7; the overdue text and the low-average text match the contract character for character, including `1 day overdue`, `earlier today` for 0, the whole-number rule (`score 40 is below 50`) and the decimal rule (`42.5`).
- Reason order: overdue reasons in due date order, then at most one low-average reason.

Feature tests on a seeded group with a fixed clock:

- `GroupProgress::for` returns every current member even with no progress rows and even when no assignment targets them (zero counts, not behind), every active assignment even with no targeted learners (`targeted_count` 0, `completion_rate` 0), `statuses` only for assignments that target the learner, `counts` summing to that number, `completed_count` and `completion_rate` and `average_score` per assignment correct against hand-computed values.
- A learner added to the group after a scope-group assignment appears with `not_started` for it; a learner outside a subset assignment has no status for it.
- Another educator's group and a missing id throw `NotFoundException` with subject `Group`, before any pair query runs.
- Query count: exactly 2 queries at 60 learners and 100 assignments, and exactly 2 at 120 learners and 200 assignments.
- Timing: the pair query at 60 by 100 runs under 100 ms locally (logged, not asserted, to avoid a flaky test; print the number in the PR).

## Out of scope

Any HTTP interface; `LearnerSummary`, `HardestAssignments` and `MyGroups` (12 and 06); caching of any kind.

## PR checklist

- [ ] Title `09: GroupProgress and BehindRule`
- [ ] `grep -rn "50" app/ | grep -v BehindRule` shows no score comparison
- [ ] Reason texts tested against api-contract.md section 6 literally
- [ ] All checks green
- [ ] Documents changed, if any, listed with the reason
