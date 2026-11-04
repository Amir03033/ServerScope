<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Postmortem;
use App\Services\Incidents\PostmortemBuilder;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Postmortem of an incident: one click from Incident Memory. Team members
 * read and write it; team administrators share it through a public,
 * read-only link (and can stop sharing at any time). Markdown export and
 * a print layout (save as PDF) are available to the team.
 */
class PostmortemController extends Controller
{
    public function show(Incident $incident, PostmortemBuilder $builder): View
    {
        $postmortem = $incident->postmortem;

        return view('incidents.postmortem', [
            'pm' => $builder->build($incident, $postmortem),
            'postmortem' => $postmortem,
            'incident' => $incident,
        ]);
    }

    public function update(Request $request, Incident $incident): RedirectResponse
    {
        $v = $request->validate([
            'summary' => ['nullable', 'string', 'max:5000'],
            'impact' => ['nullable', 'string', 'max:5000'],
            'went_well' => ['nullable', 'string', 'max:5000'],
            'went_wrong' => ['nullable', 'string', 'max:5000'],
            'action_items' => ['nullable', 'array', 'max:'.Postmortem::MAX_ACTION_ITEMS],
            'action_items.*.text' => ['nullable', 'string', 'max:300'],
            'action_items.*.owner' => ['nullable', 'string', 'max:100'],
            'action_items.*.done' => ['nullable', 'boolean'],
        ]);

        $postmortem = $incident->postmortem ?? new Postmortem(['incident_id' => $incident->id]);
        $postmortem->fill([...$v, 'action_items' => collect($v['action_items'] ?? [])
            ->filter(fn ($item) => trim((string) ($item['text'] ?? '')) !== '')
            ->map(fn ($item) => ['text' => trim($item['text']), 'owner' => trim((string) ($item['owner'] ?? '')) ?: null, 'done' => (bool) ($item['done'] ?? false)])
            ->values()->all()]);
        $postmortem->forceFill(['updated_by' => $request->user()->id])->save();

        Audit::log('postmortem.saved', "Nabeschouwing van incident #{$incident->id} opgeslagen", $postmortem);

        return redirect()->route('incidents.postmortem', $incident)->with('success', __('postmortems.saved'));
    }

    public function share(Incident $incident): RedirectResponse
    {
        Gate::authorize('admin');

        $postmortem = $incident->postmortem ?? new Postmortem(['incident_id' => $incident->id]);
        $postmortem->forceFill(['share_token' => $postmortem->share_token ?? Str::random(48), 'shared_at' => now()])->save();
        Audit::log('postmortem.shared', "Nabeschouwing van incident #{$incident->id} gedeeld via een link", $postmortem);

        return redirect()->route('incidents.postmortem', $incident)->with('success', __('postmortems.share.on'));
    }

    public function unshare(Incident $incident): RedirectResponse
    {
        Gate::authorize('admin');

        if ($postmortem = $incident->postmortem) {
            $postmortem->forceFill(['share_token' => null, 'shared_at' => null])->save();
            Audit::log('postmortem.unshared', "Deellink van de nabeschouwing van incident #{$incident->id} ingetrokken", $postmortem);
        }

        return redirect()->route('incidents.postmortem', $incident)->with('success', __('postmortems.share.off'));
    }

    public function markdown(Incident $incident, PostmortemBuilder $builder): Response
    {
        $markdown = view('incidents.postmortem-markdown', ['pm' => $builder->build($incident, $incident->postmortem)])->render();

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="postmortem-incident-'.$incident->id.'.md"',
        ]);
    }

    /** The shared, read-only page. Unknown and revoked links look the same. */
    public function public(string $token, PostmortemBuilder $builder): Response
    {
        $postmortem = Postmortem::query()->withoutGlobalScopes()->where('share_token', $token)->first();
        abort_if($postmortem === null, 404);

        return Tenant::run($postmortem->team_id, function () use ($postmortem, $builder) {
            $incident = Incident::query()->findOrFail($postmortem->incident_id);

            return response()->view('incidents.postmortem-public', ['pm' => $builder->build($incident, $postmortem)])
                ->header('X-Robots-Tag', 'noindex, nofollow')
                ->header('Referrer-Policy', 'no-referrer');
        });
    }
}
