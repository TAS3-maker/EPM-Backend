<?php

use Throwable;
use Illuminate\Auth\AuthenticationException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;

public function render($request, Throwable $exception)
{
    if ($exception instanceof TokenExpiredException) {
        return response()->json([
            'status' => 'error',
            'message' => 'Token has expired. Please log in again.',
        ], 401);
    }

    if ($exception instanceof TokenInvalidException) {
        return response()->json([
            'status' => 'error',
            'message' => 'Invalid token. Please log in again.',
        ], 401);
    }

    if ($exception instanceof JWTException) {
        return response()->json([
            'status' => 'error',
            'message' => 'Token is missing. Please provide a valid token.',
        ], 401);
    }

    return parent::render($request, $exception);
}
