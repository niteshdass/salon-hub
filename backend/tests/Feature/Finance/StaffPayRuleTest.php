<?php

namespace Tests\Feature\Finance;

use App\Models\StaffProfile;

class StaffPayRuleTest extends FinanceTestCase
{
    public function test_owner_can_set_a_hybrid_pay_rule_on_create(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $res = $this->withToken($this->token($owner))->postJson('/api/staff', [
            'name' => 'Rima',
            'email' => 'rima@acme.test',
            'pay_type' => 'hybrid',
            'monthly_salary' => 1000,
            'commission_rate' => 25,
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.pay_type', 'hybrid');
        $this->assertSame('1000.00', StaffProfile::firstWhere('user_id', $res->json('data.id'))->monthly_salary);
        $this->assertSame('25.00', StaffProfile::firstWhere('user_id', $res->json('data.id'))->commission_rate);
    }

    public function test_pay_type_defaults_to_none(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $res = $this->withToken($this->token($owner))
            ->postJson('/api/staff', ['name' => 'Rima', 'email' => 'rima@acme.test']);

        $res->assertCreated();
        $res->assertJsonPath('data.pay_type', 'none');
    }

    public function test_owner_can_update_a_pay_rule(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $staff = $this->makeStaff($org);

        $this->withToken($this->token($owner))
            ->patchJson("/api/staff/{$staff->id}", ['pay_type' => 'commission', 'commission_rate' => 30])
            ->assertOk()
            ->assertJsonPath('data.commission_rate', '30.00');
    }

    public function test_commission_rate_over_100_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $staff = $this->makeStaff($org);

        $this->withToken($this->token($owner))
            ->patchJson("/api/staff/{$staff->id}", ['pay_type' => 'commission', 'commission_rate' => 101])
            ->assertStatus(422)
            ->assertJsonValidationErrors('commission_rate');
    }

    public function test_manager_cannot_see_pay_fields(): void
    {
        $org = $this->makeOrg();
        $manager = $this->makeUser($org, 'manager');
        $staff = $this->makeStaff($org, ['pay_type' => 'salary', 'monthly_salary' => 900]);

        $res = $this->withToken($this->token($manager))->getJson("/api/staff/{$staff->id}");

        $res->assertOk();
        $res->assertJsonMissingPath('data.pay_type');
        $res->assertJsonMissingPath('data.monthly_salary');
        $res->assertJsonMissingPath('data.commission_rate');
    }

    public function test_manager_writing_pay_fields_is_ignored_not_rejected(): void
    {
        $org = $this->makeOrg();
        $manager = $this->makeUser($org, 'manager');
        $staff = $this->makeStaff($org);

        $this->withToken($this->token($manager))
            ->patchJson("/api/staff/{$staff->id}", ['designation' => 'Senior', 'monthly_salary' => 5000])
            ->assertOk();

        $profile = StaffProfile::firstWhere('user_id', $staff->id);
        $this->assertSame('Senior', $profile->designation);
        $this->assertSame('0.00', $profile->monthly_salary);
    }
}
