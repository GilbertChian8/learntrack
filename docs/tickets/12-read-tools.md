# 12: Read tools

**Estimate:** 3 days | **TDD item:** 12 | **ADRs:** ADR-006, ADR-008 | **Depends on:** 06, 09, 10, 11 | **Phase:** 2

## Goal

The five read tools of mcp-tools.md, each with its input schema, output schema, structured content and text summary, built on `GroupProgress`, `MyGroups` and the two new query classes `LearnerSummary` and `HardestAssignments`. Every answer carries its reasons, nothing leaks across educators, and no email appears anywhere.

## Read first

- mcp-tools.md: everything, section 3 in detail.
- api-contract.md: section 6 (Reasons), the rounding rules in section 5.
- TDD: MCP tools, Query counts, item 12, Testing strategy (MCP tool tests, Query-count tests).
- ADR-006, ADR-008.

## Allowed paths

`app/Mcp/Tools/{ListMyGroups,GetGroupProgress,FindLearnersBehind,GetLearnerSummary,FindHardestAssignments}.php`, `app/Mcp/Servers/EducatorServer.php` (the tools list), `app/Mcp/Schemas/**` (additions only; the shared pieces exist since ticket 11), `app/Queries/{LearnerSummary,HardestAssignments}.php`, `app/Queries/Results/**` (new result objects only), `tests/Feature/Mcp/Tools/**`, `tests/Unit/Queries/{LearnerSummary,HardestAssignments}Test.php`.

## Steps

1. Every tool extends `App\Mcp\Tools\BaseTool` from ticket 11 (structured content plus text, the not-found mapping). Input validation goes through the published schema.
2. Output schemas (`outputSchema`) for every tool, matching the Output tables in mcp-tools.md, built from the shared pieces in `app/Mcp/Schemas` (ticket 11).
3. `list_my_groups` on `MyGroups` (ticket 06): the institution as its name.
4. `get_group_progress` and `find_learners_behind` on `GroupProgress`. The first renders learners without the per-assignment statuses (those are GraphQL only) plus `assignments`; the second filters to `behind` learners and adds `rule` and `threshold` from `BehindRule`.
5. `LearnerSummary::for(User $educator, int $learnerId)`: query 1, the educator's groups that contain the learner (`GroupPolicy::taughtBy` joined with `group_learners` for the learner, with the institution time zone; none means `NotFoundException` with subject `Learner`); query 2, `PairRows::forLearner($learnerId, $groupIds)` for those groups; then the per group view (behind, reasons, average, counts, assignments) through `LearnerAggregation` from ticket 09, per group. `LearnerSummary::candidates(User $educator, string $name)`: learners of the educator's groups whose name contains the string, case-insensitive, with the group names, ordered by name then id, one query.
6. `get_learner_summary`: `learner_id` wins over `name`; one candidate means the summary; several mean `candidates` and `learner: null`; none means `Learner not found.`; neither argument means the `Give a learner_id or a name.` error.
7. `HardestAssignments::for(User $educator, int $groupId, int $limit)` on `GroupProgress`: drop assignments with `targeted_count` 0, sort by `completion_rate` ascending, then `average_score` ascending with nulls last, then `due_at`, then id, take `limit`, and add the `reason` string in the contract's format.
8. Text summaries exactly as mcp-tools.md gives them, including the empty cases.
9. Add the five tools to `EducatorServer`. Annotations: `IsReadOnly` and `IsIdempotent` on each.

## Acceptance tests

Through the laravel/mcp test helpers, acting as a seeded educator with a fixed clock:

- `tools/list` returns the five names (plus the two write tools once ticket 13 merges), each with `readOnlyHint: true`, `idempotentHint: true` and an `outputSchema`.
- `list_my_groups`: only the caller's groups, ordered by name, counts correct, text matches the contract; an educator with no groups gets the empty text.
- `get_group_progress`: the structured content matches the contract's fields; `learners` has every member and no `statuses` key; `behind_count` equals the number of `behind: true` entries; the text's completion percentage is total completed over total targeted.
- `find_learners_behind`: only learners with reasons; `rule` equals `BehindRule::ruleText()`; `threshold` 50; the group with nobody behind gives an empty list and the "Nobody" text.
- `get_learner_summary` by id: groups are only the ones the caller teaches (a learner shared with another educator's group shows that group only when the caller teaches it too); by a unique name: the same answer; by an ambiguous name: `candidates` with group names and `learner: null`, text as the contract; by a name that only matches learners outside the caller's groups: `Learner not found.`; no arguments: the exact error text.
- `find_hardest_assignments`: order verified against hand-computed rates and averages, nulls last, `limit` respected, assignments targeting nobody absent, `reason` strings exact, `limit` 0 or 21 rejected by the schema.
- Cross-interface: for the same seeded group, `get_group_progress` and `POST /graphql` report the same `behind` flags, the same reason texts, the same `counts` and the same assignment `completion_rate` and `average_score` values (one test walking both answers).
- One test per tool with another educator's group id, learner id or assignment id: the exact not-found message and `isError: true`.
- Privacy, one test over all five tools on the seeded data: the structured content has no key named `email` at any depth and the text contains no `@`.
- Query counts: `get_group_progress`, `find_learners_behind` and `find_hardest_assignments` run exactly 2 queries after authentication at 60 by 100 and at 120 by 200; `get_learner_summary` exactly 2 by id and exactly 3 by name (the candidates query first); `list_my_groups` exactly 1.
- Every example JSON in mcp-tools.md section 3 validates against the corresponding tool's published output schema (a test that loads the examples from the markdown file, or copies them into fixtures that a test compares against the document).

## Out of scope

The write tools (13), rate limits on `/mcp` (14).

## PR checklist

- [ ] Title `12: Read tools`
- [ ] Names, descriptions, annotations and texts copied from mcp-tools.md, not paraphrased
- [ ] `LearnerSummary` aggregates through `LearnerAggregation`; no second implementation
- [ ] All checks green
- [ ] Documents changed, if any, listed with the reason
