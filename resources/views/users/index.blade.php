@php
    use App\Support\Time;
    use App\Tenancy\Tenancy;
@endphp

<x-layouts.app :title="__('admin.users.title')">
    <x-slot:subtitle>{{ __('admin.users.subtitle') }}</x-slot:subtitle>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-start">
        <section class="ss-panel">
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('admin.users.col_user') }}</th><th scope="col">{{ __('admin.users.col_role') }}</th><th scope="col">{{ __('admin.users.col_2fa') }}</th><th scope="col">{{ __('admin.users.col_notifications') }}</th><th scope="col"><span class="sr-only">{{ __('admin.users.col_actions') }}</span></th></tr></thead>
                    <tbody>
                        @foreach ($users as $user)
                            @php $teamAdmin = in_array($user->team_role, Tenancy::ADMIN_ROLES, true); @endphp
                            <tr>
                                <td class="is-primary"><strong>{{ $user->name }}</strong>@if ($user->is(auth()->user())) <span class="ss-chip">{{ __('admin.users.you') }}</span>@endif<span class="block text-xs text-muted [overflow-wrap:anywhere]">{{ __('admin.users.since', ['email' => $user->email, 'date' => Time::date($user->created_at)]) }}</span></td>
                                <td data-label="{{ __('admin.users.col_role') }}"><x-status :status="$teamAdmin ? 'info' : 'unknown'" :label="$user->team_role === 'owner' ? __('admin.users.owner') : ($teamAdmin ? __('admin.users.admin') : __('admin.users.member'))" /></td>
                                <td data-label="{{ __('admin.users.col_2fa') }}"><x-status :status="$user->hasTwoFactorEnabled() ? 'enabled' : 'disabled'" :label="$user->hasTwoFactorEnabled() ? __('admin.users.on') : __('admin.users.off')" /></td>
                                <td data-label="{{ __('admin.users.col_notifications') }}" class="text-sm">{{ collect([__('admin.users.channel_email') => $user->notify_email, __('admin.users.channel_in_app') => $user->notify_in_app])->filter()->keys()->implode(', ') ?: '—' }}</td>
                                <td class="is-actions">
                                    @unless ($user->is(auth()->user()))
                                        <div class="flex flex-wrap justify-end gap-1.5">
                                            <form method="POST" action="{{ route('users.update', $user) }}">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="is_admin" value="{{ $teamAdmin ? 0 : 1 }}">
                                                <button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ $teamAdmin ? __('admin.users.make_member') : __('admin.users.make_admin') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('users.destroy', $user) }}" data-confirm="{{ __('admin.users.delete_confirm', ['email' => $user->email]) }}">
                                                @csrf @method('DELETE')
                                                <button class="ss-btn ss-btn-danger ss-btn-sm" type="submit">{{ __('admin.users.delete') }}</button>
                                            </form>
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($invitations->isNotEmpty())
            <section class="ss-panel" aria-labelledby="pending-title" data-testid="pending-invitations">
                <h2 id="pending-title" class="ss-panel-title">{{ __('admin.users.pending_title') }}</h2>
                <div class="grid gap-3 p-4">
                    @foreach ($invitations as $invitation)
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span><strong>{{ $invitation->email }}</strong> · {{ $invitation->role === 'admin' ? __('admin.users.is_admin') : __('admin.users.role_member') }} · {{ __('admin.users.pending_expires', ['date' => $invitation->expires_at->format('Y-m-d')]) }}</span>
                            <span class="flex gap-2">
                                <form method="POST" action="{{ route('users.invitations.resend', $invitation) }}">@csrf<button type="submit" class="ss-btn">{{ __('admin.users.pending_resend') }}</button></form>
                                <form method="POST" action="{{ route('users.invitations.revoke', $invitation) }}">@csrf @method('DELETE')<button type="submit" class="ss-btn">{{ __('admin.users.pending_revoke') }}</button></form>
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="ss-panel" aria-labelledby="invite-title">
            <h2 id="invite-title" class="ss-panel-title">{{ __('admin.users.invite_title') }}</h2>
            <p class="ss-panel-sub mb-4">{{ __('admin.users.invite_text') }}</p>
            <form method="POST" action="{{ route('users.store') }}" class="grid gap-4">
                @csrf
                <div>
                    <label class="ss-label" for="name">{{ __('admin.users.name') }}</label>
                    <input id="name" class="ss-input mt-1.5" name="name" required maxlength="255" value="{{ old('name') }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name')<p id="name-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="ss-label" for="email">{{ __('admin.users.email') }}</label>
                    <input id="email" class="ss-input mt-1.5" name="email" type="email" required maxlength="255" value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<p id="email-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
                <label class="ss-check"><input type="hidden" name="is_admin" value="0"><input type="checkbox" name="is_admin" value="1"> {{ __('admin.users.is_admin') }}</label>
                <div><button class="ss-btn ss-btn-primary" type="submit">{{ __('admin.users.invite') }}</button></div>
            </form>
        </section>
    </div>
</x-layouts.app>
