<?php

namespace Tests\Feature;

use App\Models\IncidentEvent;
use App\Models\IncidentLearning;
use App\Models\Postmortem;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** Postmortem: one click from Incident Memory, editable, shareable through a read-only link. */
class PostmortemTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTwoTeams();
    }

    public function test_it_is_built_from_what_is_recorded_and_opened_from_incident_memory(): void
    {
        $incident = $this->a['incident'];
        Tenant::run($this->a['team'], function () use ($incident) {
            $learning = new IncidentLearning;
            $learning->forceFill(['incident_id' => $incident->id, 'cause_category' => 'deployment', 'cause_status' => 'confirmed', 'root_cause' => 'alpha migratie vergrendelde de tabel', 'resolution_steps' => 'alpha rollback', 'outcome' => 'successful'])->save();
            IncidentEvent::query()->create(['incident_id' => $incident->id, 'type' => 'comment', 'message' => 'alpha interne notitie']);
        });

        $this->actingAs($this->a['member'])->get(route('incidents.show', $incident))->assertOk()->assertSee(route('incidents.postmortem', $incident));

        $this->get(route('incidents.postmortem', $incident))->assertOk()
            ->assertSee('alpha migratie vergrendelde de tabel')
            ->assertSee('alpha rollback')
            ->assertSee(__('postmortems.sections.timeline'))
            ->assertDontSee('alpha interne notitie')
            ->assertDontSee('bravo');

        $this->actingAs($this->b['member'])->get(route('incidents.postmortem', $incident))->assertNotFound();
    }

    public function test_members_write_the_summary_lessons_and_action_items(): void
    {
        $incident = $this->a['incident'];

        $this->actingAs($this->a['member'])->put(route('incidents.postmortem.update', $incident), [
            'summary' => 'Checkout was down for 12 minutes.',
            'went_well' => 'Alert within a minute.',
            'went_wrong' => 'Runbook out of date.',
            'action_items' => [
                ['text' => 'Update the runbook', 'owner' => 'Amir', 'done' => '1'],
                ['text' => '', 'owner' => 'nobody'],
                ['text' => 'Add a canary', 'done' => '0'],
            ],
        ])->assertRedirect(route('incidents.postmortem', $incident));

        $postmortem = Postmortem::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->a['team']->id, $postmortem->team_id);
        $this->assertSame([
            ['text' => 'Update the runbook', 'owner' => 'Amir', 'done' => true],
            ['text' => 'Add a canary', 'owner' => null, 'done' => false],
        ], $postmortem->actionItems());

        $this->get(route('incidents.postmortem', $incident))->assertSee('Checkout was down for 12 minutes.')->assertSee('Update the runbook');

        $markdown = $this->get(route('incidents.postmortem.markdown', $incident))->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')->getContent();
        $this->assertStringContainsString('- [x] Update the runbook (Amir)', $markdown);
        $this->assertStringContainsString('- [ ] Add a canary', $markdown);
    }

    public function test_an_admin_shares_a_read_only_link_and_can_stop_sharing(): void
    {
        $incident = $this->a['incident'];

        $this->actingAs($this->a['member'])->post(route('incidents.postmortem.share', $incident))->assertForbidden();
        $this->actingAs($this->a['admin'])->post(route('incidents.postmortem.share', $incident))->assertRedirect();

        $url = Postmortem::query()->withoutGlobalScopes()->sole()->publicUrl();
        auth()->logout();

        $this->get($url)->assertOk()->assertSee($incident->title)->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee(route('incidents.show', $incident))->assertDontSee('bravo');

        $this->actingAs($this->a['admin'])->delete(route('incidents.postmortem.unshare', $incident))->assertRedirect();
        auth()->logout();
        $this->get($url)->assertNotFound();
        $this->get('/postmortems/'.str_repeat('a', 48))->assertNotFound();
    }
}
