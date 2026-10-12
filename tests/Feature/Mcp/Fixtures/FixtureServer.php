<?php

namespace Tests\Feature\Mcp\Fixtures;

use Laravel\Mcp\Server;

class FixtureServer extends Server
{
    protected array $tools = [FindGroupTool::class];
}
