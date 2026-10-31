<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Team;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(): View
    {
        Gate::authorize('admin');

        return view('billing.invoices', [
            'team' => Team::query()->findOrFail(Tenant::id()),
            'invoices' => Invoice::query()->where('team_id', Tenant::id())->orderByDesc('id')->get(),
        ]);
    }

    /** Printable page (print to PDF from the browser). Drafts are not shown. */
    public function show(Invoice $invoice): View
    {
        Gate::authorize('admin');
        abort_unless($invoice->team_id === Tenant::id() && $invoice->isIssued(), 404);

        return view('billing.invoice', ['invoice' => $invoice]);
    }

    public function details(Request $request): RedirectResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'country' => ['required', 'string', 'size:2'],
            'vat_id' => ['nullable', 'string', 'max:30'],
        ]);

        Team::query()->findOrFail(Tenant::id())->forceFill(['billing_details' => [...$data, 'country' => strtoupper($data['country'])]])->save();

        return back()->with('success', __('billing.invoices.details_saved'));
    }
}
