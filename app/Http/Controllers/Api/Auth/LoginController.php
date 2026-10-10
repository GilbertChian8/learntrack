<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\LoginResource;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): LoginResource
    {
        $user = $request->authenticateOnce()->load('institution');

        return new LoginResource($user, $user->createToken('api'));
    }
}
