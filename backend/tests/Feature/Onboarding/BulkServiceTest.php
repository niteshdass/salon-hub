<?php

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: string}
     */
    private function makeOrgWithOwner(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $owner = User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        return [$org, $owner->createToken('api')->plainTextToken];
    }

    public function test_it_creates_every_row_under_one_new_category(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [
                ['name' => 'Hair cut', 'duration' => 30, 'price' => 12.5],
                ['name' => 'Hair colour', 'duration' => 90, 'price' => 40],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data');

        $this->assertSame(1, ServiceCategory::where('organization_id', $org->id)->count());
        $categoryId = ServiceCategory::where('organization_id', $org->id)->value('id');

        $this->assertDatabaseHas('services', [
            'organization_id' => $org->id,
            'category_id' => $categoryId,
            'name' => 'Hair cut',
            'duration' => 30,
            'status' => 'active',
        ]);
    }

    public function test_a_second_call_for_the_same_type_reuses_the_category(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');
        $payload = [
            'category' => 'Hair salon',
            'rows' => [['name' => 'Trim', 'duration' => 20, 'price' => 5]],
        ];

        $this->withToken($token)->postJson('/api/services/bulk', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/services/bulk', $payload)->assertCreated();

        $this->assertSame(1, ServiceCategory::where('organization_id', $org->id)->count());
    }

    public function test_a_row_without_a_price_fails_and_nothing_is_written(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [
                ['name' => 'Hair cut', 'duration' => 30, 'price' => 12.5],
                ['name' => 'Hair colour', 'duration' => 90],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.1.price');
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('service_categories', 0);
    }

    /**
     * The test above proves validation runs before the transaction is ever
     * entered — it does not prove the transaction rolls anything back, since
     * FormRequest rejects that payload before bulkStore() runs at all. This
     * test forces the failure *inside* the transaction body instead: row one
     * is valid and would be written first, row two carries a price that
     * clears `numeric|min:0` validation but exceeds what services.price
     * (decimal(10,2), 8 integer digits) can store, so it throws at the
     * database layer only after row one — and the category — already exist
     * in the (uncommitted) transaction.
     *
     * SQLite has no precision/scale enforcement on a NUMERIC-affinity
     * column — the same insert silently succeeds there (verified directly
     * against this schema) — so this failure is only observable on a real
     * MySQL connection, which is exactly why this repo runs a dedicated
     * backend-mysql CI job alongside the default SQLite one (see
     * DatabaseDriverTest). Skipped rather than faked when the suite is not
     * connected to MySQL.
     */
    public function test_a_row_that_overflows_the_price_column_rolls_back_the_whole_menu(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'services.price is decimal(10,2); only MySQL enforces that range '
                .'at the database layer, so only there does this row fail after '
                .'row one is already written inside the transaction. SQLite has '
                .'no such enforcement — see backend-mysql in ci.yml.'
            );
        }

        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [
                ['name' => 'Hair cut', 'duration' => 30, 'price' => 12.5],
                ['name' => 'Overflow', 'duration' => 30, 'price' => 100000000],
            ],
        ]);

        $response->assertServerError();
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('service_categories', 0);
    }

    /**
     * StepServices.vue displays a validation error's message verbatim to a
     * non-technical salon owner. Laravel's default message for a rule that
     * has no custom override renders the raw attribute path, e.g. "The
     * rows.0.price field must be at least 0." — meaningless, and a raw field
     * key, to that owner. `attributes()` on the request must map every
     * `rows.*` field to a human noun so every rule (not just the two with
     * custom messages()) reads in plain language.
     */
    public function test_a_negative_price_is_explained_in_plain_language_not_as_a_field_path(): void
    {
        [, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [
                ['name' => 'Hair cut', 'duration' => 30, 'price' => -5],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.0.price');

        $message = $response->json('errors')['rows.0.price'][0];
        $this->assertStringNotContainsString('rows.', $message);
    }

    public function test_it_rejects_an_empty_row_list(): void
    {
        [, $token] = $this->makeOrgWithOwner('alpha');

        $this->withToken($token)
            ->postJson('/api/services/bulk', ['category' => 'Hair salon', 'rows' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows');
    }

    public function test_a_staff_member_may_not_create_a_menu(): void
    {
        [$org] = $this->makeOrgWithOwner('alpha');
        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Ruma',
            'email' => 'ruma@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->withToken($staff->createToken('api')->plainTextToken)
            ->postJson('/api/services/bulk', [
                'category' => 'Hair salon',
                'rows' => [['name' => 'Trim', 'duration' => 20, 'price' => 5]],
            ])
            ->assertForbidden();
    }

    /**
     * Both organizations post under the identical category label
     * ("Hair salon") — the exact condition that risks firstOrCreate()
     * matching across tenants if the lookup ever loses its organization_id
     * clause. Eloquent queries on ServiceCategory/Service are avoided here
     * because BelongsToOrganization's global scope would auto-filter them
     * to whichever tenant the *last* request bound, which would silently
     * mask a cross-tenant leak instead of proving its absence; the raw
     * assertDatabase* helpers and DB facade bypass that scope entirely.
     */
    public function test_one_salons_menu_never_lands_in_another(): void
    {
        [$orgA, $tokenA] = $this->makeOrgWithOwner('alpha');
        [$orgB, $tokenB] = $this->makeOrgWithOwner('bravo');

        $this->withToken($tokenA)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [['name' => 'Trim', 'duration' => 20, 'price' => 5]],
        ])->assertCreated();

        // Sanctum's RequestGuard memoizes the resolved user within a single
        // process; several requests sharing one app instance (as here) need
        // the guard reset before the second token or it re-authenticates as
        // org A. See AppointmentCrudTest::test_index_date_filter_and_tenant_isolation
        // for the same idiom.
        $this->app['auth']->forgetGuards();
        $this->withToken($tokenB)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [['name' => 'Blow Dry', 'duration' => 25, 'price' => 8]],
        ])->assertCreated();

        // firstOrCreate() must not have reused org A's category for org B.
        $this->assertDatabaseCount('service_categories', 2);
        $this->assertDatabaseHas('service_categories', ['organization_id' => $orgA->id, 'name' => 'Hair salon']);
        $this->assertDatabaseHas('service_categories', ['organization_id' => $orgB->id, 'name' => 'Hair salon']);

        $categoryA = DB::table('service_categories')->where('organization_id', $orgA->id)->value('id');
        $categoryB = DB::table('service_categories')->where('organization_id', $orgB->id)->value('id');
        $this->assertNotSame($categoryA, $categoryB);

        $this->assertDatabaseHas('services', [
            'organization_id' => $orgA->id,
            'category_id' => $categoryA,
            'name' => 'Trim',
        ]);
        $this->assertDatabaseHas('services', [
            'organization_id' => $orgB->id,
            'category_id' => $categoryB,
            'name' => 'Blow Dry',
        ]);

        $this->assertDatabaseMissing('services', ['organization_id' => $orgA->id, 'name' => 'Blow Dry']);
        $this->assertDatabaseMissing('services', ['organization_id' => $orgB->id, 'name' => 'Trim']);
    }
}
