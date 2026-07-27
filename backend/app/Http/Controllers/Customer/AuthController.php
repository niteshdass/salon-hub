<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Mail\CustomerLoginCodeMail;
use App\Models\CustomerAccount;
use App\Models\CustomerLoginCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Passwordless customer authentication. No tenant is bound on these routes,
 * so nothing here is tenant-scoped — the account is a global identity.
 */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        CustomerLoginCode::create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        Mail::to($email)->send(new CustomerLoginCodeMail($code));

        // Generic response — never reveal whether an account exists.
        return response()->json(['message' => 'If that email is valid, a code has been sent.']);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);
        $email = strtolower($data['email']);

        $row = CustomerLoginCode::where('email', $email)->active()->latest('id')->first();

        if (! $row) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            return response()->json(['message' => 'Too many attempts. Request a new code.'], 429);
        }

        if (! Hash::check($data['code'], $row->code_hash)) {
            $row->increment('attempts');

            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $row->update(['consumed_at' => now()]);

        $account = CustomerAccount::firstOrCreate(['email' => $email]);
        $account->forceFill(['email_verified_at' => now()])->save();

        // Task 3 inserts the auto-claim call here.

        $token = $account->createToken('customer')->plainTextToken;

        return response()->json([
            'token' => $token,
            'account' => $this->accountPayload($account),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['account' => $this->accountPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /** @return array<string, mixed> */
    private function accountPayload(CustomerAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'phone' => $account->phone,
        ];
    }
}
