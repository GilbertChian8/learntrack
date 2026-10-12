# 10: GraphQL groupProgress

**Estimate:** 1.5 days | **TDD item:** 10 | **ADRs:** ADR-009 | **Depends on:** 09 | **Phase:** 1, lane B

## Goal

`POST /graphql` with the one query of v1, served by Lighthouse over `GroupProgress`, with the exact schema from the TDD, the not-found answer from ADR-006, and the educator guard.

## Read first

- TDD: Request path and routing (the `/graphql` row), GraphQL (the schema and the paragraph under it), Testing strategy (GraphQL tests).
- api-contract.md: section 5 (all), section 6.
- ADR-006, ADR-009.

## Allowed paths

`graphql/schema.graphql`, `app/GraphQL/**`, `config/lighthouse.php`, `app/Providers/**` (registration only), `routes/graphql.php` if used, `tests/Feature/GraphQL/**`, `composer.json` and `composer.lock` (Lighthouse only), `bootstrap/providers.php` and `bootstrap/app.php` (Lighthouse's provider and the route file), `phpunit.xml` (the test memory limit), `Dockerfile` (`lighthouse:cache`). For step 1 only: `app/Queries/GroupProgress.php`, `app/Queries/PairRows.php`, `app/Queries/LearnerAggregation.php`, `tests/Unit/Queries/**`, `tests/Feature/Queries/**`, and the pair SQL in `docs/tdd.md` if its order changes.

## Steps

1. Stream the pair rows, as the first commit. At 120 learners and 200 assignments, ticket 09's test peaks at 123 MB of the 128 MB test limit, because `GroupProgress` holds every pair row of the group at once. `PairRows::forGroup` orders by learner id, then the assignment order (sorting by name made the pair query 30 to 40% slower; the member order comes from the first query). `GroupProgress` reads it with `cursor()`, builds one learner's `LearnerProgress` and drops that learner's rows, and keeps running totals per assignment instead of the rows. Still exactly two queries, and the result is unchanged: ticket 09's tests pass as they are. The test memory limit stays at 128M; raising it is not the fix. The PR gives the peak memory of `GroupProgress::for` at 120x200 before and after.
2. Install Lighthouse. If the installed Lighthouse release does not support Laravel 13 or PHP 8.5, stop and raise it: ADR-009 names the fallback and the decision is not this ticket's to make.
3. `graphql/schema.graphql` is the TDD schema verbatim, with the `DateTime` scalar bound to `App\GraphQL\Scalars\DateTime` (serializes a `CarbonImmutable` as ISO 8601 with its offset; the query results already hold the institution's time zone, set by `InstitutionTime`) and the enums mapped to the PHP enums. The scalar and the enums are registered in `AppServiceProvider`, so the file needs no directive.
4. The route: `POST /graphql` only, middleware `auth:api` and `role:educator` (the HTTP-level failures use the REST error shape, from ticket 03's handler). GET is not registered. Introspection stays on.
5. The resolver, `App\GraphQL\Queries\GroupProgress` (Lighthouse finds it by the field name, so the schema needs no `@field` directive), calls `GroupProgress::for($user, $groupId)` and maps the result objects to the schema types. No query, no `Model` access and no computation in the resolver beyond mapping.
6. Error mapping: `NotFoundException` becomes a GraphQL error whose message is the exception's message, `{ "message": "Group not found.", "path": ["groupProgress"], "extensions": { "code": "not_found" } }`, with `data.groupProgress` null and HTTP 200. Register it as a Lighthouse error handler so every future resolver gets it. Unexpected exceptions never leak their message in production (`debug` off).
7. Enum values: `Status` from `DerivedStatus`, `ContentType`, `Scope`. `ID` fields render as strings.

## Acceptance tests

- The full query from api-contract.md on a seeded group returns the contract's shape: `group`, `generatedAt`, `assignments` ordered by `dueAt` then id, `learners` ordered by name then id, `statuses` only for targeted assignments in assignment order, enums upper-case, `ID` values strings, every `DateTime` with the institution's offset.
- The GraphQL answer equals `GroupProgress::for` field for field on the same group (one test that walks both structures), so the resolver adds nothing and loses nothing.
- Another educator's group and a non-existent id both answer HTTP 200 with `data.groupProgress` null and the exact error object from the contract; the two bodies are identical.
- No token: 401 in the REST error shape. A learner's token: 403 `forbidden` in the REST error shape.
- `GET /graphql` answers 404 or 405, never a result.
- The request runs exactly 2 database queries after authentication (`Passport::actingAs` adds none) at 60 learners and 100 assignments, and at double that.
- A malformed query (unknown field) answers a standard GraphQL validation error with HTTP 200 and no `data`.

## Out of scope

Mutations, more queries, persisted queries, caching, the comparison with `get_group_progress` (ticket 12).

## PR checklist

- [ ] Title `10: GraphQL groupProgress`
- [ ] Schema file diffed against the TDD: identical
- [ ] Resolver contains mapping only
- [ ] Peak memory of `GroupProgress::for` at 120x200, before and after step 1
- [ ] All checks green
- [ ] Documents changed, if any, listed with the reason
