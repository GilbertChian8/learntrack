# 10: GraphQL groupProgress

**Estimate:** 1.5 days | **TDD item:** 10 | **ADRs:** ADR-009 | **Depends on:** 09 | **Phase:** 1, lane B

## Goal

`POST /graphql` with the one query of v1, served by Lighthouse over `GroupProgress`, with the exact schema from the TDD, the not-found answer from ADR-006, and the educator guard.

## Read first

- TDD: Request path and routing (the `/graphql` row), GraphQL (the schema and the paragraph under it), Testing strategy (GraphQL tests).
- api-contract.md: section 5 (all), section 6.
- ADR-006, ADR-009.

## Allowed paths

`graphql/schema.graphql`, `app/GraphQL/**`, `config/lighthouse.php`, `app/Providers/**` (registration only), `routes/graphql.php` if used, `tests/Feature/GraphQL/**`, `composer.json` and `composer.lock` (Lighthouse only).

## Steps

1. Install Lighthouse. If the installed Lighthouse release does not support Laravel 13 or PHP 8.5, stop and raise it: ADR-009 names the fallback and the decision is not this ticket's to make.
2. `graphql/schema.graphql` is the TDD schema verbatim, with the `DateTime` scalar bound to `App\GraphQL\Scalars\DateTime` (serializes a `CarbonImmutable` as ISO 8601 with the institution's offset, through `InstitutionTime`) and the enums mapped to the PHP enums.
3. The route: `POST /graphql` only, middleware `auth:api` and `role:educator` (the HTTP-level failures use the REST error shape, from ticket 03's handler). GET is not registered. Introspection stays on.
4. `GroupProgressResolver` calls `GroupProgress::for($user, $groupId)` and maps the result objects to the schema types. No query, no `Model` access and no computation in the resolver beyond mapping.
5. Error mapping: `NotFoundException` becomes a GraphQL error `{ "message": "Group not found", "path": ["groupProgress"], "extensions": { "code": "not_found" } }` with `data.groupProgress` null and HTTP 200. Register it as a Lighthouse error handler so every future resolver gets it. Unexpected exceptions never leak their message in production (`debug` off).
6. Enum values: `Status` from `DerivedStatus`, `ContentType`, `Scope`. `ID` fields render as strings.

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
- [ ] All checks green
- [ ] Documents changed, if any, listed with the reason
