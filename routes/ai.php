<?php

use App\Mcp\Servers\EducatorServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

Mcp::oauthRoutes();

Route::getRoutes()->getByAction(OAuthRegisterController::class)?->middleware('throttle:oauth-register');

Mcp::web('/mcp', EducatorServer::class)->middleware(['auth:api', 'role:educator', 'throttle:mcp']);
