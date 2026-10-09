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
- Prefer a Laravel built-in over custom code: Policies, route model binding, Form Requests, API Resources, cursorPaginate, RateLimiter, SoftDeletes, enum casts, Carbon, and the Passport and laravel/mcp commands. Write custom code only where the contract needs a shape Laravel does not produce (error and paging envelopes, failure-only login limits, audit rows, log redaction, security headers).
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
cp .env.example .env
composer install && npm ci
php artisan key:generate
docker compose up -d            # app on http://localhost:8080, MySQL 8
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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
