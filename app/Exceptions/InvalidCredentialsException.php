<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InvalidCredentialsException extends RuntimeException implements ShouldntReport {}
