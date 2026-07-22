<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HelloController extends Controller
{
    /**
     * Simple API connectivity check for the SalonHub frontend.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Hello World from SalonHub API',
            'app' => config('app.name'),
        ]);
    }
}
