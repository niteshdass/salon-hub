<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Gallery;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo data (DemoSalonSeeder: a single, hand-authored "demo-salon"
        // org built on RegisterOrganization, for screenshots/sales demos/
        // manual QA) is opt-in and deliberately NOT invoked from here. Run
        // it explicitly with:
        //   php artisan db:seed --class=DemoSalonSeeder
        Organization::factory()->count(3)->create()->each(function (Organization $organization) {
            $this->seedTenant($organization);
        });
    }

    protected function seedTenant(Organization $organization): void
    {
        // Settings (one per org).
        Setting::factory()->for($organization)->create();

        // Primary domain.
        Domain::factory()->for($organization)->create([
            'domain' => $organization->slug.'.glowhub.com',
            'is_primary' => true,
            'is_verified' => true,
            'ssl_enabled' => true,
        ]);

        // Branch. Opening hours come from the factory default
        // (opening_hours_json) — the same column SlotGenerator and the
        // public site both read.
        $branch = Branch::factory()->for($organization)->create();

        // Owner + manager users.
        User::factory()->for($organization)->create([
            'branch_id' => $branch->id,
            'role' => UserRole::OWNER->value,
            'status' => 'active',
        ]);

        User::factory()->for($organization)->create([
            'branch_id' => $branch->id,
            'role' => UserRole::MANAGER->value,
            'status' => 'active',
        ]);

        // Staff users, each with a staff profile.
        $staffMembers = collect();
        for ($i = 0; $i < 5; $i++) {
            $staff = User::factory()->for($organization)->create([
                'branch_id' => $branch->id,
                'role' => UserRole::STAFF->value,
                'status' => 'active',
            ]);
            StaffProfile::factory()->for($staff)->create();
            $staffMembers->push($staff);
        }

        // Service categories.
        $categories = ServiceCategory::factory()->count(3)->for($organization)->create();

        // Services spread across those categories.
        $services = collect();
        for ($i = 0; $i < 8; $i++) {
            $services->push(
                Service::factory()->for($organization)->create([
                    'category_id' => $categories->random()->id,
                ])
            );
        }

        // Attach 2-5 random services to each staff member (staff_services pivot).
        $staffMembers->each(function (User $staff) use ($services) {
            $attach = $services->random(fake()->numberBetween(2, min(5, $services->count())))->pluck('id')->all();
            $staff->services()->syncWithoutDetaching($attach);
        });

        // Customers.
        $customers = Customer::factory()->count(20)->for($organization)->create();

        // Gallery.
        Gallery::factory()->count(6)->for($organization)->create();

        // Appointments: all references stay within this tenant.
        for ($i = 0; $i < 30; $i++) {
            Appointment::factory()->for($organization)->create([
                'branch_id' => $branch->id,
                'customer_id' => $customers->random()->id,
                'staff_id' => $staffMembers->random()->id,
                'service_id' => $services->random()->id,
            ]);
        }
    }
}
