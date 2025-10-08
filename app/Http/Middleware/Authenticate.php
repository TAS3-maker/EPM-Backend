<?php
use Illuminate\Auth\AuthenticationException;
use Closure;
use Illuminate\Http\Request;

public function unauthenticated($request, AuthenticationException $exception)
{
    return response()->json([
        'status' => 'error',
        'message' => 'Unauthenticated. Please log in again.'
    ], 401);
}
