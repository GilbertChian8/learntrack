<?php

use function Pest\Laravel\get;

test('the health route answers 200', function () {
    get('/up')->assertOk();
});
