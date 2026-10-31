<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApiTokenController extends Controller
{
    public function index(Request $request): View
    {
        return view('api-tokens.index', [
            'tokens' => $request->user()->hasMany(ApiToken::class)->latest('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! \App\Support\PlanLimits::allows('api_tokens')) {
            return back()->with('error', __('plans.feature.api_tokens'));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(array_keys(ApiToken::ABILITIES))],
            'expires_in_days' => ['nullable', 'integer', Rule::in([30, 90, 365])],
        ]);

        $active = ApiToken::query()->where('user_id', $request->user()->id)->whereNull('revoked_at')->count();
        abort_if($active >= 20, 422, 'Maximaal 20 actieve tokens per gebruiker.');

        $abilities = array_unique(['read', ...$validated['abilities']]);
        [$token, $plain] = ApiToken::issue(
            $request->user(),
            $validated['name'],
            $abilities,
            isset($validated['expires_in_days']) ? now()->addDays((int) $validated['expires_in_days']) : null,
        );

        Audit::log('api_token.created', "API-token '{$token->name}' aangemaakt", $request->user(), ['token_id' => $token->id, 'abilities' => $token->abilities]);

        return redirect()->route('api-tokens.index')->with('success', __('api_tokens.flash.created'))->with('api_token', $plain);
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        abort_unless($apiToken->user_id === $request->user()->id, 404);

        $apiToken->forceFill(['revoked_at' => now()])->save();
        Audit::log('api_token.revoked', "API-token '{$apiToken->name}' ingetrokken", $request->user(), ['token_id' => $apiToken->id]);

        return back()->with('success', __('api_tokens.flash.revoked'));
    }
}
