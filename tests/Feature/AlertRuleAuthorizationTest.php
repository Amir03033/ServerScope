<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertRuleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_view_alert_rules(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(route('alert-rules.index'))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_create_alert_rules(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->post(route('alert-rules.store'), [
                'name' => 'Unauthorized rule',
                'metric' => 'cpu_percent',
                'threshold' => 80,
                'operator' => '>=',
                'duration_minutes' => 1,
                'is_enabled' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('alert_rules', [
            'name' => 'Unauthorized rule',
        ]);
    }

    public function test_non_admin_cannot_update_alert_rules(): void
    {
        $rule = $this->createRule();

        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->put(route('alert-rules.update', $rule), [
                'name' => 'Changed rule',
                'metric' => 'cpu_percent',
                'threshold' => 90,
                'operator' => '>=',
                'duration_minutes' => 1,
                'is_enabled' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('alert_rules', [
            'id' => $rule->id,
            'name' => $rule->name,
        ]);
    }

    public function test_non_admin_cannot_delete_alert_rules(): void
    {
        $rule = $this->createRule();

        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->delete(route('alert-rules.destroy', $rule))
            ->assertForbidden();

        $this->assertDatabaseHas('alert_rules', [
            'id' => $rule->id,
            'deleted_at' => null,
        ]);
    }

    private function createRule(): AlertRule
    {
        return AlertRule::create([
            'name' => 'Test rule',
            'metric' => 'cpu_percent',
            'threshold' => 80,
            'operator' => '>=',
            'duration_minutes' => 1,
            'is_enabled' => true,
        ]);
    }
}
