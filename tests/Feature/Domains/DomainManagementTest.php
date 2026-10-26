<?php

namespace Tests\Feature\Domains;

use App\Jobs\RunDomainCheck;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DomainManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->viewer = User::factory()->create(['is_admin' => false]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'https://Shop.Example.com/winkel',
            'scheme' => 'https',
            'path' => '/status',
            'check_interval_minutes' => 5,
            'timeout_seconds' => 10,
            'failure_threshold' => 3,
            'recovery_threshold' => 2,
            'expected_status_codes' => '200-299, 301',
            'expected_content' => 'Welkom',
            'follow_redirects' => '1',
            'http_enabled' => '1',
            'ssl_enabled' => '1',
            'dns_enabled' => '1',
            'expiry_enabled' => '1',
            'ssl_warning_days' => '7, 30, 14',
            'expiry_warning_days' => '30,14,7,3,1',
            ...$overrides,
        ];
    }

    public function test_admin_adds_a_domain_and_initial_checks_are_queued(): void
    {
        $this->actingAs($this->admin)->post(route('domains.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $domain = Domain::query()->sole();

        $this->assertSame('shop.example.com', $domain->name);
        $this->assertSame('/status', $domain->path);
        $this->assertSame('200-299,301', $domain->expected_status_codes);
        $this->assertSame([30, 14, 7], $domain->sslWarningDays());
        $this->assertSame($this->admin->id, $domain->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.created']);

        foreach (['http', 'ssl', 'dns', 'expiry'] as $type) {
            Queue::assertPushed(RunDomainCheck::class, fn (RunDomainCheck $job) => $job->domainId === $domain->id && $job->type === $type);
        }
    }

    public function test_internationalized_domains_are_stored_as_punycode_and_shown_in_unicode(): void
    {
        $this->actingAs($this->admin)->post(route('domains.store'), $this->payload(['name' => 'bücher.de']))->assertSessionHasNoErrors();

        $domain = Domain::query()->sole();
        $this->assertSame('xn--bcher-kva.de', $domain->name);
        $this->assertSame('bücher.de', $domain->displayName());

        $this->actingAs($this->viewer)->get(route('domains.show', $domain))->assertOk()->assertSee('bücher.de');
    }

    public function test_duplicates_are_rejected_including_deleted_domains(): void
    {
        $existing = Domain::create(['name' => 'example.com']);

        $this->actingAs($this->admin)->post(route('domains.store'), $this->payload(['name' => 'EXAMPLE.com.']))
            ->assertSessionHasErrors(['name' => 'Dit domein wordt al gemonitord.']);

        $existing->delete();

        $this->actingAs($this->admin)->post(route('domains.store'), $this->payload(['name' => 'example.com']))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Domain::withTrashed()->count());
    }

    public function test_validation_rejects_unsafe_or_invalid_configuration(): void
    {
        $this->actingAs($this->admin)->post(route('domains.store'), $this->payload([
            'name' => 'http://169.254.169.254/',
            'check_interval_minutes' => 0,
            'timeout_seconds' => 120,
            'expected_status_codes' => '2xx',
            'ssl_warning_days' => '0, 500',
            'path' => '/a b',
        ]))->assertSessionHasErrors(['name', 'check_interval_minutes', 'timeout_seconds', 'expected_status_codes', 'ssl_warning_days.0', 'path']);

        foreach (['localhost', 'printer.local', 'intranet', '10.0.0.1'] as $name) {
            $this->actingAs($this->admin)->post(route('domains.store'), $this->payload(['name' => $name]))->assertSessionHasErrors('name');
        }

        $this->assertSame(0, Domain::query()->count());
    }

    public function test_http_scheme_disables_tls_checks(): void
    {
        $this->actingAs($this->admin)->post(route('domains.store'), $this->payload(['scheme' => 'http']))->assertSessionHasNoErrors();

        $this->assertFalse(Domain::query()->sole()->ssl_enabled);
        Queue::assertNotPushed(RunDomainCheck::class, fn ($job) => $job->type === 'ssl');
    }

    public function test_minimum_interval_is_configurable(): void
    {
        config(['serverscope.monitoring.min_interval_minutes' => 5]);

        $this->actingAs($this->admin)->post(route('domains.store'), $this->payload(['check_interval_minutes' => 1]))
            ->assertSessionHasErrors('check_interval_minutes');
    }

    public function test_viewers_can_view_but_not_manage(): void
    {
        $domain = Domain::create(['name' => 'example.com']);

        $this->actingAs($this->viewer)->get(route('domains.index'))->assertOk()->assertSee('example.com')->assertDontSee(route('domains.create'));
        $this->actingAs($this->viewer)->get(route('domains.show', $domain))->assertOk();

        $this->actingAs($this->viewer)->get(route('domains.create'))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('domains.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->viewer)->put(route('domains.update', $domain), $this->payload())->assertForbidden();
        $this->actingAs($this->viewer)->post(route('domains.pause', $domain))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('domains.check', $domain))->assertForbidden();
        $this->actingAs($this->viewer)->delete(route('domains.destroy', $domain))->assertForbidden();

        $this->assertNotSoftDeleted($domain);
        $this->assertTrue($domain->fresh()->is_active);
    }

    public function test_update_with_new_name_resets_previous_results(): void
    {
        $domain = Domain::create(['name' => 'old.example.com']);
        $domain->forceFill(['ssl_status' => 'valid', 'ssl_issuer' => 'X', 'status' => 'healthy', 'expiry_status' => 'verified'])->save();
        $domain->dnsSnapshots()->create(['record_type' => 'A', 'records' => ['1.1.1.1'], 'hash' => str_repeat('a', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->actingAs($this->admin)->put(route('domains.update', $domain), $this->payload(['name' => 'new.example.com']))
            ->assertRedirect(route('domains.show', $domain));

        $domain->refresh();
        $this->assertSame('new.example.com', $domain->name);
        $this->assertSame('unknown', $domain->ssl_status);
        $this->assertNull($domain->ssl_issuer);
        $this->assertSame(0, $domain->dnsSnapshots()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.updated']);
    }

    public function test_pause_resume_and_check_now(): void
    {
        $domain = Domain::create(['name' => 'example.com']);

        $this->actingAs($this->admin)->post(route('domains.pause', $domain))->assertRedirect();
        $this->assertFalse($domain->fresh()->is_active);
        $this->assertSame('paused', $domain->fresh()->displayStatus());

        Queue::fake();
        $this->actingAs($this->admin)->post(route('domains.resume', $domain))->assertRedirect();
        $this->assertTrue($domain->fresh()->is_active);
        Queue::assertPushed(RunDomainCheck::class);

        Queue::fake();
        $this->actingAs($this->admin)->post(route('domains.check', $domain))->assertSessionHas('success');
        Queue::assertPushed(RunDomainCheck::class, fn ($job) => $job->type === 'http');
        Queue::assertNotPushed(RunDomainCheck::class, fn ($job) => $job->type === 'expiry');

        // Rate limited to once per minute.
        $this->actingAs($this->admin)->post(route('domains.check', $domain))->assertSessionHas('error');
    }

    public function test_delete_restore_and_permanent_delete(): void
    {
        $domain = Domain::create(['name' => 'example.com']);
        $domain->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'checked_at' => now()]);

        $this->actingAs($this->admin)->delete(route('domains.destroy', $domain))->assertRedirect(route('domains.index'));
        $this->assertSoftDeleted($domain);
        $this->assertSame(1, $domain->checks()->count());

        $this->actingAs($this->admin)->get(route('domains.show', $domain))->assertOk()->assertSee('Dit domein is verwijderd');

        $this->actingAs($this->admin)->post(route('domains.restore', $domain))->assertRedirect(route('domains.show', $domain));
        $this->assertNotSoftDeleted($domain);

        $domain->delete();
        $this->actingAs($this->admin)->delete(route('domains.force-destroy', $domain), ['confirm_name' => 'nope'])->assertSessionHasErrors('confirm_name');
        $this->actingAs($this->admin)->delete(route('domains.force-destroy', $domain), ['confirm_name' => 'example.com'])->assertRedirect();

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
        $this->assertDatabaseMissing('domain_checks', ['domain_id' => $domain->id]);
    }

    public function test_index_filters_and_search(): void
    {
        Domain::create(['name' => 'healthy.example.com'])->forceFill(['status' => 'healthy'])->save();
        Domain::create(['name' => 'broken.example.com'])->forceFill(['status' => 'critical'])->save();
        Domain::create(['name' => 'paused.example.com', 'is_active' => false]);

        $ids = fn ($response) => $response->viewData('domains')->pluck('name')->all();

        $this->assertSame(['broken.example.com'], $ids($this->actingAs($this->viewer)->get(route('domains.index', ['status' => 'critical']))));
        $this->assertSame(['paused.example.com'], $ids($this->actingAs($this->viewer)->get(route('domains.index', ['status' => 'paused']))));
        $this->assertSame(['healthy.example.com'], $ids($this->actingAs($this->viewer)->get(route('domains.index', ['q' => 'healthy']))));
        // Critical first by default.
        $this->assertSame('broken.example.com', $ids($this->actingAs($this->viewer)->get(route('domains.index')))[0]);
    }

    public function test_notification_preference_and_availability_target_are_saved(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->actingAs($this->admin)->post(route('domains.store'), $this->payload(['notifications_enabled' => '0', 'sla_target' => '99,5']))->assertRedirect();
        $domain = Domain::query()->firstOrFail();
        $this->assertFalse($domain->notifications_enabled);
        $this->assertSame(99.5, $domain->sla_target);

        $this->actingAs($this->admin)->put(route('domains.update', $domain), $this->payload(['sla_target' => '120']))->assertSessionHasErrors('sla_target');
        $this->actingAs($this->admin)->put(route('domains.update', $domain), $this->payload(['notifications_enabled' => '1', 'sla_target' => '']))->assertRedirect();
        $this->assertTrue($domain->fresh()->notifications_enabled);
        $this->assertNull($domain->fresh()->sla_target);
    }
}
