<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BusinessHour;
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
            'domain' => $organization->slug . '.salonhub.com',
            'is_primary' => true,
            'is_verified' => true,
            'ssl_enabled' => true,
        ]);

        // Branch.
        $branch = Branch::factory()->for($organization)->create();

        // Business hours: weekday 0..6 with one or two closed days.
        $closedDays = fake()->randomElements([0, 1, 2, 3, 4, 5, 6], fake()->numberBetween(1, 2));
        foreach (range(0, 6) as $weekday) {
            $isClosed = in_array($weekday, $closedDays, true);
            BusinessHour::factory()->for($branch)->create([
                'weekday' => $weekday,
                'open_time' => $isClosed ? null : '09:00:00',
                'close_time' => $isClosed ? null : '18:00:00',
                'is_closed' => $isClosed,
            ]);
        }

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
