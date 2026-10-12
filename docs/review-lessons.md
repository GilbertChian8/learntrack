# Lessons from reviews

Rules added after a review found a mistake that could happen again in a later ticket. One line each, newest last. Ten lines maximum; past that, the fix belongs in the TDD or a ticket. A one-off mistake stays in its PR, not here.

- Larastan checks `tests/` and types `$this` in Pest closures as `TestCall`: no `$this->` in tests; use local variables, the `Pest\Laravel` functions and the helpers in `tests/Pest.php`.
- A new package can add routes, types or middleware through its discovered service providers: check what is served (`route:list`, GraphQL introspection), not only our files.
- MySQL JSON columns reorder object keys: compare decoded JSON with `toEqual`, not `toBe`.
- laravel/mcp adds `resultType`, `_meta` and cache hints to every JSON-RPC result: assert `result.*` paths, never the whole response.
- Cursor paging reads each order column from the row by name: order only by columns the row really has (page joined or aliased rows as a derived table).
- laravel/mcp 1.0.1 does not check tool input against the schema: a tool validates its own arguments with rules that mirror the schema.
- The base image's nginx writes its own logs and headers that Pest never sees: check a built container for any log or header change.
