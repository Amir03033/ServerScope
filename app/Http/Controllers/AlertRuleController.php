<?php

namespace App\Http\Controllers;

use App\Models\AlertRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlertRuleController extends Controller
{
    public function index(): View
    {
        $this->authorizeAdmin();

        $rules = AlertRule::query()
            ->withCount('alerts')
            ->orderBy('name')
            ->paginate(15);

        return view('alert-rules.index', compact('rules'));
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        return view('alert-rules.create', [
            'rule' => new AlertRule(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateRule($request);

        AlertRule::create($validated);

        return redirect()
            ->route('alert-rules.index')
            ->with('success', 'Alertregel aangemaakt.');
    }

    public function edit(AlertRule $alertRule): View
    {
        $this->authorizeAdmin();

        return view('alert-rules.edit', [
            'rule' => $alertRule,
        ]);
    }

    public function update(
        Request $request,
        AlertRule $alertRule,
    ): RedirectResponse {
        $this->authorizeAdmin();

        $validated = $this->validateRule($request);

        $alertRule->update($validated);

        return redirect()
            ->route('alert-rules.index')
            ->with('success', 'Alertregel bijgewerkt.');
    }

    public function destroy(AlertRule $alertRule): RedirectResponse
    {
        $this->authorizeAdmin();

        if ($alertRule->alerts()->exists()) {
            return redirect()
                ->route('alert-rules.index')
                ->with(
                    'error',
                    'Deze alertregel heeft meldingsgeschiedenis en kan daarom niet worden verwijderd. Schakel de regel uit als je deze niet meer wilt gebruiken.'
                );
        }

        $alertRule->delete();

        return redirect()
            ->route('alert-rules.index')
            ->with('success', 'Alertregel verwijderd.');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(
            auth()->check() && (bool) auth()->user()->is_admin,
            403,
            'Je hebt geen toestemming om alertregels te beheren.',
        );
    }

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'metric' => [
                'required',
                Rule::in([
                    'cpu_percent',
                    'memory_percent',
                    'disk_percent',
                ]),
            ],
            'threshold' => ['required', 'numeric', 'between:0,100'],
            'operator' => [
                'required',
                Rule::in(['>=', '>', '<=', '<']),
            ],
            'duration_minutes' => [
                'required',
                'integer',
                'between:1,1440',
            ],
            'is_enabled' => ['required', 'boolean'],
        ]);
    }
}
