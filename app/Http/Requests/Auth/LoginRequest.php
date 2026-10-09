<?php

namespace App\Http\Requests\Auth;

use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The web and the API login share these limits: only failures count, per IP and email and per IP.
 */
class LoginRequest extends FormRequest
{
    private const int FAILURES_PER_EMAIL_AND_IP = 10;

    private const int FAILURES_PER_IP = 50;

    private const int WINDOW_SECONDS = 15 * 60;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Sign the user in on the web session.
     *
     * @throws ThrottleRequestsException
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->recordFailure();

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->emailAndIpKey());
    }

    /**
     * Check the credentials for this request only, with no session.
     *
     * @throws ThrottleRequestsException
     * @throws InvalidCredentialsException
     */
    public function authenticateOnce(): User
    {
        $this->ensureIsNotRateLimited();

        $user = Auth::once($this->only('email', 'password')) ? Auth::user() : null;

        if (! $user instanceof User) {
            $this->recordFailure();

            throw new InvalidCredentialsException;
        }

        RateLimiter::clear($this->emailAndIpKey());

        return $user;
    }

    /**
     * @throws ThrottleRequestsException
     */
    private function ensureIsNotRateLimited(): void
    {
        $retryAfter = collect($this->limits())
            ->filter(fn (int $maxFailures, string $key) => RateLimiter::tooManyAttempts($key, $maxFailures))
            ->map(fn (int $maxFailures, string $key) => RateLimiter::availableIn($key))
            ->max();

        if ($retryAfter !== null) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => $retryAfter]);
        }
    }

    private function recordFailure(): void
    {
        foreach (array_keys($this->limits()) as $key) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }
    }

    /**
     * @return array<string, int>
     */
    private function limits(): array
    {
        return [
            $this->emailAndIpKey() => self::FAILURES_PER_EMAIL_AND_IP,
            'login-failures:ip:'.$this->ip() => self::FAILURES_PER_IP,
        ];
    }

    private function emailAndIpKey(): string
    {
        return 'login-failures:email-ip:'.sha1($this->string('email')->lower().'|'.$this->ip());
    }
}
