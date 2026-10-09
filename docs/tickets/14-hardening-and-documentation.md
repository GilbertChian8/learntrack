# 14: Hardening and documentation

**Estimate:** 2 days | **TDD item:** 14 | **ADRs:** ADR-011, ADR-013 | **Depends on:** 05, 08, 12, 13 | **Phase:** 3

## Goal

The remaining rate limits, log redaction, security headers and the alarm wiring are in place, and the README lets a new person run the system, deploy it, and connect Claude Code, Claude Desktop and the Claude app to it.

## Read first

- TDD: Rate limits (the whole table), Security and access, Launch plan (Monitoring), item 14.
- api-contract.md: the `too_many_requests` error.
- Requirements: Security, Operability.
- ADR-011, ADR-013.

## Allowed paths

`app/Providers/AppServiceProvider.php` (rate limiters), `app/Http/Middleware/SecurityHeaders.php`, `app/Logging/**`, `bootstrap/app.php`, `config/logging.php`, `routes/*.php` (throttle middleware only), `README.md`, `docs/architecture.png` (adding the exported diagram if missing), `infra/env/*.tf` (alarm email wiring only, if ticket 05 left a gap), `tests/Feature/RateLimits/**`, `tests/Feature/Security/**`, `tests/Unit/Logging/**`.

## Steps

1. Rate limiters on the database cache store, named as in the TDD table: `oauth-token` 30 per minute per IP on `POST /oauth/token`; `oauth-register` 10 per hour per IP on `POST /oauth/register`; `mcp` 60 per minute per authenticated user on `POST /mcp`; `api` 300 per minute per authenticated user on `/api/v1/*` and `/graphql` (the login failure limits exist since ticket 03). Over the limit answers 429 in the contract shape with `Retry-After` and `details.retry_after`; a 429 does not count against the limit.
2. Log redaction: a Monolog processor on every channel that removes the keys `name`, `email`, `password`, `password_confirmation`, `token`, `access_token`, `refresh_token`, `authorization` and `cookie` from the log context at any depth, and replaces any string value that looks like an email address with `[redacted]`. Request bodies are never logged. The `Authorization` header is never logged. Logs are JSON on stderr with the request id, the user id (when authenticated), the route name, the status and the duration.
3. Security headers middleware on every response: `Strict-Transport-Security: max-age=31536000; includeSubDomains` (only when the request is https), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, and a Content-Security-Policy for the three pages that allows self plus the Vite dev server in local only.
4. Alarms: confirm every alarm from the TDD's Monitoring list exists in Terraform and sends to the email topic; fill any gap left by ticket 05 (tagged here, not re-designed).
5. README: what LearnTrack is and who it is for (from the blueprint, two paragraphs); the architecture picture (`docs/architecture.png`, exported from the diagram source if it is not yet in the repository); local setup; the demo accounts and how the seed data is laid out (which groups have learners behind, and why, so the first questions have answers); the REST and GraphQL quick start with three curl examples; connecting Claude Code (`claude mcp add --transport http learntrack https://<host>/mcp`), Claude Desktop (Settings, Connectors, add custom connector with the URL) and the Claude app, with the OAuth login and consent steps described; five example questions to ask; deployment (bootstrap, the pipeline, rollback, destroy); the documents index (`docs/`).

## Acceptance tests

- For each limiter: the request over the limit answers 429 in the contract shape with `Retry-After` and `details.retry_after`, requests under it pass, the 429 itself does not consume budget, and two users do not share a per-user budget.
- A log line written with a context containing `email`, `name`, `password` and a nested `token` has none of them after the processor; a string value `a@b.c` becomes `[redacted]`; the `user_id` key stays.
- A request log line contains the request id, the user id, the route name and the status, and never the body or the `Authorization` header (tested with a request that sends both).
- Every response, including 401, 404 and 429, carries the four fixed security headers; the HSTS header appears only when the request is https (or carries `X-Forwarded-Proto: https` from a trusted proxy).
- `terraform validate` green; the alarm list in `infra/env` matches the TDD's Monitoring bullet one for one (checked by hand, listed in the PR).
- README reviewed by following it from a clean machine: every command runs as written. The Claude Desktop steps were followed once against the tunnel and once against the deployed environment (ticket 15 repeats the latter).

## Out of scope

The load measurement and the destroy and apply round trip (ticket 15). New features of any kind.

## PR checklist

- [ ] Title `14: Hardening and documentation`
- [ ] Rate limits match the TDD table exactly
- [ ] No name, email, password or token can reach a log line (tests prove it)
- [ ] README followed end to end on a clean machine
- [ ] Documents changed, if any, listed with the reason
