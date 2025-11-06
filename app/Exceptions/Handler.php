<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->toJsonResponse($e);
        }

        return parent::render($request, $e);
    }

    protected function toJsonResponse(Throwable $e): JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($e instanceof HttpExceptionInterface) {
            return response()->json([
                'message' => $e->getMessage() ?: Response::$statusTexts[$e->getStatusCode()] ?? 'Error',
            ], $e->getStatusCode(), $e->getHeaders());
        }

        return response()->json([
            'message' => 'Server Error',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
