# 01: Repository and local development

**Estimate:** 2.5 days | **TDD item:** 1 | **ADRs:** ADR-001, ADR-007, ADR-015, ADR-017 | **Depends on:** none | **Phase:** 0

## Goal

A Laravel 13 application on PHP 8.5 that runs in one Docker image (nginx plus PHP-FPM, port 8080) next to MySQL 8 under docker compose, with the Laravel React starter kit installed and trimmed to the login page, and every check (Pest, Pint, Larastan level 6, eslint, prettier, tsc) green locally and in GitHub Actions. Nothing domain-specific yet.

## Read first

- TDD: Application structure, Request path and routing, item 1.
- ADR-001, ADR-002, ADR-013, ADR-017.
- CLAUDE.md, all of it.

## Allowed paths

Everything, this is the first ticket. Keep `docs/` untouched except for a link fix.

## Steps

1. Create the application with the Laravel installer on PHP 8.5 and Laravel 13, with the React starter kit (TypeScript). Pin exact versions in `composer.json` and `package.json`. Set `APP_TIMEZONE=UTC`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`, `LOG_CHANNEL=stderr` with JSON output in `.env.example` and `config/`.
2. Trim the starter kit. Remove registration, password reset, email verification, the settings and profile pages, the dashboard and the welcome page, their routes, controllers, requests, React pages, components and tests. Keep the login page (`resources/js/pages/auth/Login.tsx`), the layout pieces it uses, and logout. Rename the page folder for the two MCP pages later: ticket 11 adds `resources/js/pages/mcp`.
3. Routes left in `routes/web.php` and `routes/auth.php`: `GET /` (guest: redirect to `/login`; signed in: redirect to `/login` as well for now, ticket 11 gives it a page), `GET /login`, `POST /login`, `POST /logout`, `GET /up`. `php artisan route:list` shows nothing else from the starter kit.
4. `AppServiceProvider`: `Model::shouldBeStrict(! app()->isProduction())` (this includes `preventLazyLoading`), `Date::use(CarbonImmutable::class)`.
5. Dockerfile with three stages: a `node:24` stage that runs `npm ci && npm run build`; a `composer` stage that runs `composer install --no-dev --optimize-autoloader`; a final stage on a maintained nginx plus PHP-FPM base image for PHP 8.5 that copies the application, `vendor/` and `public/build`. The final image has no Node and no dev dependencies, listens on 8080, and serves `/up`. If no 8.5 tag of the base image exists yet, build the final stage from `php:8.5-fpm` with nginx installed and say so in the PR.
6. `compose.yaml`: `app` (built from the Dockerfile, port 8080, environment from `.env`) and `mysql` (`mysql:8.4`, root password `root`, database `learntrack`, a named volume, a health check). `app` waits for the health check. A local-only entrypoint runs `php artisan passport:keys` when the keys are missing (the command exists after ticket 03; add the line then).
7. Tooling: Pest (default in Laravel 13), Pint (default preset), Larastan with `phpstan.neon.dist` at level 6 covering `app/`, `database/`, `routes/`, `tests/`. The starter kit's eslint, prettier and `tsc --noEmit` (`npm run types`) stay as they are.
8. `.github/workflows/ci.yml`, on every pull request: PHP 8.5 with the extensions Laravel needs, `composer install`, Node 24, `npm ci`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run lint`, `npm run format:check`, `npm run types`, `npm run build`, then `vendor/bin/pest` against a `mysql:8.4` service container (`DB_CONNECTION=mysql`). Tests never use SQLite.
9. `phpunit.xml` sets `DB_CONNECTION=mysql` and nothing about the database name (a value in `phpunit.xml` cannot be overridden by `.env.testing`). `.env.testing.example` (committed) carries `DB_DATABASE=learntrack_test` and the other test values; each worktree copies it to `.env.testing` (gitignored) and changes the database name.
10. A minimal `README.md`: what LearnTrack is (two sentences, from the blueprint), local setup (the commands in CLAUDE.md), and a link to `docs/`. The full README is ticket 14.
11. `.editorconfig`, `.gitattributes`, `.gitignore` (add `.env.testing`, `storage/oauth-*.key`, `infra/**/.terraform`, `*.tfstate*`).
12. AI tooling, committed: `composer require laravel/boost --dev`, then `php artisan boost:install --guidelines --mcp` for Claude Code. Boost appends its `<laravel-boost-guidelines>` block to the end of `CLAUDE.md`; leave the block there and the content above it untouched. Add the Context7 server to the same `.mcp.json` (`{"context7": {"type": "http", "url": "https://mcp.context7.com/mcp"}}`), so every later ticket can look up Lighthouse, Terraform and GitHub Actions docs. Commit `.mcp.json` and `boost.json`.
13. Before building anything above, check that `laravel/passport`, `nuwave/lighthouse` and `laravel/mcp` install on PHP 8.5 and Laravel 13 (`composer require --dry-run`). If one does not, stop and report it: the fallback is PHP 8.4 (still supported by Laravel 13), and that decision is not this ticket's.

## Acceptance tests

- `docker compose up -d --build` then `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/up` prints `200`.
- `docker run --rm <image> sh -c "command -v node || echo none"` prints `none`.
- `vendor/bin/pest` is green and contains the starter kit's login tests (adjusted) and no registration, reset or verification test.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run lint`, `npm run format:check`, `npm run types`, `npm run build` all exit 0.
- `php artisan route:list --except-vendor` lists only `/`, `/login` (GET, POST), `/logout`, `/up`.
- A Pest test asserts `Model::preventsLazyLoading()` is true in the testing environment.
- The CI workflow runs on a pull request and every step passes.
- `.mcp.json` lists the Boost and Context7 servers; `CLAUDE.md` ends with the Boost guidelines block and its own content is unchanged above it.
- The PR description records the result of the three-package install check.

## Out of scope

Tables, Passport, any `/api` route, the consent and error pages, Terraform.

## PR checklist

- [ ] Title `01: Repository and local development`
- [ ] All checks in CLAUDE.md green locally and in CI
- [ ] Versions pinned in `composer.json` and `package.json`
- [ ] No starter kit leftovers (registration, reset, verification, settings, dashboard, welcome)
- [ ] Documents changed, if any, listed with the reason
