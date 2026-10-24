<?php

namespace Tests\Unit;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CsrfProtectionTest extends TestCase
{
    public function test_missing_csrf_token_rejects_state_changing_request(): void
    {
        $request = $this->requestWithSession();
        $middleware = new ValidateCsrfToken(Mockery::mock(\Illuminate\Contracts\Foundation\Application::class, function ($mock) {
            $mock->shouldReceive('runningInConsole')->andReturnFalse();
        }), app('encrypter'));

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => new Response());
    }

    public function test_changed_csrf_token_rejects_state_changing_request(): void
    {
        $request = $this->requestWithSession();
        $request->headers->set('X-CSRF-TOKEN', 'tampered-token');
        $middleware = new ValidateCsrfToken(Mockery::mock(\Illuminate\Contracts\Foundation\Application::class, function ($mock) {
            $mock->shouldReceive('runningInConsole')->andReturnFalse();
        }), app('encrypter'));

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => new Response());
    }

    private function requestWithSession(): Request
    {
        $session = app('session')->driver();
        $session->start();
        $request = Request::create('/api/v1/admin/auth/logout', 'POST');
        $request->setLaravelSession($session);

        return $request;
    }
}
