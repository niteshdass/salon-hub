<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Starter service menus for the onboarding wizard. Static config, no
 * tenant data — but authenticated, because it is only ever needed by a
 * signed-in owner and there is no reason to serve it to the world.
 */
class ServicePresetController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => config('service_presets')]);
    }
}
