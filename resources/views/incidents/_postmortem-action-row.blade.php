{{-- One editable action item (also used as the template for new rows). --}}
<div class="ss-doc-action-row">
    <label class="ss-doc-action-done"><input type="hidden" name="action_items[{{ $i }}][done]" value="0"><input type="checkbox" name="action_items[{{ $i }}][done]" value="1" @checked($item['done'] ?? false)><span class="sr-only">{{ __('postmortems.action_done') }}</span></label>
    <input class="ss-input" name="action_items[{{ $i }}][text]" value="{{ $item['text'] ?? '' }}" maxlength="300" aria-label="{{ __('postmortems.action_text') }}" placeholder="{{ __('postmortems.action_text') }}">
    <input class="ss-input" name="action_items[{{ $i }}][owner]" value="{{ $item['owner'] ?? '' }}" maxlength="100" aria-label="{{ __('postmortems.action_owner') }}" placeholder="{{ __('postmortems.action_owner') }}">
    <button type="button" class="ss-icon-btn" data-action-remove aria-label="{{ __('common.delete') }}"><svg class="ico"><use href="#i-x"/></svg></button>
</div>
