{{-- A password field with a show/hide toggle; $meter adds the strength meter (registration). --}}
<div class="ss-auth-field">
    <label class="ss-label" for="{{ $id }}">{{ $label }}</label>
    <div class="ss-password mt-1.5">
        <input id="{{ $id }}" class="ss-input" type="password" name="{{ $name ?? $id }}" autocomplete="{{ $autocomplete }}" required @if ($meter ?? false) data-strength @endif @error($name ?? $id) aria-invalid="true" @enderror>
        <button type="button" class="ss-password-toggle" data-password-toggle aria-controls="{{ $id }}" aria-pressed="false"
                data-label-show="{{ __('auth.password_show') }}" data-label-hide="{{ __('auth.password_hide') }}" aria-label="{{ __('auth.password_show') }}">
            <svg class="ico" data-eye="show"><use href="#i-eye"/></svg>
            <svg class="ico" data-eye="hide" hidden><use href="#i-eye-off"/></svg>
        </button>
    </div>
    @if ($meter ?? false)
        <div class="ss-strength" data-strength-meter data-labels='@json(__('auth.strength'))' aria-live="polite">
            <span class="ss-strength-bar"><i></i><i></i><i></i><i></i></span>
            <span class="ss-strength-text"></span>
        </div>
    @endif
</div>
