{{-- Compact EN | NL switch (guest pages and public status pages). POSTs to locale.update. --}}
@php $current = app()->getLocale(); @endphp
<form method="POST" action="{{ route('locale.update') }}" {{ $attributes->merge(['class' => 'ss-segmented']) }} data-no-loading aria-label="{{ __('locale.label') }}">
    @csrf
    @foreach (\App\Support\Locale::SUPPORTED as $code)
        <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}" class="uppercase !px-2.5 !py-1" aria-pressed="{{ $code === $current ? 'true' : 'false' }}" title="{{ __('locale.names.'.$code) }}">{{ $code }}</button>
    @endforeach
</form>
