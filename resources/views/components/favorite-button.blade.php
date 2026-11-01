@props(['type', 'id', 'active' => false])

<form method="POST" action="{{ route('favorites.toggle') }}" class="inline" data-favorite-form>
    @csrf
    <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="id" value="{{ $id }}">
    <button type="submit" class="ss-fav" aria-pressed="{{ $active ? 'true' : 'false' }}" aria-label="{{ __($active ? 'favorites.remove' : 'favorites.add') }}" title="{{ __($active ? 'favorites.remove' : 'favorites.add') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="{{ $active ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/></svg>
    </button>
</form>
