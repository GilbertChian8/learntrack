# 03: Authentication, roles and Policies

**Estimate:** 2 days | **TDD item:** 3 | **ADRs:** ADR-003, ADR-006 | **Depends on:** 02 | **Phase:** 0

## Goal

Passport issues tokens; `POST /api/v1/auth/login`, `logout` and `GET /api/v1/me` work; the two roles are enforced; the Policies exist; one exception handler renders the contract's error shape; and "outside scope" becomes 404 from one place in the code. Every later ticket builds on these.

## Read first

- TDD: Request path and routing, Authentication, Rate limits (the failed-login rows), Security and access, item 3.
- api-contract.md: Conventions (Errors, Roles), section 2.
- ADR-003, ADR-006.

## Allowed paths

`app/Exceptions/**`, `app/Http/Controllers/Api/Auth/**`, `app/Http/Controllers/Api/Controller.php`, `app/Http/Middleware/**`, `app/Http/Requests/Auth/**`, `app/Http/Resources/UserResource.php`, `app/Policies/**`, `app/Providers/**`, `app/Support/**`, `bootstrap/app.php`, `config/auth.php`, `config/passport.php`, `routes/api.php`, `database/seeders/PassportSeeder.php`, `database/seeders/DatabaseSeeder.php`, `tests/Feature/Auth/**`, `tests/Unit/Policies/**`, `.env.example`, `compose.yaml` (the keys line), `.github/workflows/ci.yml` (the keys step).

## Steps

1. Install Passport, run its migrations, set the `api` guard to the `passport` driver. Keys: `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` from the environment when set (AWS), else files in `storage/` (local). CI runs `php artisan passport:keys --force` before Pest; the compose entrypoint runs `passport:keys` when the files are missing.
2. Lifetimes in `AppServiceProvider::boot`: `tokensExpireIn` 1 hour, `refreshTokensExpireIn` 30 days, `personalAccessTokensExpireIn` 8 hours, authorization codes 10 minutes (Passport's default; set it explicitly only if the installed version exposes a setter).
3. A `PassportSeeder` that creates the personal access client when none exists (`passport:client --personal` in code), called from `DatabaseSeeder`. Passport 13 finds the personal access client by itself; verify against the installed version and, if it needs a config entry, read it from env.
4. Routes in `routes/api.php` under the `/api/v1` prefix: `POST auth/login` (no auth), `POST auth/logout` and `GET me` (`auth:api`). `LoginRequest` validates email and password. The login controller checks the credentials, issues a personal access token, and returns the contract's body. `UserResource` renders `me` with the institution.
5. `EnsureRole` middleware, aliased `role`, used as `role:educator` and `role:learner`. Wrong role answers 403 `forbidden`.
6. Exception handling in `bootstrap/app.php` for `/api/*`, `/graphql` and `/mcp` requests: `AuthenticationException` to 401 `unauthenticated` with `WWW-Authenticate: Bearer`; the role failure to 403 `forbidden`; `App\Exceptions\NotFoundException`, `ModelNotFoundException` and `NotFoundHttpException` to 404 `not_found`; `ValidationException` to 422 `validation_failed` with `details`; `ThrottleRequestsException` to 429 `too_many_requests` with `Retry-After` and `details.retry_after`; `App\Exceptions\ConflictException` (abstract, with `code()` and `details()`, parents of `AlreadyAssignedException` and `AlreadyCompletedException` that tickets 07 and 08 fill) to 409; anything else to 500 `server_error` with no internals. Web requests keep Inertia's default handling.
7. `NotFoundException` carries a subject (`Group`, `Learner`, `Assignment`, `Content item`) and nothing else. REST renders plain 404 `not_found`; GraphQL (ticket 10) renders `Group not found` without a period; MCP (ticket 12) renders `Group not found.` with one. Each interface adds its own punctuation.
8. Policies own the scope predicates as query builders, so a lookup and its check are one query: `GroupPolicy::taughtBy(User $educator): Builder` (groups with a `group_educators` row for the educator) and `view(User, Group): bool` built on it; `AssignmentPolicy::manageableBy(User $educator): Builder` (active assignments whose group the educator teaches) and `manage(User, Assignment)`; `ProgressPolicy::targeting(User $learner): Builder` (active assignments in the learner's groups that target them, scope group or an `assignment_learners` row) and `update(User, Assignment)`. `app/Support/Scope.php` is the one place that turns "not in scope" into `NotFoundException`: `Scope::group(User $educator, int $id): Group`, `Scope::assignment(User $educator, int $id): Assignment`, `Scope::learnerAssignment(User $learner, int $id): Assignment`, each a `firstOrFail` on the matching Policy builder, each throwing `NotFoundException` with its subject. Later tickets extend the group lookup with joins (ticket 09 adds the members and the institution) but always start from `GroupPolicy::taughtBy`. Nothing else in the code base compares user ids to group rows.
9. Failed-login rate limits on the database cache store, for the API login and the web login: 10 failures per 15 minutes per IP and email, and 50 per 15 minutes per IP. Only failures count; a success clears the pair counter. Over the limit answers 429 before checking the password.
10. `Passport::actingAs` is how tests authenticate from now on; document that in `tests/Pest.php` with a helper `actingAsEducator()` and `actingAsLearner()`.

## Acceptance tests

- Login with the right password: 200, body has `data.token`, `data.token_type` `Bearer`, `data.expires_at` about 8 hours ahead, `data.user` with `id`, `name`, `role` and nothing else.
- Login with a wrong password: 401 `invalid_credentials`; with an unknown email: the same status and body. A missing field: 422 `validation_failed` with `details.email` or `details.password`.
- The token from login works on `GET /api/v1/me` and the body matches the contract including `institution.timezone`. `POST /api/v1/auth/logout` answers 204 and the same token then answers 401 `unauthenticated` with the `WWW-Authenticate` header.
- A request without a token to `GET /api/v1/me` answers 401 with the contract's error body.
- Ten failed logins for one email from one IP, then the eleventh answers 429 with `Retry-After` and `details.retry_after`; a successful login resets the counter; successful logins never count. The per-IP limit of 50 is tested with different emails.
- The web login (`POST /login`) applies the same limits.
- A learner calling a route behind `role:educator` answers 403 `forbidden`; an educator behind `role:learner` the same. (Use a throwaway route registered in the test.)
- `GroupPolicy::view` is true for an educator in `group_educators` and false for any other user, including an educator of the same institution. `AssignmentPolicy` follows its group and excludes removed assignments. `ProgressPolicy::update` is true only for a learner the active assignment targets (scope group member, or an `assignment_learners` row for a current member).
- `Scope::group` for a missing id and for another educator's group throws the same `NotFoundException`, which renders 404 `not_found` with an identical body in both cases; `Scope::assignment` for a removed assignment does the same.
- An unknown `/api/v1/...` route answers 404 in the contract shape, and a `ValidationException`, a `ThrottleRequestsException` and an unexpected exception each render their contract shape (tested through throwaway routes).

## Out of scope

OAuth authorization code flow, the consent page, `Mcp::oauthRoutes()` (ticket 11); any group or assignment route (06, 07); the non-login rate limits (14).

## PR checklist

- [ ] Title `03: Authentication, roles and Policies`
- [ ] Error shape verified against api-contract.md for every mapped exception
- [ ] No Policy logic outside `app/Policies`
- [ ] All checks green
- [ ] Documents changed, if any, listed with the reason
