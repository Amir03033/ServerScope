<?php

namespace Tests\Feature;

use App\Monitoring\Dns\Resolver;
use App\Support\DashboardWidgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** Dashboard widgets: each person's own order, sizes and hidden widgets; the standard layout is the dashboard as it was. */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->app->instance(Resolver::class, new FakeResolver);
        $this->createTwoTeams();
    }

    private function order(string $html): array
    {
        preg_match_all('#data-widget="([a-z]+)"#', $html, $m);

        return $m[1];
    }

    public function test_the_standard_layout_is_the_dashboard_as_it_was(): void
    {
        $html = $this->actingAs($this->a['member'])->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(array_keys(DashboardWidgets::WIDGETS), $this->order($html));
        $this->assertStringNotContainsString('data-widget="samples" data-size="l" data-default-size="l"  hidden', $html);
    }

    public function test_a_person_saves_their_own_layout_and_others_keep_theirs(): void
    {
        $this->actingAs($this->a['member'])->putJson(route('dashboard.layout'), [
            'order' => ['servers', 'health', 'nonsense'],
            'sizes' => ['servers' => 'm', 'health' => 'xl'],
            'hidden' => ['samples'],
        ])->assertUnprocessable();

        $layout = $this->actingAs($this->a['member'])->putJson(route('dashboard.layout'), [
            'order' => ['servers', 'health'],
            'sizes' => ['servers' => 'm'],
            'hidden' => ['samples', 'kpis'],
        ])->assertOk()->json('layout');

        $this->assertSame(['servers', 'health'], array_slice($layout['order'], 0, 2));
        $this->assertCount(count(DashboardWidgets::WIDGETS), $layout['order'], 'every widget keeps a place');

        $html = $this->actingAs($this->a['member'])->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame(['servers', 'health'], array_slice($this->order($html), 0, 2));
        $this->assertMatchesRegularExpression('#data-widget="servers" data-size="m"#', $html);
        $this->assertMatchesRegularExpression('#data-widget="samples"[^>]*\shidden#', $html);

        // Another member of the same team keeps the standard layout.
        $other = $this->actingAs($this->a['admin'])->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame(array_keys(DashboardWidgets::WIDGETS), $this->order($other));
    }

    public function test_presets_and_reset(): void
    {
        $layout = $this->actingAs($this->a['member'])->putJson(route('dashboard.layout'), ['preset' => 'noc'])->assertOk()->json('layout');
        $this->assertSame('health', $layout['order'][0]);
        $this->assertContains('samples', $layout['hidden'], 'a preset hides what it does not list');

        $this->actingAs($this->a['member'])->deleteJson(route('dashboard.layout.reset'))->assertOk();
        $this->assertNull($this->a['member']->fresh()->dashboard_layout);
        $this->actingAs($this->a['member'])->putJson(route('dashboard.layout'), ['preset' => 'nope'])->assertUnprocessable();

        auth()->logout();
        $this->putJson(route('dashboard.layout'), ['preset' => 'noc'])->assertUnauthorized();
    }
}
