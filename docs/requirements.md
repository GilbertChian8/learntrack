# Design Requirements: LearnTrack - Classroom Tracker

This document sets the requirements for LearnTrack before any design or code.

## About this project

LearnTrack is a demo backend service for medical schools and teaching hospitals. It shows how educators could see how their learners are doing, and ask an AI assistant about it in plain language.

The system has two ways in: a REST and GraphQL API for apps, and an MCP server that AI clients such as Claude can connect to.

## Business context

Medical educators teach groups of learners, for example a cohort of third-year students or residents on a rotation. They assign learning material from a content library, such as articles and question sets, and expect learners to finish it by a due date.

Data is organized like this:

- **Institution** (a medical school or hospital)
  - **Group** (a cohort taught by one or more educators)
    - **Learner**
- **Content library:** articles and question sets, each with a topic (for example, Cardiology)
- **Assignment:** a content item given to a group, or to some learners in it, with a due date

An educator only sees and acts on the groups they teach.

## The problem being solved

An educator may run several groups, each with dozens of learners and many assignments. To find who needs help, they open each group and scan long progress tables. This is slow, and struggling learners are noticed too late.

Educators want to ask simple questions and get a clear answer, for example:

- "Which learners in Cardiology Group A are behind?"
- "How is Sara doing across her assignments?"
- "Which assignment do most learners struggle with?"

LearnTrack lets an educator:

- Create groups and assign content with due dates
- See every learner's progress on every assignment
- Ask an AI assistant about progress, through an MCP server that only shows data the educator is allowed to see

## Functional requirements

1. **Authentication and authorization.** Every request runs on behalf of an authenticated user. The system manages users and issues tokens itself. There are two roles: educator and learner. An educator can only see and act on groups they teach. A learner can only see their own assignments and progress.
2. **Content library.** The system holds content items (articles and question sets). Each item has a title, topic, type and estimated minutes. Content is loaded by a seed command. No editing UI is needed in v1.
3. **Groups and membership.** An educator can create a group in their institution and add or remove learners. A learner can be in many groups.
4. **Assignments.** An educator can assign one or more content items to a group, each with a due date. An assignment goes to the whole group, or only to some learners in it (for example, learners who are behind). Changing or removing an assignment must not delete learners' past progress.
5. **Progress tracking.** A learner can mark an assignment as started or completed. For question sets, completion also records a score from 0 to 100. Each learner has a status on each assignment given to them: not started, in progress, completed, or overdue. Overdue is derived: the due date has passed and the assignment is not completed.
6. **The "behind" rule.** A learner is behind in a group if they have at least one overdue assignment in that group, or their average question set score in that group is below 50. Only assignments given to that learner count. The rule lives in one place in the code, so it can change later without touching every interface.
7. **API.** Core actions (groups, members, assignments, progress) are exposed as a REST API. A group's full progress is also available as one GraphQL query that returns the group, its assignments, every learner's status and the "behind" flag.
8. **MCP server for educators.** The system exposes an MCP server that AI clients (for example Claude Desktop or Claude Code) can connect to. It offers five read-only tools and two write tools:
   - `list_my_groups`: the educator's groups, with learner and assignment counts
   - `get_group_progress`: one group's progress, per learner and per assignment
   - `find_learners_behind`: learners who are behind in a group, with the reason (which assignment is overdue, or which scores are low)
   - `get_learner_summary`: one learner's status across the educator's groups
   - `find_hardest_assignments`: assignments in a group with the lowest completion rate or average score
   - `assign_content`: give a content item to one of the educator's groups, or to some learners in it, with a due date
   - `change_due_date`: move the due date of an assignment in one of the educator's groups

   Every tool call must be authenticated as an educator and apply the same authorization as the API. A tool must never return data from a group the educator does not teach. Results must include the reason behind each answer, so the AI can explain it and does not guess. Write tools only add or update data, and never delete it. Repeating a write gives the same result, and every write is recorded in an audit log.
9. **Learner privacy in AI answers.** MCP tools return only what an answer needs: the learner's name and progress. They never return emails, password hashes or other personal data.

## Non-functional requirements

### Scale

| Dimension | Target |
|---|---|
| Institutions | 10 |
| Educators | 200 |
| Learners | 5,000 |
| Groups | 300 |
| Learners per group | Up to 60 |
| Assignments per group | Up to 100 |
| Progress updates | 50,000 per day |

### Availability

The system must maintain 99.9% uptime (less than about 8.76 hours of unplanned downtime per year).

### Performance

- The group progress GraphQL query returns in under 300 ms at p95 for the largest group (60 learners and 100 assignments).
- Every MCP tool responds in under 1 second at p95.
- No N+1 queries: the number of database queries for a group's progress must not grow with the number of learners or assignments.

### Reliability

- Progress updates are idempotent: repeating the same request gives the same result, and never creates a duplicate record.
- A failed request saves no partial data. Any write that touches more than one table runs in a transaction.

### Security

- All API endpoints and MCP tools require authentication. Unauthenticated requests are rejected.
- Authorization is checked on the server for every request. It is never trusted from the client.
- MCP read tools never change data. MCP write tools only add or update data (assign content, change a due date), and never delete it.
- All traffic uses HTTPS.
- No credentials or secrets in source code. All secrets are injected at runtime through environment variables or a secrets manager.
- Learner personal data must not appear in logs.
- Login and MCP endpoints are rate limited.

### Operability

- Deployment to cloud infrastructure is automated, with no manual steps beyond the initial credentials setup.
- All infrastructure is defined as code.
- All configuration (database URLs, credentials, service endpoints) comes from environment variables or a secrets manager.
- The whole system also runs locally with docker compose (app and MySQL).
- One command seeds demo data: 2 institutions, educators, learners, groups, content and progress, with some learners behind, so the MCP tools have something real to find.
- The README explains setup, deployment, and how to connect an AI client to the MCP server.

## Out of scope for v1

To keep the first version small, these are not built:

- **Product frontend.** There is no app UI for educators or learners. The only web pages are the OAuth login and consent screens. The REST API, GraphQL and MCP server are the interfaces.
- **Content authoring.** Content comes from seed data.
- **Messaging learners** (email, push).
- **Single sign-on and user management.** Educators log in with an account in this system, not through their school's identity provider (SAML or OIDC). There is no institution admin role to create users or change roles. Accounts come from seed data.
- **Advanced observability.** No custom dashboards or tracing. Only default cloud metrics and logs, plus basic alarms.
