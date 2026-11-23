<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) env('DEVICE_API_TOKEN', '');
        $header   = $request->header('Authorization', '');
        $provided = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        if ($expected === '' || !hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        return $next($request);
    }
}