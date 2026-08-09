<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HelloController extends Controller
{
    /**
     * Simple API connectivity check for the Glowhub frontend.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Hello World from Glowhub API',
            'app' => config('app.name'),
        ]);
    }
}
