<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email verification for the SPA. The link is signed rather than
 * authenticated: someone clicking it from their inbox is usually not
 * carrying a bearer token.
 */
class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        // `signed` middleware already proved the URL was issued by us; the
        // hash additionally pins it to the address it was sent to, so a
        // re-signed link for another account is useless.
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'This verification link is not valid for this account.');
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This email address is already verified.',
                'verified' => true,
            ]);
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return response()->json([
            'message' => 'Your email address has been verified.',
            'verified' => true,
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This email address is already verified.',
                'verified' => true,
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'A fresh verification link is on its way.',
            'verified' => false,
        ]);
    }
}
