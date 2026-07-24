<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stateless password reset. `sendLink` never reports whether an address
 * exists; `reset` is the only endpoint that can fail loudly, and only on
 * a bad or spent token.
 */
class PasswordResetController extends Controller
{
    private const GENERIC_MESSAGE = 'If that email belongs to an account, a reset link is on its way.';

    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        // The status is discarded on purpose: an unknown address, a
        // throttled retry and a real send all answer identically, so the
        // endpoint cannot be used to enumerate accounts.
        Password::broker()->sendResetLink($request->validated());

        return response()->json(['message' => self::GENERIC_MESSAGE]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->validated(),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // A forgotten password may mean a compromised session, so
                // every issued API token dies with the old password.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => __($status)]);
    }
}
