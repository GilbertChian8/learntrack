# CLAUDE.md

LearnTrack is a Laravel backend for medical educators: REST and GraphQL for apps, plus an MCP server that lets an AI assistant answer "who is behind and why" about the groups an educator teaches, and assign content. This file is the working rule set for anyone (human or AI) writing code here.

## Source of truth, in order

1. `docs/tdd.md`: the design. Tables, layering, status rules, work items. It wins every disagreement.
2. `docs/api-contract.md` and `docs/mcp-tools.md`: the exact wire contract. Code matches them byte for byte (field names, codes, messages, order).
3. `docs/adr/`: why things are the way they are. Do not reopen a ratified decision inside a ticket; propose a new ADR instead.
4. `docs/tickets/`: the work, one ticket per pull request.
5. This file: the condensed rules. If this file and the TDD disagree, follow the TDD and fix this file in the same pull request.

If the code must differ from a document, change the document in the same pull request and say why in the PR description. Silent drift is a bug.

## Stack

PHP 8.5, Laravel 13, MySQL 8 (InnoDB, utf8mb4, UTC). Laravel Passport (OAuth 2.1, PKCE, personal access tokens for REST). laravel/mcp (Streamable HTTP, stateless). Lighthouse for GraphQL. Inertia with React 19, TypeScript and Tailwind for the three web pages (login, consent, error) from the Laravel React starter kit. Pest, Pint, Larastan level 6, eslint, prettier, tsc. Docker (nginx plus PHP-FPM, port 8080). Terraform and GitHub Actions for AWS (ECS Fargate, RDS MySQL Multi-AZ, ALB).

Pin exact versions in `composer.json` and `package.json` the first time a package is added. Before adding any package not named in the TDD, stop and ask; it needs an ADR.

## Rules that are not negotiable

- **Layering.** Controllers, GraphQL resolvers and MCP tools hold no business logic. They validate input, call one Action or one Query class, and format the result. Business rules live in `app/Actions` (writes) and `app/Queries` (reads).
- **One Policy layer.** Every scope check goes through `app/Policies`: the Policies own the scoped query builders (`GroupPolicy::taughtBy($educator)` and friends), and `app/Support/Scope.php` is the one place that turns "not in scope" into `NotFoundException`. Nothing else compares a user id to a group.
- **404, not 403.** Anything outside the caller's scope answers not found, the same as a thing that does not exist (ADR-006). 403 is only for the wrong role on a route.
- **Status is computed, never stored.** The derived status comes from the pair query in SQL at read time; the behind flag and the reasons come from `BehindRule` over that query's rows, in `GroupProgress` (ADR-008). No status column, no cached flag.
- **BehindRule is the only place that knows 50.** Nothing else compares a score to the threshold.
- **Fixed query counts.** A group's progress is a fixed number of queries whatever its size. Tests assert the exact count. No lazy loading: `Model::preventLazyLoading()` is on outside production.
- **Writes are natural idempotent** (ADR-005). Repeats change nothing and say so. Unique constraints are the lock; catch the duplicate key error and re-read.
- **One transaction per Action, audit row inside it.** No change exists without its audit row. Audit rows hold ids and values, never names or emails.
- **AI writes are additive only** (ADR-004). The MCP server never deletes anything and never exposes a tool that does.
- **No PII in logs or in MCP answers.** Logs carry user ids only; a log processor strips names, emails, passwords and tokens. MCP answers show a learner as id and name, nothing else.
- **Shared state lives in the database** (ADR-013): sessions, cache, rate limits. No Redis, no file cache, no in-memory assumptions across tasks.
- **No scheduler in the web tasks** (ADR-014). Housekeeping is one artisan command run by a scheduled ECS task.
- **Dates:** stored UTC, returned ISO 8601 with the institution's offset. A bare `YYYY-MM-DD` due date means 23:59:59 in the institution's time zone.

## Conventions

- Folders: `app/Models`, `app/Policies`, `app/Actions`, `app/Queries`, `app/Http/Controllers/Api`, `app/Http/Requests`, `app/Http/Resources`, `app/GraphQL`, `app/Mcp/Servers`, `app/Mcp/Tools`, `app/Enums`, `graphql/schema.graphql`, `resources/js/pages/auth`, `resources/js/pages/mcp`.
- One class per Action and per Query, named as in the TDD (`AssignContent`, `GroupProgress`, `BehindRule`, ...). Actions expose one `__invoke` or `handle` method and return a small result object, not an HTTP response.
- Validation in Form Requests. Output through API Resources. Enums are PHP backed enums in `app/Enums` with the exact string values from the contract.
- Raw SQL only inside `app/Queries`, as named query builder or `DB::select` with bindings. Never string-concatenate input into SQL.
- `env()` only inside `config/`. Everything else reads `config()`.
- Errors go through one exception handler that renders the contract's `{ "error": { "code", "message", "details" } }` shape. Do not hand-build error JSON in controllers.
- Migrations match the DDL in the TDD, including the generated column and the unique keys. Migrations are expand-only; never drop a column or table in v1.
- Tests: Pest, one feature test file per endpoint or tool, factories for data, a fixed clock (`Carbon::setTestNow`) for anything date-based. Every ticket lists its acceptance tests; write those first.
- Frontend: TypeScript strict, components under `resources/js`, forms through Inertia `useForm`, no API calls from the pages. Delete starter kit pages that are not login, consent or error.
- Commit messages: imperative, one line under 72 characters, a body when the why is not obvious. Small commits.

## Style references

- PHP and Laravel: Pint (Laravel preset) formats; where this file is silent, naming and structure follow the Spatie Laravel and PHP guidelines (https://spatie.be/guidelines/laravel-php).
- React: the Laravel React starter kit layout is the convention (`resources/js/pages`, `components`, `layouts`, `lib`, `types`). Three pages need no feature-folder architecture; do not add one.
- Laravel Boost is installed as a dev dependency. Its guidelines block sits at the bottom of this file. On any conflict, the rules above and the TDD win.

## Looking up documentation

- Never write a package API from memory. Boost's `search-docs` answers for Laravel, Passport, Inertia, Pest, Pint and laravel/mcp, matched to the versions in `composer.lock`. Context7 (`.mcp.json`) answers for everything else: Lighthouse, the Terraform AWS provider, GitHub Actions, React, Tailwind.
- Boost's `database-schema`, `database-query`, `tinker` and `last-error` tools show the real state of the application. Use them instead of guessing.

## Checks before every pull request

All of these pass locally and in CI:

```
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
npm run lint
npm run format:check
npm run types
```

Pest runs against MySQL (docker compose), not SQLite: the generated column, the ENUMs and the CHECK constraint must be real.

## Local development

```
docker compose up -d            # app on http://localhost:8080, MySQL 8
composer install && npm ci
php artisan migrate --seed      # demo data: 2 institutions, groups, content, progress with learners behind
npm run dev                     # Vite, for the three pages
```

## Ticket workflow

- One ticket, one branch (`feat/NN-short-name`), one pull request. The ticket's "Allowed paths" are the only files the PR touches, plus tests. Need to touch something else? Say so in the PR and keep it minimal.
- Read the ticket, the TDD sections it names, and the contract sections it names before writing code.
- Write the acceptance tests from the ticket first, make them pass, then refactor.
- The PR description lists: the ticket number, what was built, how it was tested, and any document changed. Use the PR checklist at the end of the ticket.
- Never merge with failing checks. Never force-push a shared branch. Never use `--no-verify`.

## Do not

- Add a package, a table, a column, an endpoint, a tool or a field that is not in the TDD or the contract. Ask first.
- Put business logic in a controller, resolver, tool or model.
- Store a status, cache a behind flag, or compare a score to 50 outside BehindRule.
- Return 403 for a resource outside scope, or leak whether it exists.
- Return an email from an MCP tool, or log a name, email, password or token.
- Commit secrets, `.env` files, Passport keys or Terraform state.
- Leave starter kit leftovers: registration, password reset, email verification, profile settings, the welcome page.
- Use `env()` outside `config/`, raw SQL outside `app/Queries`, or SQLite in tests.
- Widen a ticket's scope. A good idea that is not in the ticket goes in the PR description as a note.
