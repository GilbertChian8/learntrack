# MCP Tools Contract

**Status:** Approved 09/10/2026

**TDD:** [tdd.md](tdd.md) (MCP tools). **REST and GraphQL:** [api-contract.md](api-contract.md). The reason objects and the rounding rules are defined there (sections 5 and 6) and reused here unchanged.

This file is the exact contract of the EducatorServer: names, descriptions, annotations, input schemas, output fields, text summaries and error messages. The code must match this file; if the code has to differ, this file changes in the same pull request.

## 1. Server

| Item | Value |
|---|---|
| Endpoint | `POST /mcp`, Streamable HTTP, stateless (every call carries the bearer token) |
| Auth | OAuth 2.1 through Passport (ADR-003). `auth:api` plus the educator role. A learner's token answers 403 |
| Server name | `LearnTrack Educator` |
| Version | `1.0.0` |
| Class | `App\Mcp\Servers\EducatorServer` |
| Tools | `list_my_groups`, `get_group_progress`, `find_learners_behind`, `get_learner_summary`, `find_hardest_assignments`, `assign_content`, `change_due_date` |
| Resources, prompts | none in v1 |

Server instructions (the `instructions` text the client receives on initialize):

> LearnTrack shows how the learners in the groups you teach are doing. Start with list_my_groups to get group ids. Tools answer only about your own groups; anything else is "not found". Every answer carries the reasons behind it, so quote them instead of guessing. assign_content and change_due_date change data: tell the educator exactly what will change and ask before calling them. Dates are given in the institution's time zone.

## 2. Conventions

- **Names** are snake_case, exactly as listed. Set them with the tool's `$name` property (or the Name attribute), never from the class name.
- **Result shape.** Every successful call returns structured content (the object described under Output) and one text content item (the summary described under Text). The output schema published in `tools/list` describes the structured content. Both are built from the same PHP array, so they never disagree.
- **Errors** are tool errors (`isError: true`) with one text item holding the exact message listed under Errors. They are not JSON-RPC protocol errors. Input that fails the schema is rejected by laravel/mcp validation with its own message; the schema's `description` fields are written so that message is useful.
- **Not found.** A group, learner, assignment or content item that does not exist, or that the educator may not see, gets the same message (ADR-006): `Group not found.`, `Learner not found.`, `Assignment not found.`, `Content item not found.` (the shared exception carries the subject; the period is this interface's).
- **Dates.** Every date-time in an answer is ISO 8601 with the institution's offset, for example `2026-10-16T23:59:59+02:00`. A `due_date` input accepts `YYYY-MM-DD` (23:59:59 on that day in the institution's time zone) or a full ISO 8601 date-time with an offset. It must be in the future.
- **Learners** appear as `{ "id": 101, "name": "Sara Lindqvist" }` and nothing else. No tool has an email field, and no text summary contains an email address. A test asserts both over every tool.
- **Numbers.** `average_score` is rounded to 1 decimal and `null` when there is nothing to average. `completion_rate` is a fraction from 0 to 1 rounded to 2 decimals. Text summaries write rates as whole percentages (`29%`).
- **Annotations.** Read tools carry `readOnlyHint: true` and `idempotentHint: true`. Write tools carry `readOnlyHint: false`, `destructiveHint: false` and `idempotentHint: true`. `destructiveHint` must be set to false explicitly, because the protocol's default is true.
- **Ordering** is stated per tool and is deterministic, so a repeated call gives the same answer in the same order.
- **Actor.** No tool takes a user to act as. The educator is the owner of the bearer token.
- **Writes** run through the same action classes as REST (AssignContent, ChangeDueDate) with `channel = mcp` in the audit row, inside one transaction.

Shared objects used below:

`learner`:

```json
{ "id": 101, "name": "Sara Lindqvist" }
```

`reason`: see api-contract.md section 6 (`overdue` and `low_average`).

`counts`:

```json
{ "not_started": 3, "in_progress": 2, "completed": 4, "overdue": 1 }
```

`assignment_stats`:

```json
{
  "id": 41,
  "title": "Heart Failure Question Set",
  "topic": "Cardiology",
  "type": "question_set",
  "due_at": "2026-10-16T23:59:59+02:00",
  "scope": "learners",
  "targeted_count": 2,
  "completed_count": 1,
  "completion_rate": 0.5,
  "average_score": 72.0
}
```

`assignment` (the same object REST returns, see api-contract.md, Shared objects):

```json
{
  "id": 41,
  "group_id": 12,
  "content": { "id": 8, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "estimated_minutes": 30 },
  "due_at": "2026-10-16T23:59:59+02:00",
  "scope": "learners",
  "targeted_count": 2,
  "learners": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ],
  "created_at": "2026-10-09T14:03:21+02:00"
}
```

## 3. Read tools

### list_my_groups

| | |
|---|---|
| Title | My groups |
| Class | `App\Mcp\Tools\ListMyGroups` |
| Query class | `MyGroups` (1 query) |
| Annotations | read-only, idempotent |

Description:

> Lists the groups you teach, with the number of learners and active assignments in each. Call this first to get the group ids the other tools need.

Input schema:

```json
{ "type": "object", "properties": {}, "additionalProperties": false }
```

Output:

| Field | Type | Meaning |
|---|---|---|
| `groups` | array | Ordered by name, then id |
| `groups[].id` | integer | |
| `groups[].name` | string | |
| `groups[].institution` | string | The institution's name |
| `groups[].learner_count` | integer | Current members |
| `groups[].assignment_count` | integer | Active assignments |

Example:

```json
{
  "groups": [
    { "id": 12, "name": "Cardiology Group A", "institution": "Northside Medical School", "learner_count": 42, "assignment_count": 9 },
    { "id": 15, "name": "Cardiology Group B", "institution": "Northside Medical School", "learner_count": 38, "assignment_count": 7 }
  ]
}
```

Text: `You teach 2 groups: Cardiology Group A (42 learners, 9 assignments); Cardiology Group B (38 learners, 7 assignments).` With no groups: `You teach no groups yet.`

Errors: none.

### get_group_progress

| | |
|---|---|
| Title | Group progress |
| Class | `App\Mcp\Tools\GetGroupProgress` |
| Query class | `GroupProgress` (2 queries) |
| Annotations | read-only, idempotent |

Description:

> Shows one group's progress: per learner (status counts, average question set score, whether they are behind and why) and per assignment (how many completed it, completion rate, average score). Use find_learners_behind when you only need the learners who are behind.

Input schema:

```json
{
  "type": "object",
  "properties": {
    "group_id": { "type": "integer", "description": "The group id from list_my_groups." }
  },
  "required": ["group_id"],
  "additionalProperties": false
}
```

Output:

| Field | Type | Meaning |
|---|---|---|
| `group` | object | `id`, `name`, `institution` (name) |
| `generated_at` | string | When the data was read; every status is "as of" this moment |
| `learner_count` | integer | Current members |
| `behind_count` | integer | Learners with `behind: true` |
| `learners` | array | Every current member, ordered by name, then id |
| `learners[].id`, `learners[].name` | | |
| `learners[].behind` | boolean | From BehindRule |
| `learners[].reasons` | array of reason | Empty when not behind |
| `learners[].average_score` | number or null | Over completed question sets in this group |
| `learners[].counts` | counts | Sums to the number of assignments that target the learner |
| `assignments` | array of assignment_stats | Active assignments, ordered by due date, then id |

Example (shortened):

```json
{
  "group": { "id": 12, "name": "Cardiology Group A", "institution": "Northside Medical School" },
  "generated_at": "2026-10-09T14:20:00+02:00",
  "learner_count": 42,
  "behind_count": 5,
  "learners": [
    {
      "id": 102, "name": "Tom Becker", "behind": true,
      "reasons": [
        { "kind": "overdue", "assignment_id": 40, "title": "ECG Basics", "due_at": "2026-10-02T23:59:59+02:00", "days_overdue": 7, "text": "Overdue: ECG Basics, due 2026-10-02, 7 days overdue" }
      ],
      "average_score": null,
      "counts": { "not_started": 1, "in_progress": 0, "completed": 0, "overdue": 1 }
    }
  ],
  "assignments": [
    { "id": 40, "title": "ECG Basics", "topic": "Cardiology", "type": "article", "due_at": "2026-10-02T23:59:59+02:00", "scope": "group", "targeted_count": 42, "completed_count": 39, "completion_rate": 0.93, "average_score": null }
  ]
}
```

Text: `Cardiology Group A: 42 learners, 9 active assignments, 5 learners behind. Completion across assignments: 78%.` The last number is total completed over total targeted, as a whole percentage.

Errors: `Group not found.`

### find_learners_behind

| | |
|---|---|
| Title | Learners behind |
| Class | `App\Mcp\Tools\FindLearnersBehind` |
| Query class | `GroupProgress` (2 queries) |
| Annotations | read-only, idempotent |

Description:

> Lists the learners in a group who are behind, with the reason for each: which assignments are overdue and by how many days, or which question set scores pull their average below 50. An empty list means nobody is behind.

Input schema: the same as get_group_progress (`group_id`, required).

Output:

| Field | Type | Meaning |
|---|---|---|
| `group` | object | `id`, `name` |
| `rule` | string | The rule in words (api-contract.md section 6) |
| `threshold` | integer | 50, from BehindRule |
| `learner_count` | integer | Current members |
| `behind_count` | integer | Length of `learners` |
| `learners` | array | Only learners with `behind: true`, ordered by name, then id |
| `learners[].id`, `learners[].name` | | |
| `learners[].reasons` | array of reason | At least one |

Example:

```json
{
  "group": { "id": 12, "name": "Cardiology Group A" },
  "rule": "A learner is behind when they have at least one overdue assignment in the group, or their average score over completed question sets in the group is below 50.",
  "threshold": 50,
  "learner_count": 42,
  "behind_count": 2,
  "learners": [
    {
      "id": 101, "name": "Sara Lindqvist",
      "reasons": [
        { "kind": "low_average", "average_score": 42.5, "threshold": 50, "scores": [ { "assignment_id": 41, "title": "Heart Failure Question Set", "score": 40 }, { "assignment_id": 43, "title": "Arrhythmia Question Set", "score": 45 } ], "text": "Average question set score 42.5 is below 50 (scores: 40, 45)" }
      ]
    },
    {
      "id": 102, "name": "Tom Becker",
      "reasons": [
        { "kind": "overdue", "assignment_id": 40, "title": "ECG Basics", "due_at": "2026-10-02T23:59:59+02:00", "days_overdue": 7, "text": "Overdue: ECG Basics, due 2026-10-02, 7 days overdue" }
      ]
    }
  ]
}
```

Text: `2 of 42 learners in Cardiology Group A are behind. Sara Lindqvist: Average question set score 42.5 is below 50 (scores: 40, 45). Tom Becker: Overdue: ECG Basics, due 2026-10-02, 7 days overdue.` One sentence per learner, reasons joined with `; `. With nobody behind: `Nobody in Cardiology Group A is behind (42 learners).`

Errors: `Group not found.`

### get_learner_summary

| | |
|---|---|
| Title | Learner summary |
| Class | `App\Mcp\Tools\GetLearnerSummary` |
| Query class | `LearnerSummary` (2 queries) |
| Annotations | read-only, idempotent |

Description:

> Shows one learner's progress in every group you teach that they belong to: whether they are behind in each group and why, their status counts, their average score and each assignment's status. Give the learner_id when you have it; otherwise give the name and the tool returns the matching learners so you can ask which one is meant.

Input schema:

```json
{
  "type": "object",
  "properties": {
    "learner_id": { "type": "integer", "description": "The learner's id from an earlier answer. Preferred." },
    "name": { "type": "string", "minLength": 2, "description": "Part of the learner's name, when the id is unknown. Case-insensitive." }
  },
  "additionalProperties": false
}
```

At least one of the two must be given; with neither, the tool answers the error below. When both are given, `learner_id` wins.

Name matching: case-insensitive, matches anywhere in the name, only among learners who are members of the groups the educator teaches. Learners outside those groups are never matched, so the tool cannot be used to look people up.

Output:

| Field | Type | Meaning |
|---|---|---|
| `learner` | learner or null | `null` when candidates are returned |
| `groups` | array | The educator's groups that contain the learner, ordered by name, then id. Empty when candidates are returned |
| `groups[].id`, `groups[].name` | | |
| `groups[].behind` | boolean | |
| `groups[].reasons` | array of reason | |
| `groups[].average_score` | number or null | |
| `groups[].counts` | counts | |
| `groups[].assignments` | array | The assignments that target the learner, ordered by due date, then id |
| `groups[].assignments[]` | object | `assignment_id`, `title`, `type`, `due_at`, `status`, `score`, `completed_at` |
| `candidates` | array of object | Empty unless the name matched several learners. Each: `id`, `name`, `groups` (array of group names) |

Example (one match):

```json
{
  "learner": { "id": 101, "name": "Sara Lindqvist" },
  "groups": [
    {
      "id": 12, "name": "Cardiology Group A", "behind": true,
      "reasons": [ { "kind": "low_average", "average_score": 42.5, "threshold": 50, "scores": [ { "assignment_id": 41, "title": "Heart Failure Question Set", "score": 40 }, { "assignment_id": 43, "title": "Arrhythmia Question Set", "score": 45 } ], "text": "Average question set score 42.5 is below 50 (scores: 40, 45)" } ],
      "average_score": 42.5,
      "counts": { "not_started": 2, "in_progress": 1, "completed": 6, "overdue": 0 },
      "assignments": [
        { "assignment_id": 40, "title": "ECG Basics", "type": "article", "due_at": "2026-10-02T23:59:59+02:00", "status": "completed", "score": null, "completed_at": "2026-10-01T18:02:00+02:00" },
        { "assignment_id": 41, "title": "Heart Failure Question Set", "type": "question_set", "due_at": "2026-10-16T23:59:59+02:00", "status": "completed", "score": 40, "completed_at": "2026-10-08T20:15:00+02:00" }
      ]
    }
  ],
  "candidates": []
}
```

Example (several matches):

```json
{
  "learner": null,
  "groups": [],
  "candidates": [
    { "id": 101, "name": "Sara Lindqvist", "groups": ["Cardiology Group A"] },
    { "id": 188, "name": "Sara Okafor", "groups": ["Cardiology Group B"] }
  ]
}
```

Text, one match: `Sara Lindqvist is in 1 of your groups. Cardiology Group A: behind (Average question set score 42.5 is below 50 (scores: 40, 45)); 6 completed, 1 in progress, 2 not started, 0 overdue.` One sentence per group; `on track` replaces `behind (...)` when not behind.

Text, several matches: `2 learners match "Sara": Sara Lindqvist (Cardiology Group A), Sara Okafor (Cardiology Group B). Ask which one is meant, then call again with the learner_id.`

Errors: `Learner not found.` (no match by name, or an id that is not in any of the educator's groups). `Give a learner_id or a name.` (neither given).

### find_hardest_assignments

| | |
|---|---|
| Title | Hardest assignments |
| Class | `App\Mcp\Tools\FindHardestAssignments` |
| Query class | `HardestAssignments`, built on `GroupProgress` (2 queries) |
| Annotations | read-only, idempotent |

Description:

> Ranks a group's assignments by difficulty: lowest completion rate first, then lowest average score. Each result says how many learners completed it and the average score, so you can explain why it ranks there.

Input schema:

```json
{
  "type": "object",
  "properties": {
    "group_id": { "type": "integer", "description": "The group id from list_my_groups." },
    "limit": { "type": "integer", "minimum": 1, "maximum": 20, "default": 5, "description": "How many assignments to return. Default 5." }
  },
  "required": ["group_id"],
  "additionalProperties": false
}
```

Output:

| Field | Type | Meaning |
|---|---|---|
| `group` | object | `id`, `name` |
| `assignments` | array of assignment_stats plus `reason` | At most `limit`. Ordered by `completion_rate` ascending, then `average_score` ascending with `null` last, then due date, then id. Assignments that target nobody are left out |
| `assignments[].reason` | string | `12 of 42 learners completed it (29%)`, plus `, average score 48` for a question set with at least one score |

Example:

```json
{
  "group": { "id": 12, "name": "Cardiology Group A" },
  "assignments": [
    { "id": 43, "title": "Arrhythmia Question Set", "topic": "Cardiology", "type": "question_set", "due_at": "2026-10-05T23:59:59+02:00", "scope": "group", "targeted_count": 42, "completed_count": 12, "completion_rate": 0.29, "average_score": 48.0, "reason": "12 of 42 learners completed it (29%), average score 48" },
    { "id": 40, "title": "ECG Basics", "topic": "Cardiology", "type": "article", "due_at": "2026-10-02T23:59:59+02:00", "scope": "group", "targeted_count": 42, "completed_count": 39, "completion_rate": 0.93, "average_score": null, "reason": "39 of 42 learners completed it (93%)" }
  ]
}
```

Text: `Hardest assignments in Cardiology Group A: 1. Arrhythmia Question Set: 12 of 42 learners completed it (29%), average score 48. 2. ECG Basics: 39 of 42 learners completed it (93%).` With no assignments: `Cardiology Group A has no active assignments.`

Errors: `Group not found.`

## 4. Write tools

Both write tools return `changed` and a `message` that says exactly what happened. The text summary is the `message`. The client shows the educator an approval dialog before calling a write tool; the annotations tell it the call is not destructive and is safe to repeat.

### assign_content

| | |
|---|---|
| Title | Assign content |
| Class | `App\Mcp\Tools\AssignContent` |
| Action class | `AssignContent` (shared with `POST /api/v1/groups/{group}/assignments`) |
| Annotations | not read-only, not destructive, idempotent |

Description:

> Assigns a content item (an article or a question set) to one of your groups with a due date, or only to some learners in it (for example the learners who are behind). Give content_item_id when you know it; otherwise give content_title and the tool finds the item, or returns the candidates when several match. Nothing is ever deleted. Repeating the same call changes nothing and says so. If the content is already assigned with another due date, nothing changes; use change_due_date instead.

Input schema:

```json
{
  "type": "object",
  "properties": {
    "group_id": { "type": "integer", "description": "The group id from list_my_groups." },
    "content_item_id": { "type": "integer", "description": "The content item id. Preferred when known." },
    "content_title": { "type": "string", "minLength": 2, "description": "Part of the content title, when the id is unknown. Case-insensitive." },
    "due_date": { "type": "string", "description": "YYYY-MM-DD (end of that day in the institution's time zone) or an ISO 8601 date-time with offset. Must be in the future." },
    "learner_ids": { "type": "array", "items": { "type": "integer" }, "minItems": 1, "maxItems": 60, "uniqueItems": true, "description": "Only these learners get the assignment. Leave out to assign to the whole group." }
  },
  "required": ["group_id", "due_date"],
  "additionalProperties": false
}
```

One of `content_item_id` and `content_title` must be given. When both are given, `content_item_id` wins.

Content title matching: case-insensitive, anywhere in the title, over the whole library. Exactly one match: use it. Several: return the candidates and change nothing. None: the error below.

Output:

| Field | Type | Meaning |
|---|---|---|
| `changed` | boolean | Whether anything was written |
| `result` | string | `created`, `learners_added`, `unchanged`, `already_assigned`, `candidates` |
| `assignment` | assignment or null | The created or existing assignment; `null` for `candidates` |
| `added` | array of learner | Learners targeted for the first time by this call |
| `already_had_it` | array of learner | Learners named in `learner_ids` (or every member, when no ids were given) who were already targeted |
| `candidates` | array of object | Empty unless `result` is `candidates`. Each: `id`, `title`, `topic`, `type` |
| `message` | string | What happened, in one or two sentences. Also the text summary |

The result rules are the AssignContent rules in the TDD, with REST's 409 case returned here as `result: already_assigned` and `changed: false`, because for an AI client that is an answer, not a failure.

| Case | `changed` | `result` | `message` |
|---|---|---|---|
| Created | true | `created` | `Assigned Heart Failure Question Set to Cardiology Group A, due 2026-10-16T23:59:59+02:00, for 2 learners: Sara Lindqvist, Tom Becker.` For the whole group: `..., for all 42 learners.` |
| Learners added to a subset assignment | true | `learners_added` | `Heart Failure Question Set was already assigned in Cardiology Group A, due 2026-10-16T23:59:59+02:00. Added 1 learner: Priya Nair. 2 learners already had it: Sara Lindqvist, Tom Becker.` |
| Subset widened to the whole group (no `learner_ids` given) | true | `learners_added` | `Heart Failure Question Set was already assigned in Cardiology Group A to 2 learners, due 2026-10-16T23:59:59+02:00. Now assigned to the whole group, including learners who join later. Added 40 learners.` (names are listed after the count when 10 or fewer were added; when nobody was added the last sentence is `Every current learner already had it.`) |
| Nothing to do | false | `unchanged` | `Heart Failure Question Set is already assigned to Cardiology Group A, due 2026-10-16T23:59:59+02:00, and every learner you named already has it. Nothing changed.` With no `learner_ids`: `..., and the whole group already has it. Nothing changed.` |
| Different due date | false | `already_assigned` | `Heart Failure Question Set is already assigned to Cardiology Group A, due 2026-10-16T23:59:59+02:00, not 2026-10-23. Nothing changed. To move it, call change_due_date with assignment_id 41.` |
| Several titles match | false | `candidates` | `3 content items match "heart": Heart Failure Question Set (question_set, Cardiology), Heart Sounds (article, Cardiology), Congenital Heart Disease (article, Pediatrics). Ask which one is meant, then call again with content_item_id.` |

Example (created):

```json
{
  "changed": true,
  "result": "created",
  "assignment": {
    "id": 41,
    "group_id": 12,
    "content": { "id": 8, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "estimated_minutes": 30 },
    "due_at": "2026-10-16T23:59:59+02:00",
    "scope": "learners",
    "targeted_count": 2,
    "learners": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ],
    "created_at": "2026-10-09T14:03:21+02:00"
  },
  "added": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ],
  "already_had_it": [],
  "candidates": [],
  "message": "Assigned Heart Failure Question Set to Cardiology Group A, due 2026-10-16T23:59:59+02:00, for 2 learners: Sara Lindqvist, Tom Becker."
}
```

Errors (nothing is written):

- `Group not found.`
- `Content item not found.` (an unknown `content_item_id`)
- `No content item matches "xyz".`
- `Give a content_item_id or a content_title.`
- `The due date must be in the future. Today is 2026-10-09 in Europe/Berlin.`
- `The due date "next friday" is not a date. Use YYYY-MM-DD or an ISO 8601 date-time.`
- `Learner 77 is not a member of Cardiology Group A.` (the first offending id)

### change_due_date

| | |
|---|---|
| Title | Change due date |
| Class | `App\Mcp\Tools\ChangeDueDate` |
| Action class | `ChangeDueDate` (shared with `PATCH /api/v1/assignments/{assignment}`) |
| Annotations | not read-only, not destructive, idempotent |

Description:

> Moves the due date of an assignment in one of your groups. Get the assignment_id from get_group_progress, find_hardest_assignments or assign_content. The same date answers changed: false. Nothing else about the assignment changes and no progress is touched.

Input schema:

```json
{
  "type": "object",
  "properties": {
    "assignment_id": { "type": "integer", "description": "The assignment id from an earlier answer." },
    "due_date": { "type": "string", "description": "YYYY-MM-DD (end of that day in the institution's time zone) or an ISO 8601 date-time with offset. Must be in the future." }
  },
  "required": ["assignment_id", "due_date"],
  "additionalProperties": false
}
```

Output:

| Field | Type | Meaning |
|---|---|---|
| `changed` | boolean | |
| `assignment` | assignment | With the new `due_at` |
| `previous_due_at` | string | |
| `due_at` | string | The due date after the call |
| `message` | string | Also the text summary |

| Case | `changed` | `message` |
|---|---|---|
| Moved | true | `Moved Heart Failure Question Set in Cardiology Group A from 2026-10-16T23:59:59+02:00 to 2026-10-23T23:59:59+02:00. 2 learners are affected.` (`targeted_count` learners) |
| Same date | false | `Heart Failure Question Set in Cardiology Group A is already due 2026-10-16T23:59:59+02:00. Nothing changed.` |

Example:

```json
{
  "changed": true,
  "assignment": { "id": 41, "group_id": 12, "content": { "id": 8, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "estimated_minutes": 30 }, "due_at": "2026-10-23T23:59:59+02:00", "scope": "learners", "targeted_count": 2, "learners": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ], "created_at": "2026-10-09T14:03:21+02:00" },
  "previous_due_at": "2026-10-16T23:59:59+02:00",
  "due_at": "2026-10-23T23:59:59+02:00",
  "message": "Moved Heart Failure Question Set in Cardiology Group A from 2026-10-16T23:59:59+02:00 to 2026-10-23T23:59:59+02:00. 2 learners are affected."
}
```

Errors (nothing is written):

- `Assignment not found.` (unknown, removed, or in a group the educator does not teach)
- `The due date must be in the future. Today is 2026-10-09 in Europe/Berlin.`
- `The due date "next friday" is not a date. Use YYYY-MM-DD or an ISO 8601 date-time.`

## 5. Tests that pin this contract

- `tools/list` returns exactly the seven names above, each with the annotations in section 2 and an output schema.
- One test per tool with another educator's group, learner or assignment: the not-found message, nothing else.
- One privacy test over every tool: no key named `email` anywhere in the structured content, and no `@` in the text summary.
- `get_learner_summary` with an ambiguous name returns candidates and `learner: null`.
- `assign_content` with an ambiguous title returns candidates and writes nothing; called twice with the same input, the second answer has `changed: false` and one audit row exists.
- `change_due_date` with the same date answers `changed: false` and writes no audit row.
- The structured content of every example in this file validates against the tool's published output schema.
