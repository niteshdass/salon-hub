<?php

namespace App\Http\Controllers\Public;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\PublicBookingRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\PaymentSetting;
use App\Models\Review;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\BookingNotifier;
use App\Services\SlotGenerator;
use App\Services\SslcommerzGateway;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Customer-facing booking API. No authentication: the organization is bound
 * by the `public.tenant` middleware from the {org} slug, after which the
 * tenant global scope isolates every query to that organization.
 *
 * The {org} route parameter is intentionally not read here — the tenant is
 * already resolved and bound before these actions run.
 */
class BookingController extends Controller
{
    public function __construct(protected AppointmentScheduler $scheduler) {}

    /**
     * Public profile of the salon plus its bookable branches.
     */
    public function organization(): JsonResponse
    {
        $tenant = app(CurrentTenant::class)->get();

        return response()->json([
            'data' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                // Prices on the wizard are the salon's, not the visitor's, and
                // the booking page wears the same cover as the salon's site.
                'currency' => $tenant->currency,
                'cover_image_url' => $tenant->cover_image
                    ? Storage::disk('public')->url($tenant->cover_image)
                    : null,
                'theme_color' => Setting::query()
                    ->where('organization_id', $tenant->id)
                    ->value('theme_color'),
                'branches' => Branch::query()->get()->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'city' => $branch->city,
                    'address' => $branch->address,
                    'phone' => $branch->phone,
                ])->values(),
                'payment' => $this->publicPaymentPolicy(),
            ],
        ]);
    }

    /**
     * The salon's public-facing deposit policy: whether a deposit is required,
     * how it is computed, and the manual-transfer details a customer needs to
     * pay it. Gateway secrets are never exposed.
     *
     * @return array<string, mixed>
     */
    protected function publicPaymentPolicy(): array
    {
        $settings = PaymentSetting::query()->first();

        return [
            'requires_deposit' => (bool) $settings?->requiresDeposit(),
            'deposit_type' => $settings?->deposit_type?->value ?? 'none',
            'deposit_value' => number_format((float) ($settings?->deposit_value ?? 0), 2, '.', ''),
            'manual' => [
                'enabled' => (bool) ($settings?->manual_enabled ?? false),
                'account_number' => $settings?->manual_account_number,
                'instructions' => $settings?->manual_instructions,
            ],
            'gateway' => [
                'enabled' => (bool) ($settings?->gatewayEnabled() ?? false),
                'provider' => $settings?->gateway ?? 'none',
            ],
        ];
    }

    /**
     * Open an SSLCommerz hosted session for a booking's online deposit and
     * return the redirect URL. The success/fail/cancel callbacks route back
     * through the API, keyed by the payment's transaction id. Returns null if
     * the gateway declines — the pending payment is left for follow-up.
     */
    protected function startGatewaySession(PaymentSetting $settings, Appointment $appointment, string $tranId, string $amount): ?string
    {
        $tenant = app(CurrentTenant::class)->get();
        $customer = $appointment->customer;

        $base = rtrim((string) config('app.url'), '/')
            .'/api/public/'.$tenant?->slug.'/payment/'.$tranId;
        $callback = $base.'/callback';

        try {
            return app(SslcommerzGateway::class)->initiate($settings, [
                'total_amount' => $amount,
                'currency' => $tenant?->currency ?: 'BDT',
                'tran_id' => $tranId,
                'success_url' => $callback.'/success',
                'fail_url' => $callback.'/fail',
                'cancel_url' => $callback.'/cancel',
                // Server-to-server notification, independent of the browser.
                'ipn_url' => $base.'/ipn',
                'cus_name' => $customer?->name ?? 'Customer',
                'cus_email' => $customer?->email ?? 'customer@example.com',
                'cus_phone' => $customer?->phone ?? '0000000000',
                'product_name' => $appointment->service?->name ?? 'Salon service',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Active services the salon offers (tenant-scoped).
     */
    public function services(): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            Service::where('status', ServiceStatus::ACTIVE->value)
                ->with('category')
                ->latest('id')
                ->get()
        );
    }

    /**
     * Staff who can perform the given service, path-scoped:
     * `/public/{org}/services/{service}/staff`.
     */
    public function staffForService(string $org, Service $service): JsonResponse
    {
        return $this->staffPayload($service);
    }

    /**
     * The same action on a salon's own subdomain, where the URI carries no
     * {org} segment: `/public/services/{service}/staff`.
     *
     * A second method rather than one method with an optional first argument.
     * Laravel hands route parameters to a controller positionally, so the two
     * URIs cannot share one signature — and the Service must stay a typed
     * parameter, because implicit route binding is driven off the method
     * signature. Losing the typehint would leave a raw id here, and with it
     * the tenant global scope that turns another salon's service id into a
     * 404 (SubstituteBindings runs after public.tenant).
     */
    public function staffForServiceOnHost(Service $service): JsonResponse
    {
        return $this->staffPayload($service);
    }

    /**
     * Staff who can perform the given service. When no service in the salon
     * has any staff assignment (assignment is optional), fall back to every
     * active staff member so the salon is still bookable.
     */
    protected function staffPayload(Service $service): JsonResponse
    {
        $staff = $service->staff()->with('staffProfile')->get();

        if ($staff->isEmpty() && ! Service::has('staff')->exists()) {
            $staff = User::where('organization_id', app(CurrentTenant::class)->id())
                ->where('role', UserRole::STAFF->value)
                ->where('status', 'active')
                ->with('staffProfile')
                ->get();
        }

        return response()->json([
            'data' => $staff->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'designation' => $member->staffProfile?->designation,
                'profile_image' => $member->staffProfile?->profile_image,
            ])->values(),
        ]);
    }

    /**
     * Open start times for a staff member on a date for a chosen service.
     */
    public function slots(Request $request, SlotGenerator $slotGenerator): JsonResponse
    {
        $tenantId = app(CurrentTenant::class)->id();

        $validated = $request->validate([
            'service_id' => [
                'required',
                Rule::exists('services', 'id')->where('organization_id', $tenantId),
            ],
            'staff_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('organization_id', $tenantId)
                    ->where('role', UserRole::STAFF->value),
            ],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where('organization_id', $tenantId),
            ],
        ]);

        $service = Service::findOrFail($validated['service_id']);
        $staff = User::where('organization_id', $tenantId)
            ->where('role', UserRole::STAFF->value)
            ->findOrFail($validated['staff_id']);
        $branch = $this->resolveBranch($validated['branch_id'] ?? null);

        return response()->json([
            'data' => [
                'date' => $validated['date'],
                'slots' => $slotGenerator->generate($service, $staff, $validated['date'], $branch),
            ],
        ]);
    }

    /**
     * The branch a booking applies to: an explicit (tenant-scoped) id, else
     * the salon's first branch. Null when the salon has no branch yet.
     */
    protected function resolveBranch(?int $branchId): ?Branch
    {
        return $branchId ? Branch::find($branchId) : Branch::query()->first();
    }

    /**
     * Create a pending booking. Re-checks availability inside the transaction
     * so a slot taken between the customer viewing it and submitting cannot be
     * double-booked, and finds-or-creates the customer by phone.
     */
    public function book(PublicBookingRequest $request, BookingNotifier $notifier, SlotGenerator $slotGenerator): JsonResponse
    {
        $data = $request->validated();

        $service = Service::findOrFail($data['service_id']);
        $staff = User::findOrFail($data['staff_id']);
        $branch = $this->resolveBranch($data['branch_id'] ?? null);
        if (! $branch) {
            return response()->json(['message' => 'This salon is not accepting online bookings yet.'], 422);
        }

        $startTime = $this->scheduler->normalizeTime($data['start_time']);
        $endTime = $this->scheduler->deriveEndTime($data['start_time'], $service->duration);

        // The requested start must still be an open slot: this re-checks the
        // staff + branch hours, existing conflicts and past times in one gate,
        // closing the gap between viewing a slot and submitting the booking.
        if (! in_array(substr($startTime, 0, 5), $slotGenerator->generate($service, $staff, $data['date'], $branch), true)) {
            return response()->json(['message' => 'Sorry, that time slot is no longer available.'], 422);
        }

        // Deposit gate. A deposit is only collected when the salon both wants
        // one and has a working way to take it (manual transfer or gateway).
        $settings = PaymentSetting::query()->first();
        $manualEnabled = (bool) ($settings?->manual_enabled ?? false);
        $gatewayEnabled = (bool) ($settings?->gatewayEnabled() ?? false);
        $collectDeposit = (bool) $settings?->depositCollectable();

        $reference = $data['payment_reference'] ?? null;
        $method = $data['payment_method'] ?? null;

        if ($collectDeposit) {
            // Resolve the collection method against what the salon actually offers.
            if ($method === 'gateway' && ! $gatewayEnabled) {
                return response()->json(['message' => 'Online payment is not available for this salon.'], 422);
            }
            if ($method === 'manual' && ! $manualEnabled) {
                return response()->json(['message' => 'Manual transfer is not available for this salon.'], 422);
            }
            if ($method === null) {
                if ($manualEnabled && ! $gatewayEnabled) {
                    $method = 'manual';
                } elseif ($gatewayEnabled && ! $manualEnabled) {
                    $method = 'gateway';
                } else {
                    // Both are offered: the customer must choose one.
                    return response()->json(['message' => 'Choose how you would like to pay the deposit.'], 422);
                }
            }

            // Manual transfers must carry the reference the customer paid with.
            if ($method === 'manual' && blank($reference)) {
                return response()->json([
                    'message' => 'A deposit is required to confirm this booking. Enter the transaction reference for your transfer.',
                ], 422);
            }
        }

        $branchId = $branch->id;
        $depositAmount = $collectDeposit ? $settings->depositFor((float) $service->price) : null;
        // A gateway payment is keyed by an unguessable transaction id echoed
        // back on the callback; generated up front so it can seed the session.
        $tranId = ($collectDeposit && $method === 'gateway') ? 'SH'.strtoupper(Str::random(18)) : null;

        // A signed-in visitor books as themselves. Identity then comes from the
        // account rather than the form, which is what puts the booking on their
        // dashboard — matching typed emails is only the anonymous fallback.
        $account = CustomerAccount::current();

        $appointment = DB::transaction(function () use ($data, $service, $branchId, $startTime, $endTime, $collectDeposit, $method, $depositAmount, $reference, $tranId, $account) {
            $customer = $this->resolveCustomer($data['customer'], $account);

            $appointment = Appointment::create([
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'staff_id' => $data['staff_id'],
                'service_id' => $data['service_id'],
                'booking_date' => $data['date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                // Freeze what this booking owes at today's menu price.
                'price' => $service->price,
                'status' => AppointmentStatus::PENDING->value,
                'notes' => null,
            ]);

            if ($collectDeposit && $method === 'manual') {
                // Pending: the salon still has to confirm the transfer arrived.
                $appointment->payments()->create([
                    'amount' => $depositAmount,
                    'method' => PaymentMethod::BANK_TRANSFER,
                    'status' => PaymentStatus::PENDING,
                    'source' => PaymentSource::PUBLIC_MANUAL,
                    'reference' => $reference,
                ]);
            }

            if ($collectDeposit && $method === 'gateway') {
                // Pending until the gateway callback validates the payment.
                $appointment->payments()->create([
                    'amount' => $depositAmount,
                    'method' => PaymentMethod::ONLINE,
                    'status' => PaymentStatus::PENDING,
                    'source' => PaymentSource::GATEWAY,
                    'transaction_id' => $tranId,
                ]);
            }

            return $appointment;
        });

        $appointment->load(['service', 'staff', 'branch', 'customer', 'payments']);

        $notifier->sendForNewBooking($appointment);

        $payload = $this->bookingPayload($appointment);

        // Online deposit: open the hosted session and hand back the URL the
        // customer's browser must be redirected to.
        if ($collectDeposit && $method === 'gateway') {
            $payload['gateway_url'] = $this->startGatewaySession($settings, $appointment, $tranId, $depositAmount);
        }

        return response()->json(['data' => $payload], 201);
    }

    /**
     * The salon's customer row this booking belongs to.
     *
     * Anonymous: find-or-create by phone within the tenant, keeping an existing
     * row's stored name/email, then link it to a verified platform account if
     * the email matches one.
     *
     * Signed in: the account's own name and email are used, and the row is
     * claimed for the account. A phone already owned by a *different* account
     * is left alone and a new row is created instead — phones get shared and
     * recycled, so a stranger's booking must neither take over a salon's
     * existing customer nor vanish from the booker's own dashboard.
     *
     * @param  array<string, mixed>  $input
     */
    protected function resolveCustomer(array $input, ?CustomerAccount $account): Customer
    {
        $phone = $input['phone'];

        if (! $account) {
            $customer = Customer::firstOrCreate(
                ['phone' => $phone],
                ['name' => $input['name'], 'email' => $input['email'] ?? null],
            );

            if ($customer->email && ! $customer->customer_account_id) {
                $accountId = CustomerAccount::whereNotNull('email_verified_at')
                    ->where('email', $customer->email)->value('id');
                if ($accountId) {
                    $customer->forceFill(['customer_account_id' => $accountId])->save();
                }
            }

            return $customer;
        }

        $name = $account->name ?: ($input['name'] ?? null);

        $customer = Customer::where('phone', $phone)
            ->where(fn ($query) => $query
                ->whereNull('customer_account_id')
                ->orWhere('customer_account_id', $account->id))
            ->first();

        $customer ??= Customer::create([
            'phone' => $phone,
            'name' => $name,
            'email' => $account->email,
        ]);

        if (! $customer->customer_account_id) {
            $customer->forceFill(['customer_account_id' => $account->id])->save();
        }

        // The account learns what it booked with, so the next wizard can
        // prefill and a nameless account stops asking.
        $backfill = array_filter([
            'name' => blank($account->name) ? $name : null,
            'phone' => blank($account->phone) ? $phone : null,
        ]);

        if ($backfill) {
            $account->forceFill($backfill)->save();
        }

        return $customer;
    }

    /**
     * View a booking by its public manage token (no auth). The token is
     * unguessable and tenant-scoped, so a wrong token yields a 404.
     */
    public function manage(string $org, string $token): JsonResponse
    {
        $appointment = $this->findByToken($token)
            ->load(['service', 'staff', 'branch', 'customer']);

        return response()->json(['data' => $this->bookingPayload($appointment)]);
    }

    /**
     * Move a booking to a new date/time. Re-checks the staff + branch hours
     * and conflicts (ignoring this appointment's own slot), so the customer
     * cannot reschedule onto an occupied or closed window.
     */
    public function reschedule(Request $request, string $org, string $token, SlotGenerator $slotGenerator, BookingNotifier $notifier): JsonResponse
    {
        $appointment = $this->findByToken($token);

        if (! $this->isChangeable($appointment)) {
            return response()->json(['message' => 'This booking can no longer be changed.'], 422);
        }

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
        ]);

        $appointment->loadMissing(['service', 'staff', 'branch']);
        $service = $appointment->service;

        $startTime = $this->scheduler->normalizeTime($data['start_time']);
        $endTime = $this->scheduler->deriveEndTime($data['start_time'], $service->duration);

        $open = $slotGenerator->generate($service, $appointment->staff, $data['date'], $appointment->branch, $appointment->id);
        if (! in_array(substr($startTime, 0, 5), $open, true)) {
            return response()->json(['message' => 'Sorry, that time slot is no longer available.'], 422);
        }

        $appointment->update([
            'booking_date' => $data['date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        $fresh = $appointment->fresh()->load(['service', 'staff', 'branch', 'customer']);
        $notifier->sendForReschedule($fresh);

        return response()->json(['data' => $this->bookingPayload($fresh)]);
    }

    /**
     * Cancel a booking. Only a still-active (pending/confirmed) booking can be
     * cancelled; a completed/cancelled/no-show one is left untouched.
     */
    public function cancel(string $org, string $token, BookingNotifier $notifier): JsonResponse
    {
        $appointment = $this->findByToken($token);

        if (! $this->isChangeable($appointment)) {
            return response()->json(['message' => 'This booking can no longer be changed.'], 422);
        }

        $appointment->update(['status' => AppointmentStatus::CANCELLED->value]);

        $fresh = $appointment->fresh()->load(['service', 'staff', 'branch', 'customer']);
        $notifier->sendForCancellation($fresh);

        return response()->json(['data' => $this->bookingPayload($fresh)]);
    }

    /**
     * Resolve an appointment by its public token within the bound tenant.
     * ModelNotFoundException (wrong token / wrong org) surfaces as a 404.
     */
    protected function findByToken(string $token): Appointment
    {
        return Appointment::where('public_token', $token)->firstOrFail();
    }

    /**
     * Whether a booking is still customer-editable (pending or confirmed).
     */
    protected function isChangeable(Appointment $appointment): bool
    {
        $status = $appointment->status instanceof AppointmentStatus
            ? $appointment->status
            : AppointmentStatus::from($appointment->status);

        return in_array($status, [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED], true);
    }

    /**
     * A finished visit — the only state a customer may review.
     */
    protected function isCompleted(Appointment $appointment): bool
    {
        $status = $appointment->status instanceof AppointmentStatus
            ? $appointment->status
            : AppointmentStatus::from($appointment->status);

        return $status === AppointmentStatus::COMPLETED;
    }

    /**
     * Public-facing shape of a booking, shared by book / manage / reschedule /
     * cancel. `changeable` tells the customer UI whether to offer edits.
     *
     * @return array<string, mixed>
     */
    protected function bookingPayload(Appointment $appointment): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $appointment->loadMissing('payments');

        $settings = PaymentSetting::query()->first();
        $depositRequired = (bool) $settings?->depositCollectable();

        // Reviewing is offered once the visit is completed and not yet reviewed.
        $review = Review::query()->where('appointment_id', $appointment->id)->first();

        return [
            'id' => $appointment->id,
            'public_token' => $appointment->public_token,
            'salon' => [
                'name' => $tenant?->name,
                'slug' => $tenant?->slug,
                // The manage page wears the salon's accent, same as the wizard.
                'theme_color' => $tenant
                    ? Setting::query()->where('organization_id', $tenant->id)->value('theme_color')
                    : null,
            ],
            'payment' => [
                'deposit_required' => $depositRequired,
                'amount_paid' => $appointment->amountPaid(),
                'amount_pending' => $appointment->amountPending(),
                'balance_due' => $appointment->balanceDue(),
            ],
            'date' => $appointment->booking_date->format('Y-m-d'),
            'start_time' => substr($appointment->start_time, 0, 5),
            'end_time' => substr($appointment->end_time, 0, 5),
            'status' => $appointment->status instanceof \BackedEnum
                ? $appointment->status->value
                : $appointment->status,
            'changeable' => $this->isChangeable($appointment),
            'can_review' => $review === null && $this->isCompleted($appointment),
            'review' => $review ? [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at,
            ] : null,
            'service' => $appointment->service ? [
                'id' => $appointment->service->id,
                'name' => $appointment->service->name,
                'duration' => $appointment->service->duration,
                'price' => $appointment->service->price,
            ] : null,
            'staff' => $appointment->staff ? [
                'id' => $appointment->staff->id,
                'name' => $appointment->staff->name,
            ] : null,
            'branch' => $appointment->branch ? [
                'id' => $appointment->branch->id,
                'name' => $appointment->branch->name,
            ] : null,
            'customer' => $appointment->customer ? [
                'id' => $appointment->customer->id,
                'name' => $appointment->customer->name,
            ] : null,
        ];
    }
}
