# API Contract: REST and GraphQL

**Status:** Approved 09/10/2026

**TDD:** [tdd.md](tdd.md). This file is the exact wire contract for every REST endpoint and for the GraphQL query. Where the TDD describes behaviour in prose, this file says what the bytes look like. The code must match this file; if the code has to differ, this file changes in the same pull request.

The MCP tools have their own contract in [mcp-tools.md](mcp-tools.md). The reason strings and the rounding rules below are shared with the MCP tools.

## 1. Conventions

### Base URL and headers

- REST base path: `/api/v1`. GraphQL: `POST /graphql`.
- Requests carry `Accept: application/json` and, when they have a body, `Content-Type: application/json`.
- Authentication: `Authorization: Bearer <token>` on every request except `POST /api/v1/auth/login`.
- Responses are always JSON, including errors.

### Identifiers

- REST ids are JSON numbers (`12`), never strings. GraphQL `ID` values are strings (`"12"`), as the GraphQL specification requires.
- Path parameters are integer ids. A non-numeric id answers 404.

### Dates and times

- Every date-time in a response is ISO 8601 with the UTC offset of the institution the data belongs to, for example `2026-10-16T23:59:59+02:00`. The database stores UTC; the offset is applied when the response is built.
- A due date in a request (`due_at`) accepts two formats:
  - `YYYY-MM-DD`, read as 23:59:59 on that day in the institution's time zone.
  - A full ISO 8601 date-time with an offset, for example `2026-10-16T18:00:00+02:00`.
- A due date must be in the future at the moment of the request, else 422.
- Date-time values stored inside audit `changes` are UTC with a `Z` suffix and are returned as stored.

### Enums

| Name | Values |
|---|---|
| role | `educator`, `learner` |
| content type | `article`, `question_set` |
| assignment scope | `group`, `learners` |
| status | `not_started`, `in_progress`, `completed`, `overdue` |
| audit channel | `rest`, `mcp` |
| audit action | `group.created`, `group.learner_added`, `group.learner_removed`, `assignment.created`, `assignment.learners_added`, `assignment.due_changed`, `assignment.removed` |
| assign result | `created`, `learners_added`, `unchanged` |

### Envelopes

One object:

```json
{ "data": { } }
```

A list, always paged:

```json
{ "data": [ ], "meta": { "next_cursor": "eyJpZCI6NDJ9" } }
```

- `?limit` is the page size: default 50, maximum 200. A value above 200 or below 1 answers 422.
- `?cursor` is the opaque string from the previous page's `meta.next_cursor`. A malformed cursor answers 422.
- `next_cursor` is `null` on the last page.
- Every list has a stated order (see each endpoint); the cursor follows that order.

Status codes without a body: `204 No Content`.

The order of fields in the examples is not part of the contract.

### Errors

One shape everywhere, including GraphQL requests rejected before the query runs:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": { "due_at": ["The due date must be in the future."] }
  }
}
```

- `code` is a stable machine string from the table below. `message` is for humans and may change. `details` is present only where the table says so.

| HTTP | code | When | details |
|---|---|---|---|
| 401 | `unauthenticated` | Missing, expired or invalid bearer token | none |
| 401 | `invalid_credentials` | Login with a wrong email or password (the same answer for an unknown email) | none |
| 403 | `forbidden` | The caller's role may not use this route (a learner on an educator route, an educator on a learner route) | none |
| 404 | `not_found` | The resource does not exist, or it exists but is outside the caller's scope (ADR-006). The two cases are indistinguishable | none |
| 405 | `method_not_allowed` | The path exists but not for this method; carries an `Allow` header | none |
| 409 | `already_assigned` | The content is already assigned to the group with a different due date | `assignment`: the existing assignment object |
| 409 | `already_completed` | A progress update that would move a completed assignment backwards or change its score | `status`, `score`, `completed_at` of the existing row |
| 422 | `validation_failed` | Invalid input | object: field name to a list of messages. Array items use dot notation, for example `learner_ids.1` |
| 429 | `too_many_requests` | A rate limit was hit. The response carries a `Retry-After` header in seconds | `retry_after`: the same number |
| 500 | `server_error` | An unexpected failure. The message never contains internals | none |
| 503 | `service_unavailable` | Maintenance mode | none |

The 401 response from `/api/v1/*`, `/graphql` and `/mcp` carries `WWW-Authenticate: Bearer`; on `/mcp` it also carries `resource_metadata` pointing at `/.well-known/oauth-protected-resource`, which laravel/mcp adds in ticket 11.

An unknown path answers 404 `not_found`; a known path with the wrong method answers 405 `method_not_allowed`.

Any other HTTP error keeps its status with a snake_case code of its reason phrase; a failure that is not an HTTP error is 500 `server_error`.

### Roles

- Educator routes answer 403 to learners. Learner routes answer 403 to educators. `GET /api/v1/me`, `POST /api/v1/auth/logout` serve both.
- Scope is checked after the role. An educator asking for a group they do not teach gets 404, never 403 (ADR-006).

### Shared objects

`learner` (used wherever a learner is listed; never carries an email except in `GET /api/v1/learners`):

```json
{ "id": 101, "name": "Sara Lindqvist" }
```

`content`:

```json
{ "id": 8, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "estimated_minutes": 30 }
```

`group` (summary):

```json
{
  "id": 12,
  "name": "Cardiology Group A",
  "institution": { "id": 1, "name": "Northside Medical School" },
  "learner_count": 42,
  "assignment_count": 9,
  "created_at": "2026-09-01T09:00:00+02:00"
}
```

`assignment_count` counts active (not removed) assignments.

`assignment`:

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

- `targeted_count`: the number of current group members the assignment targets. For scope `group` that is the group's learner count.
- `learners`: the targeted learners who are current members of the group when scope is `learners` (so its length equals `targeted_count`); `null` when scope is `group`.

`audit_entry`:

```json
{
  "id": 980,
  "actor": { "id": 3, "name": "Anna Keller" },
  "channel": "mcp",
  "action": "assignment.due_changed",
  "subject": { "type": "assignment", "id": 41 },
  "changes": { "due_at": { "from": "2026-10-16T21:59:59Z", "to": "2026-10-23T21:59:59Z" } },
  "created_at": "2026-10-09T14:10:02+02:00"
}
```

`changes` per action (ids and values only, never names or emails):

| action | subject | changes |
|---|---|---|
| `group.created` | group | `{ "name": "Cardiology Group A" }` |
| `group.learner_added` | group | `{ "user_id": 101 }` (one row per learner) |
| `group.learner_removed` | group | `{ "user_id": 101 }` |
| `assignment.created` | assignment | `{ "content_item_id": 8, "due_at": "2026-10-16T21:59:59Z", "scope": "learners", "learner_ids": [101, 102] }` (`learner_ids` is `[]` for scope `group`) |
| `assignment.learners_added` | assignment | `{ "learner_ids": [103] }`, plus `"scope": { "from": "learners", "to": "group" }` when the scope widened |
| `assignment.due_changed` | assignment | `{ "due_at": { "from": "...", "to": "..." } }` |
| `assignment.removed` | assignment | `{ "removed_at": "2026-10-09T12:10:02Z" }` |

## 2. Authentication

### POST /api/v1/auth/login

No token. Rate limited on failures (see the TDD, Rate limits).

Request:

```json
{ "email": "anna.keller@example.edu", "password": "secret" }
```

200:

```json
{
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
    "token_type": "Bearer",
    "expires_at": "2026-10-09T22:03:21+02:00",
    "user": { "id": 3, "name": "Anna Keller", "role": "educator" }
  }
}
```

- The token is a Passport personal access token, valid 8 hours.
- 401 `invalid_credentials` on a wrong email or password. 422 when a field is missing. 429 after too many failures.

### POST /api/v1/auth/logout

Revokes the token used to call it. 204. Calling it again with the same token answers 401.

### GET /api/v1/me

Both roles. 200:

```json
{
  "data": {
    "id": 3,
    "name": "Anna Keller",
    "email": "anna.keller@example.edu",
    "role": "educator",
    "institution": { "id": 1, "name": "Northside Medical School", "timezone": "Europe/Berlin" }
  }
}
```

## 3. Educator endpoints

All routes in this section require the educator role (403 otherwise). Any `{group}` or `{assignment}` the caller does not teach answers 404.

### GET /api/v1/groups

The groups the caller teaches. Order: name, then id. Paged.

200:

```json
{
  "data": [
    {
      "id": 12,
      "name": "Cardiology Group A",
      "institution": { "id": 1, "name": "Northside Medical School" },
      "learner_count": 42,
      "assignment_count": 9,
      "created_at": "2026-09-01T09:00:00+02:00"
    }
  ],
  "meta": { "next_cursor": null }
}
```

### POST /api/v1/groups

Creates a group in the caller's institution. The caller becomes its educator. Audit `group.created`.

Request:

```json
{ "name": "Cardiology Group B" }
```

Validation: `name` required, string, 1 to 255 characters.

201: `{ "data": <group> }` with `learner_count` 0 and `assignment_count` 0.

### GET /api/v1/groups/{group}

200:

```json
{
  "data": {
    "id": 12,
    "name": "Cardiology Group A",
    "institution": { "id": 1, "name": "Northside Medical School" },
    "learner_count": 42,
    "assignment_count": 9,
    "educators": [ { "id": 3, "name": "Anna Keller" } ],
    "learners": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ],
    "created_at": "2026-09-01T09:00:00+02:00"
  }
}
```

`educators` and `learners` are ordered by name, then id. They are not paged (a group has at most 60 learners).

### POST /api/v1/groups/{group}/learners

Adds learners to the group. Audit `group.learner_added` once per learner actually added.

Request:

```json
{ "user_ids": [101, 102, 103] }
```

Validation: `user_ids` required, array of 1 to 200 distinct integers. Every id must be a learner of the caller's institution; otherwise 422 and nothing is written:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": { "user_ids.2": ["User 103 is not a learner of your institution."] }
  }
}
```

The message is the same for an id that does not exist, so nothing leaks.

200:

```json
{
  "data": {
    "added": [ { "id": 103, "name": "Priya Nair" } ],
    "already_members": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ]
  }
}
```

Repeating the call answers 200 with everyone under `already_members`.

### DELETE /api/v1/groups/{group}/learners/{user}

Removes the learner from the group. Progress rows stay. 204, also when the learner was not a member (nothing to do). Audit `group.learner_removed` only when a row was removed.

### GET /api/v1/learners

Learners of the caller's institution, to pick from when adding to a group. This is the only endpoint that returns other people's emails (`GET /api/v1/me` returns the caller's own). Order: name, then id. Paged.

Query: `?search=` matches name or email, case-insensitive, anywhere in the string.

200:

```json
{
  "data": [
    { "id": 101, "name": "Sara Lindqvist", "email": "sara.lindqvist@example.edu" }
  ],
  "meta": { "next_cursor": null }
}
```

### GET /api/v1/content

The content library. Order: topic, title, then id. Paged.

Query: `?topic=` exact match, case-insensitive. `?search=` matches the title, case-insensitive, anywhere in the string.

200:

```json
{
  "data": [
    { "id": 7, "title": "ECG Basics", "topic": "Cardiology", "type": "article", "estimated_minutes": 20 },
    { "id": 8, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "estimated_minutes": 30 }
  ],
  "meta": { "next_cursor": null }
}
```

### GET /api/v1/groups/{group}/assignments

Active assignments of the group. Order: `due_at`, then id. Paged.

200:

```json
{ "data": [ <assignment>, <assignment> ], "meta": { "next_cursor": null } }
```

### POST /api/v1/groups/{group}/assignments

The AssignContent action. REST and MCP share it (ADR-005).

Request:

```json
{ "content_item_id": 8, "due_at": "2026-10-16", "learner_ids": [101, 102] }
```

Validation:

- `content_item_id` required integer. An id that does not exist answers 404 `not_found` with the message "Content item not found."
- `due_at` required, one of the two date formats (else 422, message `The due date must be a date (YYYY-MM-DD) or an ISO 8601 date-time.`), in the future (else 422, message `The due date must be in the future.`).
- `learner_ids` optional, array of 1 to 60 distinct integers. Every id must be a current member of the group, else 422 (`learner_ids.N`: "Learner 77 is not a member of this group.") and nothing is written.

Outcomes:

| Case | HTTP | `result` | Audit |
|---|---|---|---|
| No active assignment for this content in this group: created. Scope `group` without `learner_ids`, `learners` with them | 201 | `created` | `assignment.created` |
| Active assignment, same due date, scope `group` | 200 | `unchanged` | none |
| Active assignment, same due date, scope `learners`, `learner_ids` given: the ids not yet targeted are added | 200 | `learners_added`, or `unchanged` when every id already had it | `assignment.learners_added` when something was added |
| Active assignment, same due date, scope `learners`, no `learner_ids`: the scope widens to `group`, so members who join later get it too. This is a write even when every current member already had it | 200 | `learners_added` (`added` may be empty) | `assignment.learners_added` with the scope change |
| Active assignment with a different due date | 409 | see below | none |

"Same due date" compares the stored UTC instant, so `2026-10-16` and `2026-10-16T23:59:59+02:00` are the same date for an institution in Europe/Berlin.

201 or 200:

```json
{
  "data": {
    "result": "created",
    "assignment": <assignment>,
    "added": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ],
    "already_had_it": []
  }
}
```

- `added`: learners targeted for the first time by this call. For a created scope `group` assignment that is every current member.
- `already_had_it`: learners named in `learner_ids` (or every member, when no ids were given) who were already targeted.

409:

```json
{
  "error": {
    "code": "already_assigned",
    "message": "Heart Failure Question Set is already assigned to Cardiology Group A, due 2026-10-16T23:59:59+02:00. Change the due date with PATCH /api/v1/assignments/41.",
    "details": { "assignment": <assignment> }
  }
}
```

Progress rows are never touched by this endpoint.

### PATCH /api/v1/assignments/{assignment}

The ChangeDueDate action. A removed assignment answers 404.

Request:

```json
{ "due_at": "2026-10-23" }
```

Validation: `due_at` required, one of the two formats, in the future, else 422 with the same two messages as `POST /api/v1/groups/{group}/assignments`.

200:

```json
{
  "data": {
    "changed": true,
    "due_at": "2026-10-23T23:59:59+02:00",
    "previous_due_at": "2026-10-16T23:59:59+02:00",
    "assignment": <assignment>
  }
}
```

The same date answers `"changed": false` with `previous_due_at` equal to `due_at` and no audit row. Audit `assignment.due_changed` when it changed.

### DELETE /api/v1/assignments/{assignment}

Soft delete: sets `removed_at`, progress rows stay. 204. Repeating it answers 204 again with no second audit row. Audit `assignment.removed` once.

After a removal the same content can be assigned to the group again; that creates a new assignment with a new id.

### GET /api/v1/groups/{group}/audit

Who changed what in this group. Order: newest first (`created_at` descending, then id descending). Paged.

200:

```json
{ "data": [ <audit_entry> ], "meta": { "next_cursor": "eyJpZCI6OTAwfQ" } }
```

## 4. Learner endpoints

All routes in this section require the learner role (403 otherwise).

### GET /api/v1/me/assignments

Every active assignment that targets the caller, across their groups. Order: `due_at`, then assignment id. Paged.

200:

```json
{
  "data": [
    {
      "assignment_id": 41,
      "group": { "id": 12, "name": "Cardiology Group A" },
      "content": { "id": 8, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "estimated_minutes": 30 },
      "due_at": "2026-10-16T23:59:59+02:00",
      "status": "in_progress",
      "score": null,
      "started_at": "2026-10-10T08:12:00+02:00",
      "completed_at": null
    }
  ],
  "meta": { "next_cursor": null }
}
```

`status` is the derived status (TDD, Status and the behind rule). `started_at` and `completed_at` are `null` until they happen. Removed assignments are not listed.

### PUT /api/v1/me/assignments/{assignment}/progress

The RecordProgress action. PUT is a state: a repeat sets the same state and changes nothing.

404 when the assignment is removed, is not in one of the caller's groups, or does not target the caller.

Request:

```json
{ "status": "completed", "score": 72 }
```

Validation (422 otherwise):

- `status` required, `in_progress` or `completed`.
- `score`: only accepted with `status: completed`. Required for a question set, must be absent for an article. Integer 0 to 100.

Transitions:

| Current row | Request | Outcome |
|---|---|---|
| none | `in_progress` | Row created, `started_at` now. `changed: true` |
| in_progress | `in_progress` | `changed: false` |
| completed | `in_progress` | 409 `already_completed` |
| none | `completed` | Row created with `started_at` and `completed_at` now. `changed: true` |
| in_progress | `completed` | Completed, `completed_at` now. `changed: true` |
| completed | `completed`, same score | `changed: false` |
| completed | `completed`, different score | 409 `already_completed` |

Completing after the due date is allowed; the status becomes `completed` and `completed_at` after `due_at` shows the lateness.

200:

```json
{
  "data": {
    "assignment_id": 41,
    "status": "completed",
    "score": 72,
    "started_at": "2026-10-10T08:12:00+02:00",
    "completed_at": "2026-10-12T19:40:13+02:00",
    "due_at": "2026-10-16T23:59:59+02:00",
    "changed": true
  }
}
```

`status` is the derived status, the same value `GET /api/v1/me/assignments` returns. Marking an overdue assignment as `in_progress` therefore answers `"status": "overdue"`.

409:

```json
{
  "error": {
    "code": "already_completed",
    "message": "This assignment is already completed with a score of 72.",
    "details": { "status": "completed", "score": 72, "completed_at": "2026-10-12T19:40:13+02:00" }
  }
}
```

## 5. GraphQL

- `POST /graphql` with `{ "query": "...", "variables": { } }`. GET is not served.
- Bearer token and the educator role, checked by middleware before the query runs. Failures use the REST error shape with 401 or 403.
- Errors inside the query use the GraphQL shape with HTTP 200.
- The schema is in the TDD (GraphQL). `DateTime` serializes exactly like a REST date-time: ISO 8601 with the institution's offset. `ID` values are strings.

Enum values map to the REST strings: `NOT_STARTED` is `not_started`, `IN_PROGRESS` is `in_progress`, `COMPLETED` is `completed`, `OVERDUE` is `overdue`; `ARTICLE` and `QUESTION_SET`; `GROUP` and `LEARNERS`.

### groupProgress

Query:

```graphql
query ($groupId: ID!) {
  groupProgress(groupId: $groupId) {
    group { id name institution }
    generatedAt
    assignments { id title topic type dueAt scope targetedCount completedCount completionRate averageScore }
    learners {
      learner { id name }
      behind
      reasons
      averageScore
      counts { notStarted inProgress completed overdue }
      statuses { assignmentId status score completedAt }
    }
  }
}
```

Variables: `{ "groupId": "12" }`.

200:

```json
{
  "data": {
    "groupProgress": {
      "group": { "id": "12", "name": "Cardiology Group A", "institution": "Northside Medical School" },
      "generatedAt": "2026-10-09T14:20:00+02:00",
      "assignments": [
        { "id": "40", "title": "ECG Basics", "topic": "Cardiology", "type": "ARTICLE", "dueAt": "2026-10-02T23:59:59+02:00", "scope": "GROUP", "targetedCount": 42, "completedCount": 39, "completionRate": 0.93, "averageScore": null },
        { "id": "41", "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "QUESTION_SET", "dueAt": "2026-10-16T23:59:59+02:00", "scope": "LEARNERS", "targetedCount": 2, "completedCount": 1, "completionRate": 0.5, "averageScore": 72.0 }
      ],
      "learners": [
        {
          "learner": { "id": "102", "name": "Tom Becker" },
          "behind": true,
          "reasons": ["Overdue: ECG Basics, due 2026-10-02, 7 days overdue"],
          "averageScore": null,
          "counts": { "notStarted": 1, "inProgress": 0, "completed": 0, "overdue": 1 },
          "statuses": [
            { "assignmentId": "40", "status": "OVERDUE", "score": null, "completedAt": null },
            { "assignmentId": "41", "status": "NOT_STARTED", "score": null, "completedAt": null }
          ]
        }
      ]
    }
  }
}
```

Rules:

- `assignments`: the group's active assignments ordered by `dueAt`, then id. An assignment that targets nobody appears with `targetedCount` 0.
- `learners`: every current member ordered by name, then id. `statuses` lists only the assignments that target that learner, in the same order as `assignments`.
- `counts` sums to the number of assignments that target the learner.
- `averageScore` on a learner: the average over their completed question sets in this group, rounded to 1 decimal; `null` when they have completed none.
- `averageScore` on an assignment: the average over completed rows, rounded to 1 decimal; `null` for articles and when nobody has completed it.
- `completionRate`: `completedCount / targetedCount`, rounded to 2 decimals; `0` when `targetedCount` is 0.
- `behind` and `reasons` come from BehindRule, see below.
- `generatedAt`: the moment the pair query ran. Every status is "as of" this time.

Not found (unknown id, or a group the caller does not teach; ADR-006):

```json
{
  "data": { "groupProgress": null },
  "errors": [
    { "message": "Group not found", "path": ["groupProgress"], "extensions": { "code": "not_found" } }
  ]
}
```

## 6. Reasons (shared with the MCP tools)

BehindRule produces zero or more reasons per learner and group. Every interface returns the same ones. GraphQL returns the `text`; the MCP tools return the structured form, which carries the same `text`.

Overdue (one per overdue assignment, in due date order):

```json
{
  "kind": "overdue",
  "assignment_id": 40,
  "title": "ECG Basics",
  "due_at": "2026-10-02T23:59:59+02:00",
  "days_overdue": 7,
  "text": "Overdue: ECG Basics, due 2026-10-02, 7 days overdue"
}
```

- `days_overdue`: calendar days between the due date and today in the institution's time zone. The date inside `text` is `YYYY-MM-DD` in that time zone. The last part of `text` is `{n} days overdue`, `1 day overdue` when `days_overdue` is 1, and `earlier today` when it is 0 (a due time that passed today).

Low average (at most one):

```json
{
  "kind": "low_average",
  "average_score": 42.5,
  "threshold": 50,
  "scores": [ { "assignment_id": 41, "title": "Heart Failure Question Set", "score": 40 }, { "assignment_id": 43, "title": "Arrhythmia Question Set", "score": 45 } ],
  "text": "Average question set score 42.5 is below 50 (scores: 40, 45)"
}
```

- `scores`: every completed question set of the learner in the group, in due date order, so the AI can say which ones pull the average down.
- `average_score` is rounded to 1 decimal; a whole number is written without a decimal in `text` (`"score 40 is below 50"`).

The rule in words, returned by `find_learners_behind` as `rule`: "A learner is behind when they have at least one overdue assignment in the group, or their average score over completed question sets in the group is below 50."
