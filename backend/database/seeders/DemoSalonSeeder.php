<?php

namespace Database\Seeders;

use App\Actions\Auth\RegisterOrganization;
use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A populated salon for demos, screenshots and manual QA.
 *
 * Built on RegisterOrganization so the demo salon is byte-identical to a
 * real signup — if registration stops producing a bookable salon, this
 * seeder stops producing one too, and we find out immediately.
 *
 * Opt-in only: run explicitly with
 *   php artisan db:seed --class=DemoSalonSeeder
 * Never wired into DatabaseSeeder::run() — see the comment there.
 *
 * Idempotent: users.email is globally unique, so a second run without an
 * intervening migrate:fresh would otherwise die on a raw SQLSTATE[23000]
 * for demo@salonhub.com. Re-running is a normal workflow (refresh the demo
 * data without wiping the rest of a shared dev database), so this seeder
 * deletes any previous demo-salon organization first rather than requiring
 * migrate:fresh every time. Every tenant-scoped table's organization_id
 * foreign key is declared ->cascadeOnDelete() (see database/migrations/
 * 2026_07_22_100002_create_branches_table.php and siblings), and
 * staff_profiles/staff_services cascade from users.id in turn, so deleting
 * the Organization row is normally sufficient.
 *
 * That cascade only fires when MySQL is actually enforcing foreign keys.
 * Verified against a real local MySQL instance: `foreign_key_checks` can be
 * OFF at the *global* default (observed here — nothing in this codebase
 * sets it; it's server/session state, not app config), in which case
 * `DELETE FROM organizations` silently succeeds while every child row
 * (users included) is left orphaned, and the very next insert of
 * demo@salonhub.com collides again. `SET FOREIGN_KEY_CHECKS=1` is scoped to
 * this seeder's own DB connection/session only — it does not touch the
 * server's global setting or any other connection — so it's safe to force
 * on before the delete without side effects elsewhere.
 *
 * Destructive by design (it deletes a whole organization on re-run — see
 * above) and therefore refuses to run anywhere but `local`/`testing`. It
 * also never trusts the `demo-salon` slug alone to identify "the org I'm
 * allowed to delete": `demo-salon` is not in Organization::RESERVED_SLUGS,
 * so RegisterOrganization::uniqueSlug() hands that exact slug to the first
 * real customer who names their salon "Demo Salon" — matching on slug
 * would delete a genuine tenant's data with no recovery path (no model in
 * this app uses SoftDeletes).
 *
 * IMPORTANT — the owner's email alone is not unambiguous either, contrary
 * to what an earlier version of this comment claimed. users.email is
 * globally unique, but that only says *a* user holds demo@salonhub.com; it
 * does not say that user is the owner of a *seeder-created* organization.
 * Verified against real data both ways: (1) a real tenant can register
 * with demo@salonhub.com as its own owner under a completely different
 * salon name/slug, and (2) StoreStaffRequest validates email uniqueness
 * globally too, so any real organization's *staff* member can hold that
 * address while someone else owns the org. Either case, blindly deleting
 * "whoever has that email" destroys a real tenant.
 *
 * So identifying "our" previous org requires BOTH: the demo@salonhub.com
 * user must be that organization's OWNER (not staff/manager), AND the
 * organization's slug must match what RegisterOrganization::uniqueSlug()
 * actually produces for "Demo Salon" — `demo-salon`, or `demo-salon-2`,
 * `-3`, ... if an earlier run lost the base slug to a real tenant that
 * registered first. Anything else is presumed to be someone else's data:
 * resolvePreviousDemoOrganization() refuses to guess and the whole run
 * aborts with an actionable message instead of deleting the mismatch or
 * limping on into RegisterOrganization::execute()'s inevitable duplicate-
 * email failure.
 */
class DemoSalonSeeder extends Seeder
{
    /**
     * The exact slug shape RegisterOrganization::uniqueSlug() produces for
     * "Demo Salon": the base slug, or the base with a numeric collision
     * suffix if a real tenant already held it on an earlier run.
     */
    private const DEMO_SLUG_PATTERN = '/^demo-salon(-\d+)?$/';

    public function run(): void
    {
        // Thrown, not $this->command->error()+exit(1): a plain exception
        // renders as a clear message under Artisan (verified: no raw
        // SQLSTATE-style trace, non-zero exit code) without depending on
        // $this->command being bound — it is null when a seeder is
        // invoked programmatically rather than via the console — and
        // without hard-killing the process, which would abort a parent
        // seeder's own cleanup if this were ever reached via $this->call().
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'DemoSalonSeeder refused to run: APP_ENV is "'.app()->environment().'", '.
                'not "local" or "testing". This seeder can delete an organization it '.
                'recognizes as its own previous run, which is only safe on a disposable '.
                'database. If this really is one, set APP_ENV=local and run again.'
            );
        }

        $existing = $this->resolvePreviousDemoOrganization();

        if ($existing) {
            $this->command?->warn("Removing previous demo salon (organization #{$existing->id}, slug \"{$existing->slug}\") before reseeding.");

            // Force FK enforcement ON for this connection only, so the
            // cascadeOnDelete() constraints declared in the migrations
            // actually fire. See the class docblock: relying on the
            // server's ambient foreign_key_checks default is not safe.
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            $existing->delete();
        }

        $result = app(RegisterOrganization::class)->execute([
            'salon_name' => 'Demo Salon',
            'name' => 'Dana Demo',
            'email' => 'demo@salonhub.com',
            'password' => 'password',
            'phone' => '+8801700000000',
            'country' => 'BD',
            'timezone' => 'Asia/Dhaka',
            'currency' => 'BDT',
        ]);

        $org = $result['organization'];
        $owner = $result['user'];

        // A demo login must not nag its own visitor to verify an email
        // nobody is going to check. RegisterOrganization sends no
        // verification mail itself (that lives in AuthController for a real
        // signup), so the address just needs marking — there is nothing to
        // actually verify here.
        $owner->markEmailAsVerified();

        $branch = Branch::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();

        $hair = ServiceCategory::create(['organization_id' => $org->id, 'name' => 'Hair']);
        $skin = ServiceCategory::create(['organization_id' => $org->id, 'name' => 'Skin']);

        $services = collect([
            ['Hair Cut', $hair->id, 30, 500],
            ['Hair Colour', $hair->id, 90, 3500],
            ['Facial', $skin->id, 60, 1800],
            ['Manicure', $skin->id, 45, 900],
        ])->map(fn (array $row) => Service::create([
            'organization_id' => $org->id,
            'category_id' => $row[1],
            'name' => $row[0],
            'duration' => $row[2],
            'price' => $row[3],
            'status' => 'active',
        ]));

        // Working Monday(1)–Saturday(6), matching the branch's own opening
        // hours (RegisterOrganization::DEFAULT_OPENING_HOURS is mon–sat,
        // closed Sunday) so the intersection SlotGenerator computes is never
        // empty on a day the branch is open.
        $workingDays = [1, 2, 3, 4, 5, 6];
        $workingHours = ['start' => '09:00', 'end' => '18:00'];

        $staff = collect(['Alia Rahman', 'Bipul Das', 'Chandni Roy'])->map(function (string $name) use ($org, $branch, $services, $workingDays, $workingHours) {
            $user = User::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'name' => $name,
                'email' => str($name)->slug().'@demo.salonhub.com',
                'password' => Hash::make('password'),
                'role' => 'staff',
                'status' => 'active',
            ]);

            StaffProfile::create([
                'user_id' => $user->id,
                'designation' => 'Stylist',
                // working_days_json: ISO weekday integers 1 (Mon) - 7 (Sun),
                // per StoreStaffRequest/UpdateStaffRequest's validation
                // contract and how SlotGenerator::generate() reads it
                // (Carbon::dayOfWeekIso compared with array_map('intval', ...)).
                'working_days_json' => $workingDays,
                // working_hours_json: a keyed start/end map, not a pair —
                // SlotGenerator reads $hours['start'] / $hours['end'].
                'working_hours_json' => $workingHours,
            ]);

            // Every stylist can perform every service, so any demo booking
            // path finds an available staff member.
            $user->services()->sync($services->pluck('id')->all());

            return $user;
        });

        $customers = collect(range(1, 12))->map(fn (int $i) => Customer::create([
            'organization_id' => $org->id,
            'name' => "Demo Customer {$i}",
            'phone' => '+88017000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'email' => "customer{$i}@demo.test",
        ]));

        // 30 appointments across the last and next fortnight, statuses mixed
        // so the dashboard, calendar and reports all have something to show.
        $statuses = [
            AppointmentStatus::COMPLETED->value,
            AppointmentStatus::CONFIRMED->value,
            AppointmentStatus::PENDING->value,
            AppointmentStatus::CANCELLED->value,
            AppointmentStatus::NO_SHOW->value,
        ];

        foreach (range(0, 29) as $i) {
            $date = Carbon::today()->addDays($i - 14);
            $service = $services[$i % $services->count()];
            $hour = 9 + ($i % 8);

            Appointment::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'customer_id' => $customers[$i % $customers->count()]->id,
                'staff_id' => $staff[$i % $staff->count()]->id,
                'service_id' => $service->id,
                'booking_date' => $date->toDateString(),
                'start_time' => sprintf('%02d:00:00', $hour),
                'end_time' => sprintf('%02d:%02d:00', $hour + intdiv($service->duration, 60), $service->duration % 60),
                'price' => $service->price,
                // Past dates get settled statuses, future dates get open ones.
                'status' => $date->isPast() ? $statuses[$i % 5] : $statuses[($i % 2) + 1],
            ]);
        }

        $this->command?->info("Demo salon ready: demo@salonhub.com / password (slug: {$org->slug})");
    }

    /**
     * Find the organization created by this seeder's own previous run, if
     * any — see the class docblock for why the demo@salonhub.com email
     * alone cannot answer this.
     *
     * Returns null only when there is genuinely nothing to clean up: no
     * user holds demo@salonhub.com yet (first-ever run). If that address
     * is taken but the owner-role-plus-slug check fails, this throws
     * instead of returning null — silently reporting "nothing found" would
     * let run() sail on into RegisterOrganization::execute()'s inevitable
     * duplicate-email failure a few lines later, with no explanation of
     * why or what was actually found.
     */
    protected function resolvePreviousDemoOrganization(): ?Organization
    {
        $user = User::where('email', 'demo@salonhub.com')->first();

        if (! $user) {
            return null;
        }

        $org = $user->organization;
        $looksLikeOurs = $user->role === UserRole::OWNER
            && $org !== null
            && preg_match(self::DEMO_SLUG_PATTERN, $org->slug) === 1;

        if (! $looksLikeOurs) {
            throw new RuntimeException(
                'DemoSalonSeeder refused to run: a user already exists with email '.
                'demo@salonhub.com'.
                ($org
                    ? " (role \"{$user->role->value}\" in organization #{$org->id}, slug \"{$org->slug}\")"
                    : ' (no organization)'
                ).
                ", but it does not look like this seeder's own previous run — the ".
                'owner role and the "demo-salon"[-N] slug must both match before it is '.
                'safe to delete. Nothing was changed. If this is genuinely stale demo '.
                'data, remove it manually and run this seeder again.'
            );
        }

        return $org;
    }
}
