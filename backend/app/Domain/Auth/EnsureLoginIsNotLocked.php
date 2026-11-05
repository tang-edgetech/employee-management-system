<?php

namespace App\Domain\Auth;

use Closure;
use Illuminate\Http\Request;

/**
 * First step of the Fortify login pipeline: stop before checking the password
 * while the email is locked out.
 */
final class EnsureLoginIsNotLocked
{
    public function __construct(private readonly LoginThrottle $throttle) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->throttle->ensureNotLocked((string) $request->input('email'));

        return $next($request);
    }
}
