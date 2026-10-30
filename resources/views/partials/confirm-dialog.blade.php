{{-- Confirmation for forms with data-confirm (same text as before). Falls back to window.confirm. --}}
<dialog class="ss-dialog ss-dialog-sm" data-confirm-dialog aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-text">
    <form method="dialog" class="p-5">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-2 text-bad"><svg class="ico h-[18px] w-[18px]"><use href="#i-alert"/></svg></span>
            <div class="min-w-0">
                <h2 id="confirm-dialog-title" class="m-0 text-base font-semibold text-fg">{{ __('common.confirm_title') }}</h2>
                <p id="confirm-dialog-text" class="m-0 mt-1.5 text-sm text-muted" data-confirm-text></p>
            </div>
        </div>
        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <button type="submit" value="cancel" class="ss-btn ss-btn-secondary" autofocus>{{ __('common.cancel') }}</button>
            <button type="submit" value="confirm" class="ss-btn ss-btn-danger" data-confirm-button>{{ __('common.confirm') }}</button>
        </div>
    </form>
</dialog>
