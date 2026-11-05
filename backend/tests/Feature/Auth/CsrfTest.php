<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

it('rejects a browser request without a CSRF token (419)', function () {
    // Laravel skips CSRF checks in tests; this test turns them back on.
    app()->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    config(['sanctum.middleware.validate_csrf_token' => ValidateCsrfToken::class]);

    $this->withHeaders(fromWebApp())
        ->postJson('/api/v1/auth/login', ['email' => 'a@b.c', 'password' => 'x'])
        ->assertStatus(419)
        ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
});
