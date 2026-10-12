<?php

namespace App\Mcp\Tools;

use App\Exceptions\NotFoundException;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * One array is the structured content and the source of the text summary. Not found is a tool error.
 */
abstract class BaseTool extends Tool
{
    /**
     * @throws ValidationException
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $this->rejectUnknownArguments($request);

        try {
            $answer = $this->answer($request);
        } catch (NotFoundException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::make(Response::text($this->summary($answer, $request)))->withStructuredContent($answer);
    }

    /**
     * Every input schema in mcp-tools.md allows no other argument.
     */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function answer(Request $request): array;

    /**
     * The text, built from the answer and the request, nothing else.
     *
     * @param  array<string, mixed>  $answer
     */
    abstract protected function summary(array $answer, Request $request): string;

    /**
     * An argument the input schema does not name is an error, not ignored.
     *
     * @throws ValidationException
     */
    private function rejectUnknownArguments(Request $request): void
    {
        $unknown = array_diff(array_keys($request->all()), array_keys($this->schema(new JsonSchemaTypeFactory)));

        if ($unknown !== []) {
            throw ValidationException::withMessages(array_map(fn (string $name) => "The argument \"{$name}\" is not in the input schema.", array_combine($unknown, $unknown)));
        }
    }

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
