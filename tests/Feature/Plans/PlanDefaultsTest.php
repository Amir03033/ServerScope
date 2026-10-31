<?php

namespace Tests\Feature\Plans;

use Tests\TestCase;

/** The agreed numbers per plan, read from the configuration file itself. */
class PlanDefaultsTest extends TestCase
{
    public function test_free_limits_are_the_agreed_ones(): void
    {
        $plans = (require config_path('serverscope.php'))['plans'];

        $this->assertSame([
            'servers' => 1, 'domains' => 3, 'pages_per_website' => 15,
            'status_pages' => 1, 'min_interval_minutes' => 5, 'retention_days' => 14,
        ], $plans['free']['limits']);
        $this->assertSame([], $plans['free']['features']);
    }

    public function test_paid_plans_include_more_than_free_in_every_limit(): void
    {
        $plans = (require config_path('serverscope.php'))['plans'];

        foreach (['servers', 'domains', 'pages_per_website', 'status_pages'] as $limit) {
            $this->assertGreaterThan($plans['free']['limits'][$limit], $plans['pro']['limits'][$limit], $limit);
            $this->assertGreaterThanOrEqual($plans['pro']['limits'][$limit], $plans['premium']['limits'][$limit], $limit);
        }
    }
}
