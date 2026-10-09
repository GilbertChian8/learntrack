<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticateOnce()->load('institution');
        $token = $user->createToken('api');

        return response()->json([
            'data' => [
                'token' => $token->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->getToken()->expires_at->setTimezone($user->institution->timezone)->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role->value,
                ],
            ],
        ]);
    }
}
