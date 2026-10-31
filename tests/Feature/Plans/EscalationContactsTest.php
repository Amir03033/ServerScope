<?php

namespace Tests\Feature\Plans;

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentNotification;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EscalationContactsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Team $team, string $role = 'owner'): User
    {
        $u = User::factory()->create();
        $u->teams()->attach($team->id, ['role' => $role]);
        $u->forceFill(['current_team_id' => $team->id, 'is_admin' => $role !== 'member'])->save();

        return $u;
    }

    public function test_admin_saves_up_to_five_valid_addresses_for_the_own_team_only(): void
    {
        $a = Team::query()->create(['name' => 'A']);
        $b = Team::query()->create(['name' => 'B']);
        $admin = $this->admin($a);

        $this->actingAs($admin)->put(route('team.escalation'), ['escalation_emails' => "Oncall@Example.test, dev@example.test\noncall@example.test"])->assertSessionHasNoErrors();
        $this->assertSame(['oncall@example.test', 'dev@example.test'], $a->fresh()->escalation_emails);
        $this->assertNull($b->fresh()->escalation_emails);

        $this->actingAs($admin)->get(route('users.index'))->assertOk()->assertSee('dev@example.test');

        $this->actingAs($admin)->put(route('team.escalation'), ['escalation_emails' => 'geen-email'])->assertSessionHasErrors();
        $this->actingAs($admin)->put(route('team.escalation'), ['escalation_emails' => 'a@x.test b@x.test c@x.test d@x.test e@x.test f@x.test'])->assertSessionHasErrors();
        $this->assertSame(['oncall@example.test', 'dev@example.test'], $a->fresh()->escalation_emails);

        $this->actingAs($admin)->put(route('team.escalation'), ['escalation_emails' => ''])->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->escalation_emails);
    }

    public function test_plain_members_cannot_change_them(): void
    {
        $a = Team::query()->create(['name' => 'A']);
        $this->actingAs($this->admin($a, 'member'))->put(route('team.escalation'), ['escalation_emails' => 'x@example.test'])->assertForbidden();
    }

    public function test_an_escalation_reaches_only_the_contacts_of_its_own_team(): void
    {
        Notification::fake();
        config(['serverscope.escalation.after_minutes' => ['critical' => 15], 'serverscope.escalation.emails' => ['platform@example.test']]);

        $a = Team::query()->create(['name' => 'A']);
        $a->forceFill(['escalation_emails' => ['a-oncall@example.test']])->save();
        $b = Team::query()->create(['name' => 'B']);
        $b->forceFill(['escalation_emails' => ['b-oncall@example.test']])->save();
        $server = Server::forceCreate(['team_id' => $a->id, 'name' => 'a-server', 'hostname' => 'a.example.test']);
        app(IncidentManager::class)->trigger(new Signal('esc:a', 'manual', 'critical', 'Storing A', null, $server));

        $this->travel(16)->minutes();
        $this->artisan('serverscope:escalate-incidents')->assertSuccessful();

        // Exactly one extra recipient: the contact of team A. Not team B's, not the platform list.
        $recipients = \App\Models\NotificationLog::withoutGlobalScopes()->whereNotNull('recipient')->pluck('recipient')->all();
        $this->assertSame(['a-oncall@example.test'], $recipients);
        Notification::assertSentOnDemandTimes(IncidentNotification::class, 1);
    }
}
