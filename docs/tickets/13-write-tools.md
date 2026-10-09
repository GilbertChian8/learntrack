# 13: Write tools

**Estimate:** 1.5 days | **TDD item:** 13 | **ADRs:** ADR-004, ADR-005 | **Depends on:** 07, 11 | **Phase:** 2

## Goal

`assign_content` and `change_due_date` on the same action classes as REST, with content title resolution, the exact messages from mcp-tools.md, the write annotations, and `channel = mcp` in every audit row.

## Read first

- mcp-tools.md: section 2 and section 4 in detail, section 5.
- TDD: MCP tools (the table rows for the two tools and the Rules), Educator endpoints (the AssignContent rules), Concurrency (the bullet about Claude retrying assign_content), Failure modes (the bullets about an ambiguous title and a past due date).
- ADR-004, ADR-005.

## Allowed paths

`app/Mcp/Tools/{AssignContent,ChangeDueDate}.php`, `app/Mcp/Tools/Concerns/ParsesDueDates.php`, `app/Mcp/Servers/EducatorServer.php` (the tools list), `app/Mcp/Schemas/**` (additions only), `app/Queries/FindContent.php`, `tests/Feature/Mcp/Tools/{AssignContent,ChangeDueDate}Test.php`, `tests/Unit/Queries/FindContentTest.php`.

## Steps

1. `FindContent::byId(int $id): ?ContentItem` and `FindContent::byTitle(string $title): Collection` (case-insensitive, anywhere in the title, over the whole library, ordered by title then id, one query).
2. Both tools extend `App\Mcp\Tools\BaseTool` from ticket 11 and share a small `ParsesDueDates` trait (`app/Mcp/Tools/Concerns/ParsesDueDates.php`) that calls `DueDate::parse` and renders `DueDateException` as mcp-tools.md's two date messages. `assign_content`: resolve the group with `Scope::group` (`Group not found.`); resolve the content (`content_item_id` wins; `Content item not found.`, `No content item matches "...".`, several matches means `result: candidates` with `changed: false` and nothing written; neither argument means `Give a content_item_id or a content_title.`); parse `due_date` through the trait; call the `AssignContent` action with `AuditChannel::Mcp`; map `AssignResult` to the output fields; map `AlreadyAssignedException` to `result: already_assigned` with the existing assignment and the contract's message; map a learner validation failure to `Learner 77 is not a member of ....` (the first offending id).
3. `change_due_date`: resolve the assignment with `Scope::assignment` (removed or foreign means `Assignment not found.`), parse the date, call `ChangeDueDate` with `AuditChannel::Mcp`, map the result and the two messages.
4. Messages built exactly as the tables in mcp-tools.md section 4, including the "names are listed when 10 or fewer were added" rule and the `targeted_count` sentence.
5. Annotations on both tools: `IsReadOnly(false)`, `IsDestructive(false)`, `IsIdempotent(true)`, in whatever form the installed laravel/mcp release expresses them, so that `tools/list` shows `readOnlyHint: false`, `destructiveHint: false`, `idempotentHint: true`.
6. Add both tools to `EducatorServer`.

## Acceptance tests

Through the laravel/mcp test helpers, acting as a seeded educator with a fixed clock:

- `tools/list` shows the two write tools with the three hints above and an output schema (and the five read tools once ticket 12 has merged).
- `assign_content` by id with no learner ids: `changed: true`, `result: created`, the assignment object as in the contract, `added` lists every member, the message exact, one audit row with `channel: mcp` and action `assignment.created`.
- The same call again: `changed: false`, `result: unchanged`, the whole-group variant of the message exact, still one assignment and one audit row.
- With learner ids on a fresh content item: scope `learners`; then the same call with one more id: `result: learners_added`, `added` has only the new learner, audit `assignment.learners_added` with `channel: mcp`.
- A different due date for already assigned content: `changed: false`, `result: already_assigned`, `assignment` is the existing one, the message names both dates and the assignment id, no audit row, `isError` false.
- `content_title` matching one item: the same result as by id; matching several: `result: candidates`, the candidates listed with `id`, `title`, `topic`, `type`, the message exact, nothing written; matching none: the exact error.
- A past `due_date`: the exact error naming today's date in the institution's time zone; `next friday`: the exact format error; a learner id not in the group: the exact error; another educator's group: `Group not found.`; neither content argument: the exact error. In every error case no assignment and no audit row is written.
- `change_due_date` to a new date: `changed: true`, `previous_due_at`, `due_at`, the assignment with the new date, the message with the affected learner count, one audit row `assignment.due_changed` with `channel: mcp`; the same date: `changed: false`, the exact message, no audit row; a removed assignment and a foreign assignment: `Assignment not found.`; a past date: the exact error.
- Privacy over both tools: no `email` key in the structured content, no `@` in the text.
- The REST endpoint and the tool produce the same assignment row and the same audit `changes` for the same input (one test that runs both on two identical groups and compares the rows except for `channel` and ids).

## Out of scope

Any other write (deleting, removing learners, changing progress) stays out of the MCP server by ADR-004. Rate limits (14).

## PR checklist

- [ ] Title `13: Write tools`
- [ ] Both tools call the action classes; no rule is re-implemented in the tool
- [ ] Messages copied from mcp-tools.md, not paraphrased
- [ ] All checks green
- [ ] Documents changed, if any, listed with the reason
