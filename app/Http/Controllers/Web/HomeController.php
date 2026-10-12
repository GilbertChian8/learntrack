<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('mcp/message', [
            'title' => 'You are signed in',
            'message' => "You are signed in as {$request->user()?->name}. Return to your AI assistant to finish connecting.",
            'signOut' => true,
        ]);
    }
}
