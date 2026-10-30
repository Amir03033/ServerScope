{{-- Status and error messages on the guest pages. --}}
@if (session('status'))
    <div class="ss-alert ss-alert-success mt-5" role="status"><svg class="ico"><use href="#i-check"/></svg><div>{{ session('status') }}</div></div>
@endif
@if (session('error'))
    <div class="ss-alert ss-alert-error mt-5" role="alert"><svg class="ico"><use href="#i-alert"/></svg><div>{{ session('error') }}</div></div>
@endif
@if ($errors->any())
    <div class="ss-alert ss-alert-error mt-5" role="alert"><svg class="ico"><use href="#i-alert"/></svg><div>{{ $errors->first() }}</div></div>
@endif
