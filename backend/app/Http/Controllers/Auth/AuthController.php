<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterOrganization;
use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterOrganization $action): JsonResponse
    {
        $result = $action->execute($request->validated());

        $user = $result['user'];
        $organization = $result['organization'];

        // Sends the verification email (User implements MustVerifyEmail).
        event(new Registered($user));

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'organization' => new OrganizationResource($organization->load('domains')),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Keyed on (email, IP), same convention as Laravel's own
        // Fortify/Breeze throttling: one attacker's failures cannot lock the
        // real owner out from a different address. The tradeoff is that a
        // distributed attacker rotating source IPs is NOT capped by this
        // limiter — each new IP gets its own 5-attempt budget. The only
        // per-IP bound on login is the route-level `throttle:20,1`.
        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Please try again later.'],
            ])->status(429);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Resolve the organization BEFORE minting a token. Previously the
        // token row was written first and `$user->organization->load(...)`
        // below then 500'd on a null, persisting a personal access token for
        // an account that could never complete a sign-in. It also left the
        // "an org-less user cannot log in" property resting on a null-pointer
        // dereference, one `?->` away from silently disappearing during a
        // routine 500 fix. Refuse explicitly instead, on the same rule the
        // `tenant` middleware enforces on every request after this one, so
        // a suspended salon is refused at the door rather than handed a token
        // that 403s on every subsequent call.
        $organization = $user->organization;

        if (! $organization || $organization->status !== OrganizationStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'email' => ['This account is not active. Please contact support.'],
            ]);
        }

        // A genuine sign-in clears the counter so a user who fat-fingers a
        // password twice is not one typo away from a lockout.
        RateLimiter::clear($key);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'organization' => new OrganizationResource($organization->load('domains')),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'organization' => new OrganizationResource($user->organization->load('domains')),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
