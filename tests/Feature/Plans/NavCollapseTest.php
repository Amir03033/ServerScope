<?php

namespace Tests\Feature\Plans;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavCollapseTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_groups_are_collapsible_and_open_by_default(): void
    {
        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/dashboard')->assertOk()->getContent();

        foreach (['monitoring', 'incidents', 'reporting', 'admin', 'account'] as $group) {
            $this->assertMatchesRegularExpression('/<details class="ss-nav-section" data-nav-group="'.$group.'" open>/', $html);
        }
        // The dashboard link itself is not in a collapsible group.
        $this->assertStringNotContainsString('data-nav-group=""', $html);
    }
}
