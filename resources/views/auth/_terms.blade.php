@if (\App\Support\Legal::required())
    <label class="ss-check" data-testid="terms-checkbox">
        <input type="checkbox" name="terms" value="1" required @checked(old('terms'))>
        <span>{!! __('auth.terms.label', [
            'terms' => '<a class="text-accent underline" href="'.e(\App\Support\Legal::termsUrl()).'" target="_blank" rel="noopener">'.e(__('auth.terms.terms')).'</a>',
            'privacy' => '<a class="text-accent underline" href="'.e(\App\Support\Legal::privacyUrl()).'" target="_blank" rel="noopener">'.e(__('auth.terms.privacy')).'</a>',
        ]) !!}</span>
    </label>
@endif
