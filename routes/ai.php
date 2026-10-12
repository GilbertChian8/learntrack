<?php

use App\Mcp\Servers\EducatorServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', EducatorServer::class)->middleware(['auth:api', 'role:educator']);
