<x-layouts.app :title="__('billing.invoices.title')">
    <form method="POST" action="{{ route('billing.details') }}" class="ss-card grid gap-4 p-5">
        @csrf @method('PUT')
        <h2 class="m-0 text-lg font-semibold">{{ __('billing.invoices.details') }}</h2>
        <p class="m-0 text-sm text-muted">{{ __('billing.invoices.details_hint') }}</p>
        @php($d = $team->billing_details ?? [])
        <div><label class="ss-label" for="d-name">{{ __('billing.invoices.name') }}</label><input id="d-name" class="ss-input mt-1.5" name="name" required maxlength="150" value="{{ old('name', $d['name'] ?? $team->name) }}"></div>
        <div><label class="ss-label" for="d-address">{{ __('billing.invoices.address') }}</label><textarea id="d-address" class="ss-input mt-1.5" name="address" rows="3" required maxlength="500">{{ old('address', $d['address'] ?? '') }}</textarea></div>
        <div><label class="ss-label" for="d-country">{{ __('billing.invoices.country') }}</label><input id="d-country" class="ss-input mt-1.5" name="country" required maxlength="2" placeholder="NL" value="{{ old('country', $d['country'] ?? '') }}"></div>
        <div><label class="ss-label" for="d-vat">{{ __('billing.invoices.vat_id') }}</label><input id="d-vat" class="ss-input mt-1.5" name="vat_id" maxlength="30" value="{{ old('vat_id', $d['vat_id'] ?? '') }}"></div>
        <div><button type="submit" class="ss-btn ss-btn-primary">{{ __('billing.invoices.save') }}</button></div>
    </form>

    <div class="ss-card mt-5 p-5">
        <h2 class="m-0 text-lg font-semibold">{{ __('billing.invoices.title') }}</h2>
        @forelse ($invoices as $invoice)
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3 text-sm">
                <span>{{ $invoice->isIssued() ? $invoice->number : __('billing.invoices.draft') }} · {{ $invoice->paid_at->format('Y-m-d') }} · {{ __('plans.names.'.$invoice->plan) }}</span>
                <span>{{ \App\Models\Invoice::money($invoice->amount_incl, $invoice->currency) }}
                    @if ($invoice->isIssued()) · <a class="text-accent" href="{{ route('billing.invoice', $invoice) }}">{{ __('billing.invoices.view') }}</a>@endif</span>
            </div>
        @empty
            <p class="mt-3 text-sm text-muted">{{ __('billing.invoices.none') }}</p>
        @endforelse
    </div>
</x-layouts.app>
