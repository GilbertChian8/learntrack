# Technical Design (TDD) for PAS-001: LearnTrack

**Status:** Approved 09/10/2026

**Blueprint:** [PAS-001: LearnTrack](blueprint.md)

**ADRs:** [adr/README.md](adr/README.md)

**Requirements:** [requirements.md](requirements.md)

**Exact interfaces:** [api-contract.md](api-contract.md) (every REST endpoint and the GraphQL error shape) and [mcp-tools.md](mcp-tools.md) (every MCP tool's schema). Where this document describes an interface in prose, those two files are the contract.

**Collaborators:** Gilbert Lavensky Chan

# Application structure

One Laravel application serves REST, GraphQL and MCP (ADR-002). The rule that makes "the same answer everywhere" true is a layering rule: controllers, GraphQL resolvers and MCP tools hold no business logic. They validate input, call one action or one query class, and format the result.

| Layer | Folder | What lives there |
|---|---|---|
| Models | app/Models | Institution, User, Group (table learner_groups), ContentItem, Assignment, Progress, AuditEntry |
| Policies | app/Policies | GroupPolicy (an educator teaches the group), AssignmentPolicy (through its group), ProgressPolicy (a learner owns the row). Every scope check in the system goes through these |
| Actions | app/Actions | AssignContent, ChangeDueDate, RemoveAssignment, CreateGroup, AddLearners, RemoveLearner, RecordProgress. Each one runs in one transaction and writes its audit row inside that transaction |
| Queries | app/Queries | GroupProgress (the pair query and the per learner and per assignment aggregates), BehindRule (the rule itself: thresholds and the predicate), LearnerSummary, HardestAssignments, MyGroups |
| REST | app/Http/Controllers/Api, app/Http/Requests, app/Http/Resources | Thin controllers, Form Requests and API Resources |
| GraphQL | graphql/schema.graphql, app/GraphQL | The groupProgress query and its resolver (Lighthouse) |
| MCP | app/Mcp/Servers/EducatorServer, app/Mcp/Tools | Seven tool classes (laravel/mcp) |
| Web | resources/js/pages/auth, resources/js/pages/mcp | The login, consent and error pages as Inertia pages (React, TypeScript, ADR-017); app.blade.php is the only Blade view |

BehindRule is the one place the rule lives (requirement 6). It exposes the score threshold (50) and one method, isBehind(overdueCount, averageScore). The SQL only produces the two inputs; nothing else in the code base compares a score to 50.

# Request path and routing

One host, one certificate, one ALB, one target group. Every path below is served by the same Laravel application.

| Path | Served by | Auth |
|---|---|---|
| /api/v1/* | REST controllers | Bearer token (Passport), auth:api |
| /graphql | Lighthouse | Bearer token, auth:api, educator role |
| /mcp | laravel/mcp web server (Streamable HTTP) | Bearer token from the OAuth flow, auth:api, educator role |
| /oauth/authorize, /oauth/token, /oauth/register | Passport and Mcp::oauthRoutes() | Web session for authorize, public for token and register |
| /.well-known/oauth-protected-resource, /.well-known/oauth-authorization-server | Mcp::oauthRoutes() | Public |
| / | Web controller | Guest: redirect to /login. Signed in: the notice page (ticket 11) |
| /login, /logout | Web controllers | Web session (database driver) |
| /up | Laravel health route | Public, used by the ALB health check |

Proxies: the ALB ends TLS, so the app sees plain HTTP. TrustProxies trusts the VPC range and reads X-Forwarded-Proto and X-Forwarded-For, so generated URLs (the discovery documents, redirects) are https and rate limits count the real client address. The ALB redirects port 80 to 443 (301). The app sends Strict-Transport-Security.

Region: a Terraform variable. Default eu-central-1 (Frankfurt), so learner data stays inside the EU by default. A different region is one variable change.

## Architecture diagram wires

The diagram ([architecture.png](architecture.png)) carries these arrows, numbered the same way.

1. Educator browser -> ALB = HTTPS, login and consent pages (the OAuth flow)
2. Claude -> ALB = HTTPS, OAuth discovery and token, then MCP tool calls with a bearer token
3. Apps -> ALB = HTTPS, REST and GraphQL with a bearer token
4. Route 53 -> ALB = alias record for the domain (dashed)
5. ALB -> App tasks = HTTP on port 8080, health check GET /up
6. App tasks -> RDS MySQL = MySQL over TLS: data, sessions, cache, rate limits, OAuth tokens, audit log
7. App tasks -> Secrets Manager = database password, APP_KEY, Passport keys, read at task start (dashed)
8. App tasks -> CloudWatch = logs and metrics (dashed)
9. App tasks -> ECR = image pull at task start, layers through the S3 gateway endpoint (dashed)
10. App tasks -> NAT gateways = all outbound traffic: AWS APIs today, single sign-on and email later (dashed)
11. EventBridge Scheduler -> ECS = run the housekeeping task once a day (dashed)
12. GitHub Actions -> IAM = assume the deploy role with OIDC, no stored keys (dashed)
13. GitHub Actions -> ECR = push the image tagged with the git SHA
14. GitHub Actions -> Terraform state (S3) and AWS = terraform plan and apply
15. GitHub Actions -> ECS = run the migration task, register the task definition revision, update the service

# Database Design

MySQL 8, InnoDB, utf8mb4. All timestamps are stored in UTC. Laravel migrations create these tables; the DDL below is the design they must match.

```sql
CREATE TABLE institutions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  timezone    VARCHAR(64)  NOT NULL DEFAULT 'UTC',   -- IANA name, used to read bare due dates
  created_at  TIMESTAMP NULL,
  updated_at  TIMESTAMP NULL
);

CREATE TABLE users (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id  BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(255) NOT NULL,
  email           VARCHAR(255) NOT NULL UNIQUE,
  password        VARCHAR(255) NOT NULL,              -- bcrypt hash, never the raw password
  role            ENUM('educator', 'learner') NOT NULL,
  created_at      TIMESTAMP NULL,
  updated_at      TIMESTAMP NULL,
  INDEX idx_users_institution_role (institution_id, role),
  FOREIGN KEY (institution_id) REFERENCES institutions (id)
);

CREATE TABLE learner_groups (                          -- "groups" is a reserved word in MySQL 8
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id  BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(255) NOT NULL,
  created_at      TIMESTAMP NULL,
  updated_at      TIMESTAMP NULL,
  FOREIGN KEY (institution_id) REFERENCES institutions (id)
);

CREATE TABLE group_educators (
  group_id    BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NULL,
  PRIMARY KEY (group_id, user_id),
  INDEX idx_group_educators_user (user_id),
  FOREIGN KEY (group_id) REFERENCES learner_groups (id),
  FOREIGN KEY (user_id)  REFERENCES users (id)
);

CREATE TABLE group_learners (
  group_id    BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NULL,
  PRIMARY KEY (group_id, user_id),
  INDEX idx_group_learners_user (user_id),
  FOREIGN KEY (group_id) REFERENCES learner_groups (id),
  FOREIGN KEY (user_id)  REFERENCES users (id)
);

CREATE TABLE content_items (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title              VARCHAR(255) NOT NULL,
  topic              VARCHAR(100) NOT NULL,            -- for example Cardiology
  type               ENUM('article', 'question_set') NOT NULL,
  estimated_minutes  SMALLINT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NULL,
  updated_at         TIMESTAMP NULL,
  INDEX idx_content_topic (topic),
  INDEX idx_content_title (title)
);

CREATE TABLE assignments (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id         BIGINT UNSIGNED NOT NULL,
  content_item_id  BIGINT UNSIGNED NOT NULL,
  due_at           DATETIME NOT NULL,                  -- UTC
  scope            ENUM('group', 'learners') NOT NULL DEFAULT 'group',
  created_by       BIGINT UNSIGNED NOT NULL,           -- the educator
  removed_at       DATETIME(6) NULL,                   -- soft delete; progress rows are kept
  removed_key      DATETIME(6) AS (COALESCE(removed_at, '1000-01-01 00:00:00')) STORED,
  created_at       TIMESTAMP NULL,
  updated_at       TIMESTAMP NULL,
  UNIQUE KEY uq_assignments_active (group_id, content_item_id, removed_key),
  INDEX idx_assignments_group (group_id, removed_at, due_at),
  FOREIGN KEY (group_id)        REFERENCES learner_groups (id),
  FOREIGN KEY (content_item_id) REFERENCES content_items (id),
  FOREIGN KEY (created_by)      REFERENCES users (id)
);

CREATE TABLE assignment_learners (                     -- rows exist only when scope = 'learners'
  assignment_id  BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  created_at     TIMESTAMP NULL,
  PRIMARY KEY (assignment_id, user_id),
  INDEX idx_assignment_learners_user (user_id),
  FOREIGN KEY (assignment_id) REFERENCES assignments (id),
  FOREIGN KEY (user_id)       REFERENCES users (id)
);

CREATE TABLE progress (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assignment_id  BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,             -- the learner
  status         ENUM('in_progress', 'completed') NOT NULL,
  score          TINYINT UNSIGNED NULL,                -- 0 to 100, question sets only
  started_at     DATETIME NOT NULL,
  completed_at   DATETIME NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  UNIQUE KEY uq_progress_pair (assignment_id, user_id),
  INDEX idx_progress_user (user_id),
  CONSTRAINT chk_progress_score CHECK (score IS NULL OR score <= 100),
  FOREIGN KEY (assignment_id) REFERENCES assignments (id),
  FOREIGN KEY (user_id)       REFERENCES users (id)
);

CREATE TABLE audit_log (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id      BIGINT UNSIGNED NOT NULL,              -- the educator who made the change
  channel       ENUM('rest', 'mcp') NOT NULL,
  action        VARCHAR(64) NOT NULL,                  -- see the list below
  group_id      BIGINT UNSIGNED NULL,
  subject_type  VARCHAR(64) NOT NULL,                  -- assignment, group
  subject_id    BIGINT UNSIGNED NOT NULL,
  changes       JSON NOT NULL,                         -- before and after, ids and values only
  created_at    DATETIME(6) NOT NULL,
  INDEX idx_audit_group_time (group_id, created_at),
  INDEX idx_audit_actor_time (actor_id, created_at),
  FOREIGN KEY (actor_id) REFERENCES users (id)
);
```

Framework tables, created by their own migrations: Passport's oauth_clients, oauth_auth_codes, oauth_access_tokens, oauth_refresh_tokens and oauth_device_codes; Laravel's sessions, cache and cache_locks (ADR-013).

Notes on the design:

- **Status is not stored** (ADR-008). "Not started" is the absence of a progress row. A row exists only once the learner has started or completed. The derived status for a learner and assignment pair is:

| Progress row | Due date passed | Status |
|---|---|---|
| completed | any | completed |
| none, or in_progress | yes | overdue |
| in_progress | no | in_progress |
| none | no | not_started |

- **Behind** (requirement 6): a learner is behind in a group when they have at least one overdue pair in that group, or when the average score of their completed question sets in that group is below 50. A learner with no completed question set has no average and is not behind by score. Exactly 50 is not behind. Only assignments that target the learner count (scope group, or a row in assignment_learners).
- **Subset assignments.** An assignment with scope group targets every current member of the group, including learners added later. An assignment with scope learners targets only the rows in assignment_learners. This is why a new group member picks up the group's assignments with no extra write, while a remediation assignment stays with the learners it was made for.
- **Soft delete for assignments** (requirement 4). Removing an assignment sets removed_at; its progress rows stay. The generated column removed_key makes the unique key uq_assignments_active mean "one active assignment per content item per group": every active row shares the same removed_key, and every removed row carries its own timestamp. MySQL has no partial indexes, so this is the standard way to express that rule. After a removal the same content can be assigned again as a new row.
- **Removing a learner from a group** deletes the group_learners row only. Their progress rows stay, so re-adding them later shows their history, and nothing is lost.
- **Time zones.** due_at is stored in UTC. A due date given as a bare date (2026-10-16) means 23:59:59 of that day in the institution's timezone. Answers return due_at in ISO 8601 with the institution's offset, so an AI client can say "Friday" correctly.
- **Audit log actions:** group.created, group.learner_added, group.learner_removed, assignment.created, assignment.learners_added, assignment.due_changed, assignment.removed. Every action class writes its row inside the same transaction as the change, so no change exists without its audit row. Rows hold ids and values, never names or emails; names are resolved when the log is read.

## The indexes that matter

- group_educators (user_id): "which groups do I teach" and every Policy check start here.
- group_learners (user_id): a learner's own assignments, and get_learner_summary across groups.
- assignments (group_id, removed_at, due_at): the active assignments of a group, in due date order.
- progress (assignment_id, user_id), unique: the join key of every status computation, and the duplicate lock for progress (ADR-005).
- progress (user_id): a learner's own view.
- assignment_learners (user_id): the learner side of subset assignments.
- assignments (group_id, content_item_id, removed_key), unique: the lock that makes assign_content idempotent (ADR-005).
- audit_log (group_id, created_at): the group's audit view, newest first.

# Status and the behind rule

GroupProgress is the one query class behind GraphQL groupProgress, get_group_progress, find_learners_behind and find_hardest_assignments. It runs a fixed number of queries whatever the size of the group (requirement: no N+1).

The pair query starts from the group's active assignments and left joins the learners each one targets. It returns one row per learner and assignment pair, with the derived status:

```sql
SELECT a.group_id, g.name AS group_name,
       gl.user_id, u.name AS learner_name,
       a.id AS assignment_id, a.scope, a.due_at,
       c.id AS content_item_id, c.title, c.topic, c.type, c.estimated_minutes,
       p.status AS progress_status, p.score, p.started_at, p.completed_at,
       CASE
         WHEN p.status = 'completed'        THEN 'completed'
         WHEN a.due_at < UTC_TIMESTAMP()    THEN 'overdue'
         WHEN p.status = 'in_progress'      THEN 'in_progress'
         ELSE 'not_started'
       END AS status
FROM assignments a
JOIN learner_groups g       ON g.id = a.group_id
JOIN content_items c        ON c.id = a.content_item_id
LEFT JOIN group_learners gl ON gl.group_id = a.group_id
  AND (a.scope = 'group'
       OR EXISTS (SELECT 1 FROM assignment_learners al
                  WHERE al.assignment_id = a.id AND al.user_id = gl.user_id))
LEFT JOIN users u           ON u.id = gl.user_id
LEFT JOIN progress p        ON p.assignment_id = a.id AND p.user_id = gl.user_id
WHERE a.group_id = ? AND a.removed_at IS NULL;
```

The learner variant of the same query (GET /api/v1/me/assignments and get_learner_summary) starts from the learner's group_learners rows and inner joins the active assignments that target them, with the same columns and the same CASE, in one query.

For the largest group that is 6,000 rows, read once. An assignment that targets nobody yet (an empty group, or a subset whose learners all left the group) appears as one row with a null learner, so the assignment list is always complete; such rows count for the assignment list and are skipped for learner statistics. A member that no assignment targets has no pair row at all; the member list comes from the group lookup (the first query, below), so such a learner appears with zero counts and is not behind. From those rows GroupProgress builds, in PHP, the per learner view (counts by status, average score over completed question sets, the behind flag through BehindRule, and the reasons: which assignments are overdue and since when, or which scores pull the average down) and the per assignment view (targeted learners, completed count, completion rate, average score).

Query counts, fixed by tests:

| Operation | Queries | What they are |
|---|---|---|
| GraphQL groupProgress, get_group_progress, find_learners_behind, find_hardest_assignments | 2 | Group lookup through the educator's scope (the Policy's scoped query, which also returns the group's members and institution), then the pair query |
| get_learner_summary | 2 (3 with a name lookup) | The groups the educator teaches that contain the learner, then the pair rows for that learner in those groups. A lookup by name runs the candidates query first |
| list_my_groups | 1 | Groups with learner and assignment counts, one aggregate |
| GET /api/v1/me/assignments | 1 | The learner's pair rows across their groups |

The reasons are part of the data, not a formatting step, so every interface returns the same ones. find_learners_behind also returns the rule in words (the sentence in api-contract.md, section 6), so the AI explains the right rule and does not invent one.

# API Endpoints

The exact request and response JSON for every endpoint is in [api-contract.md](api-contract.md). This section gives the behaviour.

Common rules:

- All REST paths start with /api/v1. A breaking change becomes /api/v2 next to it.
- JSON in and out. One error shape everywhere: `{ "error": { "code": "...", "message": "...", "details": { } } }`, with details only where the contract says so.
- Every route except login needs a bearer token (Passport, auth:api). The same guard protects REST, GraphQL and MCP (ADR-003).
- Status codes: 401 missing or bad token, 403 wrong role for the route, 404 not found or outside the caller's scope (ADR-006), 409 a repeat that carries a different value, 422 invalid input, 429 too many requests.
- Learners appear as id and name. Email is returned only by GET /api/v1/me (the caller's own) and by GET /api/v1/learners, where an educator picks learners of their own institution to add to a group.
- Every list endpoint pages with ?limit (default 50, max 200) and ?cursor.

## Authentication

| Endpoint | What it does | Body | Returns |
|---|---|---|---|
| POST /api/v1/auth/login | Log in and get an API token | email, password | 200 token, token_type, expires_at and the user (id, name, role). The token is a Passport personal access token valid 8 hours. 401 on a wrong password. Rate limited (see Rate limits) |
| POST /api/v1/auth/logout | Revoke the current token | none | 204 |
| GET /api/v1/me | The caller | none | 200 id, name, email, role and institution (id, name, timezone) |

## Educator endpoints

| Endpoint | What it does | Body | Returns |
|---|---|---|---|
| GET /api/v1/groups | My groups | none | 200 groups the caller teaches, each with learner_count and assignment_count |
| POST /api/v1/groups | Create a group in my institution | name | 201 the group. The caller becomes its educator. Audit group.created |
| GET /api/v1/groups/{group} | One group | none | 200 group with educators and learners (id, name). 404 if the caller does not teach it |
| POST /api/v1/groups/{group}/learners | Add learners | user_ids[] | 200 added and already_members. Every id must be a learner of the same institution, else 422 and nothing is written. Audit group.learner_added per learner |
| DELETE /api/v1/groups/{group}/learners/{user} | Remove a learner | none | 204. Progress rows stay. Repeating it answers 204 again. Audit group.learner_removed |
| GET /api/v1/learners | Learners of my institution, to add to groups | ?search= | 200 (id, name, email) |
| GET /api/v1/content | The content library | ?topic=, ?search= | 200 (id, title, topic, type, estimated_minutes) |
| GET /api/v1/groups/{group}/assignments | Active assignments of a group | none | 200 (id, content, due_at, scope, targeted_count) in due date order |
| POST /api/v1/groups/{group}/assignments | Assign content (the AssignContent action) | content_item_id, due_at, learner_ids[] optional | 201 when the assignment is created. 200 when the content was already assigned: the body says which learners were added, or that nothing changed. 409 already_assigned when the existing assignment has a different due date (nothing changes; the body carries the existing assignment). 422 for a due date in the past or a learner who is not in the group |
| PATCH /api/v1/assignments/{assignment} | Change the due date (the ChangeDueDate action) | due_at | 200 changed (true or false), due_at, previous_due_at. 422 for a date in the past. Audit assignment.due_changed when it changed |
| DELETE /api/v1/assignments/{assignment} | Remove an assignment | none | 204. Soft delete, progress kept. Repeating it answers 204 again. Audit assignment.removed |
| GET /api/v1/groups/{group}/audit | Who changed what in this group | ?limit, ?cursor | 200 entries newest first: actor (id, name), channel, action, changes, created_at |

AssignContent rules (REST and MCP share the class, ADR-005):

1. The group must be taught by the caller (404 otherwise). The content item must exist (404). The due date must be in the future (422). Every learner id given must be a current member of the group (422).
2. No active assignment for this content in this group: create it. Scope is group when no learner ids are given, learners otherwise. Audit assignment.created. Result: created.
3. An active assignment exists with a different due date: nothing changes. Result: already assigned with the existing due date, use change due date. REST answers 409.
4. An active assignment exists with the same due date and scope group: nothing changes, everyone already has it.
5. An active assignment exists with the same due date and scope learners: the learner ids not yet targeted are added (audit assignment.learners_added); with no learner ids the scope widens to group, which is additive. Result: the names added, and the names that already had it.

Progress is never touched by any of these.

## Learner endpoints

| Endpoint | What it does | Body | Returns |
|---|---|---|---|
| GET /api/v1/me/assignments | My assignments across my groups | none | 200 one row per assignment that targets me: assignment id, group, content (title, topic, type, estimated_minutes), due_at, status, score, started_at, completed_at. Removed assignments are not listed |
| PUT /api/v1/me/assignments/{assignment}/progress | Mark progress (the RecordProgress action) | status: in_progress or completed, score (question sets, on completion) | 200 status, score, started_at, completed_at, due_at, changed. 404 if the assignment is removed, not in my groups, or does not target me |

RecordProgress rules (ADR-005): PUT is a state, so a repeat sets the same state.

- in_progress: no row, create it with started_at now (changed). Row in_progress: nothing changes. Row completed: 409 already_completed.
- completed: a question set requires a score from 0 to 100 and an article must not carry one, else 422. No row: create it with started_at and completed_at now. Row in_progress: complete it. Row completed with the same score: nothing changes. Row completed with a different score: 409 already_completed. Completed is final in v1.
- Completing after the due date is allowed and turns overdue into completed. Lateness stays visible as completed_at after due_at.

## GraphQL

POST /graphql, educators only. One query in v1 (requirement 7):

```graphql
type Query {
  groupProgress(groupId: ID!): GroupProgress
}

type GroupProgress {
  group: Group!
  assignments: [Assignment!]!
  learners: [LearnerProgress!]!
  generatedAt: DateTime!
}

type Group { id: ID!  name: String!  institution: String! }

type Assignment {
  id: ID!  title: String!  topic: String!  type: ContentType!
  dueAt: DateTime!  scope: Scope!
  targetedCount: Int!  completedCount: Int!  completionRate: Float!  averageScore: Float
}

type LearnerProgress {
  learner: Learner!
  behind: Boolean!
  reasons: [String!]!
  averageScore: Float
  counts: StatusCounts!
  statuses: [AssignmentStatus!]!
}

type Learner { id: ID!  name: String! }
type StatusCounts { notStarted: Int!  inProgress: Int!  completed: Int!  overdue: Int! }
type AssignmentStatus { assignmentId: ID!  status: Status!  score: Int  completedAt: DateTime }

enum Status { NOT_STARTED  IN_PROGRESS  COMPLETED  OVERDUE }
enum ContentType { ARTICLE  QUESTION_SET }
enum Scope { GROUP  LEARNERS }
```

The resolver calls GroupProgress, which looks the group up through the educator's scope. A group the caller does not teach answers a null groupProgress with a "Group not found" error, the same answer as for a group that does not exist (ADR-006). A learner's statuses list only the assignments that target them.

## OAuth and web pages

| Path | What it does |
|---|---|
| GET /login, POST /login | The login page. Email and password, web session in the database. Educators and learners can log in; the consent step below is for educators only |
| POST /logout | Ends the web session |
| GET /oauth/authorize | Passport shows the consent page (an Inertia page, styled for LearnTrack): the client's name and what it will be able to do: read progress of your groups, assign content, change due dates. A learner sees a message that only educators can connect an AI assistant, and no code is issued |
| POST /oauth/authorize, DELETE /oauth/authorize | Approve or deny |
| POST /oauth/token | Authorization code with PKCE, and refresh |
| POST /oauth/register | Dynamic client registration. Claude registers itself here before the first login |
| GET /.well-known/oauth-protected-resource, GET /.well-known/oauth-authorization-server | The discovery documents Claude reads after the first 401 |
| POST /mcp | The MCP endpoint. auth:api plus the educator role. Stateless: every call carries the token |

Token lifetimes: MCP access tokens 1 hour, refresh tokens 30 days (Claude refreshes without asking the educator), authorization codes 10 minutes, API personal access tokens 8 hours. PKCE is required. The Passport signing keys and APP_KEY come from Secrets Manager (ADR-013). The housekeeping task runs passport:purge daily (ADR-014).

## MCP tools

Server: EducatorServer at /mcp. Seven tools. Every tool returns structured content (parseable JSON, with an output schema) plus a short text summary, and every answer carries the reason or the numbers behind it (requirement 8). Learners appear as id and name only (requirement 9). A group, learner or assignment outside the educator's scope is "not found" (ADR-006). The exact schemas are in [mcp-tools.md](mcp-tools.md).

| Tool | Input | Output | Annotations |
|---|---|---|---|
| list_my_groups | none | groups: id, name, institution, learner_count, assignment_count | read-only, idempotent |
| get_group_progress | group_id | group; learners with counts (not_started, in_progress, completed, overdue), average_score, behind, reasons; assignments with due_at, targeted_count, completed_count, completion_rate, average_score | read-only, idempotent |
| find_learners_behind | group_id | rule (in words); learners with reasons: overdue (assignment, due_at, days_overdue) or low_average (average_score, scores). An empty list means nobody is behind | read-only, idempotent |
| get_learner_summary | learner_id, or name when the id is unknown | learner; groups with behind, reasons, counts, average_score, assignments. Only the educator's groups. With a name that matches several learners: candidates and no summary. With no match: not found | read-only, idempotent |
| find_hardest_assignments | group_id, limit (default 5) | assignments with completion_rate, average_score and a reason, lowest completion rate first, then lowest average score | read-only, idempotent |
| assign_content | group_id, content_item_id or content_title, due_date, learner_ids optional | changed; assignment; added; already_had_it; message saying exactly what changed. A title that matches several items: candidates and no change. A due date in the past: an error that says so | not read-only, not destructive, idempotent |
| change_due_date | assignment_id, due_date | changed; assignment; previous_due_at; due_at. The same date answers changed: false | not read-only, not destructive, idempotent |

Rules for the tools:

- A tool never takes a user id to act as: the educator is the token's owner.
- Every write runs through the same action class as REST, inside one transaction with its audit row (channel mcp), and the answer lists what changed.
- Claude asks the educator to approve a write before calling it. The annotations tell the client the write is not destructive and is safe to repeat, which is what makes the approval dialog honest.
- The text summary never includes an email address, and the structured content has no email field. A test asserts both.
- Dates in answers carry the institution's offset, so "due Friday" reads as Friday.

## Rate limits

Counters live in the database cache store (ADR-013), so both tasks count together. Over the limit answers 429 with Retry-After and the standard error shape. A 429 does not spend budget. Only failed logins count: institutions sit behind one public address, so a limit on all logins would lock out a whole hospital at 9 am.

| Rule | Limit | Counted per |
|---|---|---|
| Failed logins, web and API | 10 per 15 minutes | IP and email |
| Failed logins, web and API | 50 per 15 minutes | IP |
| OAuth token endpoint | 30 per minute | IP |
| OAuth client registration | 10 per hour | IP |
| MCP endpoint | 60 requests per minute | educator (token owner) |
| REST and GraphQL | 300 requests per minute | user |

# Flow Walkthroughs with Estimates (Release 1)

Numbered work items with an estimate in days, none over 3 days. Items 1 to 5 are foundations, then the items follow the flows. Each item is also a ticket in [tickets/](tickets/README.md).

## Foundations

1\. **Repository and local development (2.5 days)** [ADR-001, ADR-007, ADR-015, ADR-017]

- One repository, one Laravel application. One Docker image built on a maintained nginx plus PHP-FPM base image, the same image for local and AWS. The container listens on 8080. A Node stage in the Dockerfile builds the Vite assets into public/build and the PHP stage copies them, so the running image has no Node.
- The Laravel React starter kit (Inertia, React, TypeScript, Tailwind) is installed and trimmed to the login page: register, password reset, email verification and profile pages removed.
- docker compose: app and MySQL 8. `php artisan migrate --seed` brings up the demo data.
- Pest, Pint and Larastan (level 6) run locally and in CI, with eslint, prettier and tsc for the frontend.
- A CLAUDE.md at the root distils the rules of this TDD for the AI coding assistant. The TDD wins on any disagreement, then CLAUDE.md is fixed.

2\. **Data model, migrations and seed (2 days)** [ADR-007, ADR-008]

- Migrations for every table in Database Design, with the generated column and the unique keys.
- Seed: 2 institutions, 6 educators, about 300 learners, 12 groups, 40 content items across 6 topics, assignments with past and future due dates, subset assignments, and progress that leaves some learners behind by overdue work and some by low scores. A fixed random seed, so every machine gets the same demo data.
- Factories for tests.

3\. **Authentication, roles and Policies (2 days)** [ADR-003, ADR-006]

- Passport with keys read from the environment (Secrets Manager in AWS), the personal access client seeded, token lifetimes set.
- POST login, logout and me. The role middleware (educator, learner).
- GroupPolicy, AssignmentPolicy, ProgressPolicy, and the one place that turns "not in scope" into 404 for REST, a not-found error for GraphQL and a not-found answer for MCP.
- Login rate limits on the database cache store.

4\. **Terraform: network and data (3 days)** [ADR-007, ADR-010, ADR-012, ADR-015]

- Bootstrap, the one manual step: the state bucket with locking, the GitHub OIDC provider, the deploy role, the ECR repository and the Route 53 hosted zone. These survive a destroy, so images are kept and the domain's nameservers never change.
- VPC in 2 AZs: public subnets (ALB, NAT gateways), private subnets (tasks, database), an internet gateway, one NAT gateway per AZ, the S3 gateway endpoint on the private route tables.
- Security groups: the ALB accepts 80 and 443 from the internet; the app accepts 8080 from the ALB only; the database accepts 3306 from the app only.
- RDS MySQL 8, Multi-AZ, encrypted, automated backups, require_secure_transport on. The master password, APP_KEY and the Passport key pair are generated by Terraform as ephemeral values and written to Secrets Manager with write-only arguments, so no secret lands in the state.
- An ACM certificate for the domain, validated by DNS records in the bootstrap's hosted zone.

5\. **Terraform: compute, schedule and pipeline (3 days)** [ADR-010, ADR-011, ADR-014, ADR-016]

- ECS cluster, task definition (0.25 vCPU, 0.5 GB to start), service with 2 tasks across 2 AZs, rolling deploys, deployment circuit breaker with rollback, lifecycle ignore_changes on task_definition.
- ALB: HTTPS listener with the ACM certificate, HTTP listener that redirects to HTTPS, target group with health check GET /up and a 30 second deregistration delay.
- CloudWatch: one log group (JSON logs, 30 day retention) and the alarms listed in the launch plan.
- EventBridge Scheduler rule that runs the housekeeping command as a one-off task once a day.
- GitHub Actions: ci (Pint, Larastan, Pest against a MySQL service container, eslint, tsc) on every pull request, plus terraform plan posted on the pull request; deploy on main: build the image tagged with the git SHA, push to ECR, terraform apply, run the migration task and stop on failure, register the task definition revision, update the service, wait until stable; a manual destroy workflow as the off switch for cost.
- The environment name and the region are variables; a second environment is one tfvars file and one OIDC role.

## Flows 2 and 3: groups, assignments and progress (REST)

6\. **Groups, membership, learners and content (2 days)** [ADR-006]

- GET and POST groups, GET one group, add and remove learners (same institution rule, repeat-safe), GET learners, GET content.
- The 404 rule tested on every route with another educator's group.

7\. **Assignments and the audit log (2.5 days)** [ADR-004, ADR-005]

- AssignContent with the five rules above, ChangeDueDate, RemoveAssignment (soft delete), each in one transaction with its audit row.
- GET assignments, POST assignments, PATCH assignment, DELETE assignment, GET audit.
- Tests: the same assign twice gives one row; a different due date answers 409 and changes nothing; adding learners to a subset assignment adds only the new ones; a removal keeps the progress rows; the unique key refuses a second active row when two requests race.

8\. **Learner endpoints (2 days)** [ADR-005, ADR-008]

- GET my assignments with the derived status, from the same pair query filtered to one learner.
- PUT progress with the RecordProgress rules: forward only, completed is final, score rules per content type, 404 for an assignment that does not target me.
- Tests for every transition and every repeat.

## Flow 4: the progress engine

9\. **GroupProgress and BehindRule (3 days)** [ADR-008]

- The pair query, the per learner and per assignment aggregates, the reasons, time zone handling.
- BehindRule as the one place with the threshold and the predicate.
- The query-count test at 60 learners and 100 assignments, then at double the learners, same count.
- A local timing run of the pair query at full size; the AWS measurement is in item 15.

10\. **GraphQL groupProgress (1.5 days)** [ADR-009]

- Lighthouse, the schema above, a resolver that calls GroupProgress, the not-found behavior, the educator role guard.
- A test that compares the GraphQL answer with the GroupProgress result for the same seeded group, field for field. The cross-interface comparison with get_group_progress is part of item 12.

## Flows 1, 4, 5 and 6: Claude

11\. **MCP server and the OAuth flow (3 days)** [ADR-001, ADR-002, ADR-003, ADR-017]

- laravel/mcp, EducatorServer at /mcp, Mcp::oauthRoutes(), the educator-only middleware.
- The login, consent and error pages as Inertia pages (React, TypeScript, Tailwind), trimmed from the Laravel React starter kit. Passport::authorizationView returns Inertia::render with the client name, the scopes, the auth token and the state. The learner message on consent.
- TrustProxies, token lifetimes, the housekeeping command (passport:purge and expired cache rows).
- Checked with the MCP Inspector locally and with Claude Code against http://localhost. Claude Desktop and the Claude app require HTTPS, so their check is against a tunnel (cloudflared) and then the deployed environment (item 15).

12\. **Read tools (3 days)** [ADR-006, ADR-008]

- The five read tools with input schemas, output schemas, structured content and text summaries, built on GroupProgress, LearnerSummary, HardestAssignments and MyGroups.
- get_learner_summary name matching with candidates.
- One test per tool for another educator's group: not found. One privacy test over every tool: no email anywhere in the answer. A comparison test that asserts GraphQL groupProgress and get_group_progress report the same statuses, behind flags and reasons for the same group.

13\. **Write tools (1.5 days)** [ADR-004, ADR-005]

- assign_content and change_due_date on the same action classes as REST, with annotations and channel mcp in the audit row.
- Content title resolution with candidates when several match.
- Tests: a repeated call changes nothing and says so; a different due date changes nothing and explains; the audit row exists after every change.

## Hardening and launch

14\. **Hardening and documentation (2 days)**

- Rate limits on every route group, the log processor that drops names, emails, passwords and tokens from log context, security headers, the alarms wired to an email topic.
- README: local setup, deployment, how to connect Claude Code, Claude Desktop and the Claude app to the MCP server, and how the seed data is laid out so the first questions have answers.

15\. **Launch check (1 day)**

- Deploy through the pipeline. Connect Claude Desktop to the live server and run Flows 1, 4, 5 and 6 end to end.
- Measure p95 of groupProgress on the largest seeded group and of every MCP tool with a small load script. Targets: 300 ms and 1 s.
- Run destroy and apply once, to prove the environment is reproducible from code.

## Total

**34 days.** Items 1 to 5 (foundations) 12.5 days, flows 2 and 3 6.5 days, flow 4 4.5 days, the Claude flows 7.5 days, hardening and launch 3 days. The blueprint's Release 1 estimate copies this number.

# Beyond the Happy Path (the five required sections)

## 1. Concurrency

- **Two educators assign the same content to the same group at the same moment:** both transactions try to insert the active row. The unique key uq_assignments_active lets one through; the other gets a duplicate key error, which AssignContent catches and turns into "already assigned" by re-reading the row. One assignment, two honest answers. [ADR-005]
- **A learner's app sends the same progress update twice at once** (a double tap, or a retry): RecordProgress locks the progress row for the pair (SELECT ... FOR UPDATE, or the insert itself when there is no row yet), applies the forward-only rule, and writes. The unique key uq_progress_pair stops a second row if both inserts race. [ADR-005]
- **A due date changes while a learner completes the assignment:** two different rows, no conflict. Status is derived at read time, so the next read is right for both. [ADR-008]
- **A learner is removed from a group while Claude asks about the group:** the pair query reads committed rows only, so the learner is in the answer or not, never half. Their progress rows stay.
- **An assignment is removed while a learner submits progress:** RecordProgress checks removed_at inside the same transaction, so the write answers 404 and nothing is saved.
- **Claude retries assign_content after a timeout:** the second call finds the assignment and reports nothing changed. One audit row. [ADR-005]
- **Two tasks, one OAuth login:** the session lives in the database, so the consent POST on task B sees the login that happened on task A. [ADR-013]
- **Two web requests hit a rate limit at the same moment:** the counter is one row in the cache table, incremented atomically, so both tasks count together. [ADR-013]
- **The housekeeping task overlaps with itself:** it runs once a day and every statement in it is safe to repeat, so an overlap deletes nothing twice and breaks nothing. [ADR-014]

## 2. Failure modes

- **RDS fails over** (Multi-AZ): about a minute of connection errors. Requests in that minute fail with 503, Claude shows the error, and the educator retries. No partial writes, because every multi-table write is one transaction.
- **A task dies mid-request:** the ALB answers 502 for that request, the other task keeps serving, and ECS starts a replacement.
- **One AZ is down:** the second task, the second NAT gateway and the RDS standby are in the other AZ. The ALB routes around, RDS fails over.
- **A bad deploy:** the health check on /up fails on the new task, the rolling deploy stops, and the deployment circuit breaker rolls the service back to the previous revision by itself. [ADR-016]
- **A migration fails:** the pipeline stops before the service update. The old image keeps running against a schema it understands, because migrations are expand-only in v1.
- **Secrets Manager is unreachable when a task starts:** the task fails to start and ECS retries. Running tasks are not affected, because secrets are read at start only. [ADR-013]
- **A NAT gateway fails in one AZ:** tasks in that AZ cannot pull images or ship logs; the ones already running keep serving and buffer their logs briefly, and new tasks start in the other AZ. [ADR-012]
- **Claude asks about an unknown or foreign group id:** not found, and nothing about the group's existence leaks. [ADR-006]
- **Claude passes a content title that matches several items:** the tool answers the candidates and writes nothing.
- **Claude asks for a due date in the past:** the tool explains that a due date must be in the future and writes nothing.
- **The OAuth access token expired:** the MCP endpoint answers 401 with a WWW-Authenticate header, Claude uses the refresh token, or runs the login flow again if that expired too.
- **A learner's app retries a progress update after a timeout:** the PUT is a state, so the retry sets the same state and answers changed: false. [ADR-005]
- **A rate limit is hit:** 429 with Retry-After. Claude tells the educator to wait a moment.
- **The audit write fails:** the whole transaction rolls back, so no change ever exists without its audit row.
- **The housekeeping task fails:** expired tokens accumulate until the next day. An alarm fires on the failed invocation. Nothing user facing breaks. [ADR-014]
- **The MCP client sends a malformed tool call:** laravel/mcp validation answers an error with the message from the input schema, so Claude can correct the call.

## 3. Security and access

- Passwords are bcrypt hashes. Tokens are Passport RS256 JWTs signed with keys held in Secrets Manager. Lifetimes: MCP access 1 hour with a 30 day refresh, API tokens 8 hours, authorization codes 10 minutes. PKCE is required. [ADR-003]
- Only educators pass the consent page and the MCP middleware. A learner's token is refused at /mcp with 403 even if it was obtained some other way. [ADR-003]
- Every read and write of a group's data passes one Policy check: a row in group_educators for the caller. A learner reads and writes only rows with their own user id. Anything outside scope answers 404, from one place in the code. [ADR-006]
- MCP answers carry a learner's id and name only. No email, no password hash, no institution data beyond its name. A test over every tool asserts it. [requirement 9]
- Logs carry user ids, never names, emails, passwords or tokens. A log processor drops those keys from every log context, request bodies are not logged, and the Authorization header never is. The audit log stores ids and resolves names at read time. [requirement: no learner personal data in logs]
- Secrets (database password, APP_KEY, Passport keys) live in Secrets Manager, are injected into the tasks at start, and never appear in the repository or in the Terraform state. [ADR-013]
- Transport: HTTPS at the ALB with an ACM certificate, HTTP redirected with 301, HSTS from the app, TLS required on the MySQL connection. [ADR-011]
- Network: the database accepts the app only, the app accepts the ALB only, only the ALB has a public address. Outbound goes through the NAT gateways. [ADR-012]
- Input: Form Requests validate shape and ranges; ids are always resolved through the Policy, never trusted from the client. An educator creates groups in their own institution only and adds learners of that institution only.
- Rate limits as in the table above, counted in the shared database store.

## 4. Testing strategy

- **Unit tests for BehindRule and the status derivation**, with a fixed clock: not started, in progress, completed, overdue; behind by overdue; behind by average below 50; exactly 50 is not behind; the average covers completed question sets only; an article never carries a score; a subset assignment counts only for its learners; a late completion is completed, not overdue.
- **Feature tests per REST endpoint**: the happy path, validation (422), the 404 rule with another educator's group on every route, the role rule (a learner on an educator route gets 403), and the repeat rules: the same assign twice gives one row; a different due date answers 409 with no change; the same PUT progress twice gives one row and changed: false; a backward status answers 409; completed with a different score answers 409.
- **Action tests** for the audit log: every change writes exactly one row with the right action and channel, and a failed write leaves no row.
- **MCP tool tests** with the laravel/mcp test helpers, acting as an educator: one test per tool for a group outside their scope (not found); one privacy test over every tool that asserts no email key and no email string in the whole answer; the content title candidates case; the learner name candidates case; a repeated write answers changed: false.
- **GraphQL tests**: the full groupProgress on a seeded group, the not-found answer for a foreign group, and a comparison test that asserts GraphQL and get_group_progress report the same statuses, behind flags and reasons for the same group.
- **Query-count tests**: seed a group with 60 learners and 100 assignments, run groupProgress and every MCP read tool, assert the exact query count; double the learners and the assignments and assert the same count.
- **Timing check**: time the pair query at full size locally (expected tens of milliseconds); the real p95 is measured in AWS in item 15 against the 300 ms and 1 s targets.
- **OAuth flow check**: the MCP Inspector runs the full discovery, login, consent and token flow against the local stack; Claude Code connects locally over http; Claude Desktop and the Claude app are checked through a tunnel and then against the deployed environment, because they require HTTPS. A test asserts the discovery documents are https when the request carries X-Forwarded-Proto: https.
- **Static analysis**: Larastan and Pint run in CI on every pull request, with eslint, prettier and the TypeScript compiler for the frontend.
- **Manual pass before release**: Flows 1, 4, 5 and 6 from Claude Desktop against the live server, including a question about a group the educator does not teach and a repeated assign_content.

## 5. Launch plan

- **No data migration**: the system is new. Migrations run as a one-off ECS task in the pipeline before the service update, never on task boot. Migrations are expand-only in v1: no column or table is dropped while the previous image can still run.
- **Environments**: docker compose locally; one AWS environment built and destroyed by the pipeline. The environment name and the region are Terraform variables, so a second environment is one tfvars file and one OIDC role. [ADR-015, ADR-016]
- **Rollout in three steps**: 1) deploy with seed data and connect one educator's Claude; 2) one pilot group with real educators and learners for two weeks, watching the alarms and the audit log; 3) onboard institutions by adding their accounts with the account command, since v1 has no admin screen.
- **Rollback**: one command to the previous task definition revision. Migrations are forward-only, so a rollback never needs a schema change. [ADR-016]
- **Monitoring** (minimal on purpose): CloudWatch alarms on ALB 5xx rate, unhealthy target count, running task count below 2, target response time p95 above 1 s, RDS CPU, free storage and connection count, and a failed housekeeping invocation. JSON logs in CloudWatch with 30 day retention. An email topic receives the alarms.
- **Success check after launch**: the blueprint metrics. groupProgress p95 under 300 ms on the largest group, every MCP tool p95 under 1 s, 99.9% uptime, a fixed query count at any group size, and no tool returning data from a group the educator does not teach.
