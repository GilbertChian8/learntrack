<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Passport\PersonalAccessTokenResult;

/**
 * @mixin User
 */
class LoginResource extends JsonResource
{
    /**
     * @param  PersonalAccessTokenResult<mixed>  $tokenResult
     */
    public function __construct(User $user, private readonly PersonalAccessTokenResult $tokenResult)
    {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->tokenResult->accessToken,
            'token_type' => 'Bearer',
            'expires_at' => $this->tokenResult->getToken()->expires_at->setTimezone($this->institution->timezone)->toIso8601String(),
            'user' => [
                'id' => $this->id,
                'name' => $this->name,
                'role' => $this->role->value,
            ],
        ];
    }
}
