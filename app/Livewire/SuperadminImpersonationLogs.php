<?php

namespace App\Livewire;

use App\Models\ImpersonationLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only viewer for the impersonation audit trail.
 *
 * Rows are never editable or deletable from the UI — an audit log you can edit
 * answers no questions.
 */
class SuperadminImpersonationLogs extends Component
{
    use WithPagination;

    public string $searchUser = '';

    public string $filterAction = '';

    public string $filterSession = '';

    public bool $mutationsOnly = false;

    public int $perPage = 25;

    /** @var list<string> */
    public array $actions = [
        'start',
        'stop',
        'write_mode_enabled',
        'write_mode_disabled',
        'created',
        'updated',
        'deleted',
    ];

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['searchUser', 'filterAction', 'filterSession', 'mutationsOnly']);
        $this->resetPage();
    }

    /**
     * Narrow the trail to a single impersonation session.
     */
    public function showSession(string $sessionUuid): void
    {
        $this->filterSession = $sessionUuid;
        $this->resetPage();
    }

    public function getLogsProperty(): LengthAwarePaginator
    {
        return ImpersonationLog::query()
            ->with(['impersonator:id,name', 'impersonated:id,name', 'team:id,name'])
            ->when($this->filterSession !== '', fn (Builder $q) => $q->where('session_uuid', $this->filterSession))
            ->when($this->filterAction !== '', fn (Builder $q) => $q->where('action', $this->filterAction))
            ->when($this->mutationsOnly, fn (Builder $q) => $q->whereIn('action', ['created', 'updated', 'deleted']))
            ->when($this->searchUser !== '', function (Builder $q) {
                $term = '%'.$this->searchUser.'%';

                // Grouped so the OR cannot escape the other filters above.
                $q->where(function (Builder $inner) use ($term) {
                    $inner->whereHas('impersonator', fn (Builder $u) => $u->where('name', 'like', $term))
                        ->orWhereHas('impersonated', fn (Builder $u) => $u->where('name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate($this->perPage);
    }

    public function render()
    {
        return view('livewire.superadmin-impersonation-logs', [
            'logs' => $this->logs,
        ]);
    }
}
