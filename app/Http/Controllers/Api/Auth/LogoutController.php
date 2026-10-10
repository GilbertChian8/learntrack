<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;
use Laravel\Passport\AccessToken;

class LogoutController extends Controller
{
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $token = $user->token();

        if ($token instanceof AccessToken) {
            $token->revoke();
        }

        return response()->noContent();
    }
}
