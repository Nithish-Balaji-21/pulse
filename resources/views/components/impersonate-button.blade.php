@props(['user'])

{{-- Mirrors Impersonation::reasonCannotImpersonate() so the button only shows
     where the POST would actually succeed. The service is still the authority. --}}
@can('impersonate-users')
    @if (! $user->is_superadmin && $user->is_active && $user->id !== auth()->id())
        <form method="POST" action="{{ route('impersonate.start', $user->id) }}" class="inline-flex shrink-0 m-0">
            @csrf
            <button type="submit"
                    title="View CoiioT as {{ $user->name }} (starts read-only)"
                    class="impersonate-btn btn-xs">
                <i class="fa-solid fa-user-secret"></i>Impersonate
            </button>
        </form>
    @endif
@endcan
