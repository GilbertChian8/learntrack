# 02: Data model, migrations and seed

**Estimate:** 2 days | **TDD item:** 2 | **ADRs:** ADR-007, ADR-008 | **Depends on:** 01 | **Phase:** 0

## Goal

Every table of the TDD's Database Design as migrations, with the unique keys and the CHECK constraints; Eloquent models and enums; factories; and one deterministic seed command that produces the demo data the MCP tools will have something to find in.

## Read first

- TDD: Database Design (the DDL and every note under it), The indexes that matter, item 2.
- ADR-007, ADR-008.
- api-contract.md: Enums (the exact string values).

## Allowed paths

`database/migrations/**`, `database/factories/**`, `database/seeders/**`, `app/Models/**`, `app/Enums/**`, `tests/Feature/Database/**`, `tests/Unit/Models/**`, `.env.example` (if a variable is added).

## Steps

1. Replace the starter kit's `users` migration with the TDD's `users` table (institution_id, role ENUM, no email verification columns, no remember token needed for the API but keep it for the web session). Keep Laravel's `sessions`, `cache`, `cache_locks` tables.
2. Migrations in DDL order: `institutions`, `users`, `learner_groups`, `group_educators`, `group_learners`, `content_items`, `assignments`, `assignment_learners`, `progress`, `audit_log`. Names, types, nullability, defaults, indexes and foreign keys as in the DDL. `assignments.removed_key` is a plain `BIGINT UNSIGNED NOT NULL DEFAULT 0` (0 while active, the row's id once removed), `uq_assignments_active` is on `(group_id, content_item_id, removed_key)`, and `chk_assignments_removed` keeps it in step with `removed_at`. The index and key names match the DDL.
3. Enums in `app/Enums`, backed by the contract's strings: `Role`, `ContentType`, `AssignmentScope`, `ProgressStatus` (`in_progress`, `completed`: what the table stores), `DerivedStatus` (`not_started`, `in_progress`, `completed`, `overdue`: what the API returns), `AuditChannel`, `AuditAction`.
4. Models: `Institution`, `User`, `Group` (`#[Table('learner_groups')]`), `ContentItem`, `Assignment`, `Progress`, `AuditEntry` (`#[Table('audit_log')]`). Relationships from the DDL, enum casts, `#[Fillable]`. `Assignment` has a query scope `active()` (`removed_at IS NULL`). `User` has `educatorOf()` and `memberOf()` relationships through the pivot tables.
5. Factories for every model, with states that matter: `User::factory()->educator()`, `->learner()`, `Assignment::factory()->forLearners([...])`, `->removed()`, `Progress::factory()->completed($score)`.
6. Seeders. `ContentSeeder`: 40 content items over 6 topics (Cardiology, Pulmonology, Nephrology, Neurology, Endocrinology, Pediatrics), articles and question sets, realistic titles. `DemoSeeder`: 2 institutions (Europe/Berlin and Europe/London), 3 educators each, about 300 learners, 12 groups (20 to 60 learners each, every group with at least one educator, some with two), 5 to 12 assignments per group with due dates between 30 days ago and 30 days ahead, 2 subset assignments per group, and progress rows such that every group has learners behind by overdue work, learners behind by a low average, and learners on track. All demo passwords are `password`. Fixed demo accounts, listed in the seeder's docblock: educator `anna.keller@example.edu` teaching `Cardiology Group A` in Northside Medical School, plus one learner account per institution. The Faker seed is fixed (`fake()->seed(42)`): a fixed random seed, so every machine gets the same names, memberships, assignments and scores; due dates are relative to the seeding day so the demo always has overdue and upcoming work. `DatabaseSeeder` calls both.
7. `php artisan migrate --seed` runs in under 60 seconds on the compose stack.

## Acceptance tests

- A migration test runs `migrate:fresh` and asserts, by reading `information_schema`, that `uq_assignments_active`, `uq_progress_pair`, `idx_assignments_group`, `chk_progress_score` and `chk_assignments_removed` exist.
- Inserting two active assignments for the same group and content item throws a duplicate key `QueryException`; removing the first (set `removed_at`, and `removed_key` to its id) and inserting again succeeds. A row with `removed_at` set and `removed_key` 0 is refused by `chk_assignments_removed`.
- Inserting a progress row with `score = 101` throws (the CHECK constraint); 100 is accepted; `null` is accepted.
- Two progress rows for the same assignment and user throw a duplicate key error.
- Every factory creates a valid row (one test that calls each factory once).
- `migrate:fresh --seed` produces 2 institutions, 6 educators, 12 groups, 40 content items, at least 250 learners; every group has at least 5 active assignments and at least one subset assignment; every group has at least one learner with an overdue, not completed pair and at least one learner whose average completed question set score is below 50 (asserted with plain SQL in the test, since BehindRule is ticket 09).
- The seed is deterministic: two fresh runs on the same frozen clock give the same first ten learner names and the same assignment due dates.
- `Model::shouldBeStrict` stays on: a test that accesses an unloaded relationship throws.

## Out of scope

Policies, Passport tables (ticket 03 installs Passport), any endpoint, the housekeeping command.

## PR checklist

- [ ] Title `02: Data model, migrations and seed`
- [ ] Migrations match the DDL name for name; any deliberate difference is explained and the TDD updated in this PR
- [ ] All checks green
- [ ] `migrate:fresh --seed` timed and under 60 s
- [ ] Documents changed, if any, listed with the reason
