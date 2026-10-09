# LearnTrack ADRs

Architectural Decision Records for [PAS-001: LearnTrack](../blueprint.md). Requirements: [requirements.md](../requirements.md).

Status: all records ratified 09/10/2026. Each record has the same shape: the context, the decision required, the alternatives with their pros and cons, and the decision with the reasons that apply to this system. How a decision is built (tables, endpoints, pipeline steps) is in the [TDD](../tdd.md), not here.

| ADR | Title | Decision |
|---|---|---|
| [ADR-001](ADR-001-backend-framework.md) | Backend framework | Laravel (PHP) |
| [ADR-002](ADR-002-one-application.md) | One application or a separate MCP service | One Laravel application serves REST, GraphQL and MCP |
| [ADR-003](ADR-003-authentication.md) | Authentication for MCP and the APIs | OAuth 2.1 with Passport for MCP, Passport tokens for REST and GraphQL |
| [ADR-004](ADR-004-ai-writes.md) | What the AI is allowed to change | Additive and reversible writes only: assign content, change a due date |
| [ADR-005](ADR-005-idempotency.md) | How writes stay safe to repeat | Natural idempotency with unique constraints, no client key |
| [ADR-006](ADR-006-not-found.md) | Answer for data the caller may not see | 404 for any resource outside the caller's scope |
| [ADR-007](ADR-007-database.md) | Database engine | MySQL 8 on RDS, Multi-AZ |
| [ADR-008](ADR-008-status-computation.md) | Where status and the behind rule are computed | In SQL at read time, in one query class |
| [ADR-009](ADR-009-graphql-library.md) | GraphQL library | Lighthouse |
| [ADR-010](ADR-010-compute.md) | Application compute | ECS on Fargate, 2 tasks across 2 AZs |
| [ADR-011](ADR-011-entry-point.md) | Public entry point | ALB with an ACM certificate on our domain, no CloudFront |
| [ADR-012](ADR-012-outbound-network.md) | How private subnets reach the outside | One NAT gateway per AZ plus the free S3 gateway endpoint |
| [ADR-013](ADR-013-shared-state.md) | Shared state across tasks | The database holds sessions, cache and rate-limit counters |
| [ADR-014](ADR-014-background-work.md) | Background work | A daily scheduled ECS task; the web tasks run no scheduler |
| [ADR-015](ADR-015-infrastructure-as-code.md) | Infrastructure as code | Terraform |
| [ADR-016](ADR-016-ci-cd.md) | CI/CD pipeline | GitHub Actions with OIDC; the pipeline registers ECS revisions |
| [ADR-017](ADR-017-web-pages.md) | Web pages, Blade or Inertia with React | Inertia with React (TypeScript) and Tailwind for the three web pages |
