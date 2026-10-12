<?php

namespace App\GraphQL;

use App\Exceptions\NotFoundException;
use Closure;
use GraphQL\Error\Error;
use Nuwave\Lighthouse\Execution\ErrorHandler;

/**
 * A NotFoundException answers its message with the path and code not_found (ADR-006).
 */
class NotFoundErrorHandler implements ErrorHandler
{
    public function __invoke(?Error $error, Closure $next): ?array
    {
        $exception = $error?->getPrevious();

        if ($exception instanceof NotFoundException) {
            return $next(new Error($exception->getMessage(), path: $error->getPath(), extensions: ['code' => 'not_found']));
        }

        return $next($error);
    }
}
