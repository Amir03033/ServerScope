<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\User;
use App\Services\Incidents\IncidentMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rebuilt incident, alert, alert rule and dependency screens: the status
 * bar, no inline code, the shared confirmation dialog and both languages.
 */
class IncidentPagesTest extends TestCase
{
    use RefreshDatabase;

    private function incident(string $status = 'acknowledged'): Incident
    {
        return Incident::query()->create([
            'fingerprint' => 'manual:test', 'source' => 'manual', 'severity' => 'high', 'status' => $status,
            'title' => 'Database traag', 'opened_at' => now()->subHour(), 'last_occurrence_at' => now(),
        ]);
    }

    private function rule(): AlertRule
    {
        return AlertRule::query()->create(['name' => 'CPU hoog', 'metric' => 'cpu_percent', 'threshold' => 85, 'operator' => '>=', 'severity' => 'high', 'duration_minutes' => 5, 'is_enabled' => true]);
    }

    public function test_the_status_bar_marks_the_current_step_and_offers_the_next_actions(): void
    {
        $incident = $this->incident('acknowledged');

        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('incidents.show', $incident))->assertOk()->getContent();

        preg_match('/<ol class="ss-progress[^"]*"[^>]*>(.*?)<\/ol>/s', $html, $bar);
        $this->assertNotEmpty($bar);
        $this->assertSame(5, substr_count($bar[1], '<li'));
        $this->assertSame(1, substr_count($bar[1], 'aria-current="step"'));
        $this->assertMatchesRegularExpression('/aria-current="step"\s*>Bevestigd</', $bar[1]);
        $this->assertSame(1, substr_count($bar[1], 'is-done'));

        // The possible transition and the resolve form are both there.
        $this->assertStringContainsString('name="status" value="investigating"', $html);
        $this->assertStringContainsString('id="resolve"', $html);
        $this->assertStringContainsString('href="#resolve"', $html);
        $this->assertStringNotContainsString('name="status" value="acknowledged"', $html);
    }

    public function test_the_screens_are_english_by_default(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'locale' => 'en']);
        $incident = $this->incident('open');
        $this->rule();
        app()->setLocale('en');

        $this->actingAs($user)->get(route('incidents.index'))->assertOk()
            ->assertSee(['Report incident', 'Incident frequency', 'Not acknowledged'])
            ->assertDontSee(['Incident melden', 'Incidentfrequentie', 'Niet bevestigd']);
        $this->actingAs($user)->get(route('incidents.show', $incident))->assertOk()
            ->assertSee(['Timeline', 'Acknowledge', 'Add note', 'Assignment'])
            ->assertDontSee(['Tijdlijn', 'Notitie toevoegen', 'Toewijzing']);
        $this->actingAs($user)->get(route('incidents.create'))->assertOk()->assertSee('Create incident')->assertDontSee('Incident aanmaken');
        $this->actingAs($user)->get(route('alerts.index'))->assertOk()->assertSee(['Alert history', 'No active alerts'])->assertDontSee('Alertgeschiedenis');
        $this->actingAs($user)->get(route('alert-rules.index'))->assertOk()->assertSee(['Alert rules', 'Required duration'])->assertDontSee('Vereiste duur');
        $this->actingAs($user)->get(route('alert-rules.create'))->assertOk()->assertSee(['New alert rule', 'Alert when CPU usage ≥ 85% for 1 minutes'])->assertDontSee('Nieuwe alertregel');
        $this->actingAs($user)->get(route('dependencies.index'))->assertOk()->assertSee(['Add dependency', 'Confirmed dependencies'])->assertDontSee('Afhankelijkheid toevoegen');
    }

    public function test_the_live_preview_reads_the_rule_back_in_dutch(): void
    {
        $rule = $this->rule();

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('alert-rules.edit', $rule))->assertOk()
            ->assertSee('Alert als CPU-gebruik ≥ 85% gedurende 5 minuten');
    }

    public function test_an_alert_rule_can_be_switched_off_through_the_form(): void
    {
        $rule = $this->rule();
        $admin = User::factory()->create(['is_admin' => true]);

        // The form sends is_enabled=0 when the box is unchecked (the controller requires the field).
        $this->actingAs($admin)->get(route('alert-rules.edit', $rule))->assertOk()
            ->assertSee('<input type="hidden" name="is_enabled" value="0">', false);

        $this->actingAs($admin)->put(route('alert-rules.update', $rule), [
            'name' => 'CPU hoog', 'metric' => 'cpu_percent', 'threshold' => 85, 'operator' => '>=', 'duration_minutes' => 5, 'is_enabled' => '0',
        ])->assertRedirect(route('alert-rules.index'))->assertSessionHasNoErrors();

        $this->assertFalse($rule->fresh()->is_enabled);
    }

    public function test_deleting_an_alert_rule_asks_through_the_shared_dialog_with_the_same_text(): void
    {
        $this->rule();

        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('alert-rules.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('onsubmit=', $html);
        $this->assertStringContainsString('data-confirm="Weet je zeker dat je deze alertregel en de bijbehorende alertgeschiedenis wilt verwijderen?"', $html);
    }

    public function test_the_screens_have_no_inline_scripts_or_styles(): void
    {
        $incident = $this->incident();
        $rule = $this->rule();
        $admin = User::factory()->create(['is_admin' => true]);

        foreach ([route('incidents.index'), route('incidents.show', $incident), route('incidents.create'), route('alerts.index'), route('alert-rules.index'), route('alert-rules.create'), route('alert-rules.edit', $rule), route('dependencies.index')] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $main = substr($html, strpos($html, '<main'));

            $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $main, "Inline style on {$url}");
            $this->assertDoesNotMatchRegularExpression('/<style/', $html, "Style block on {$url}");
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*type="application\/json")[^>]*>/', $main, "Inline script on {$url}");
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $main, "Inline handler on {$url}");
        }
    }

    public function test_durations_follow_the_language(): void
    {
        app()->setLocale('nl');
        $nl = IncidentMetrics::humanDuration(3 * 3600 + 120);
        app()->setLocale('en');
        $en = IncidentMetrics::humanDuration(3 * 3600 + 120);

        $this->assertSame('3u 2min', $nl);
        $this->assertSame('3h 2m', $en);
    }
}
