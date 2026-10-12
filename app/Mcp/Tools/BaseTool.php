<?php

namespace App\Mcp\Tools;

use App\Exceptions\NotFoundException;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * One array is the structured content and the source of the text summary. Not found is a tool error.
 */
abstract class BaseTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $answer = $this->answer($request);
        } catch (NotFoundException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::make(Response::text($this->summary($answer)))->withStructuredContent($answer);
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function answer(Request $request): array;

    /**
     * @param  array<string, mixed>  $answer
     */
    abstract protected function summary(array $answer): string;

    /**
     * The owner of the bearer token. No tool takes a user to act as.
     */
    protected function educator(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
