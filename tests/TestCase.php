<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pest runs without a frontend build; the pages are checked by name, not by their assets.
        $this->withoutVite();
    }
}
