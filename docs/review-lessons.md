# Lessons from reviews

Rules added after a review found a mistake that could happen again in a later ticket. One line each, newest last. Ten lines maximum; past that, the fix belongs in the TDD or a ticket. A one-off mistake stays in its PR, not here.

- Larastan checks `tests/` and types `$this` in Pest closures as `TestCall`: no `$this->` in tests; use local variables, the `Pest\Laravel` functions and the helpers in `tests/Pest.php`.
