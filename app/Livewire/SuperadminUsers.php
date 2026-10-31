<?php

namespace App\Livewire;

use App\Actions\Jetstream\DeleteUser;
use App\Mail\UserUpdatedMail;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;
use Livewire\WithPagination;

class SuperadminUsers extends Component
{
    use WithPagination;

    public $showModal = false;

    public $editMode = false;

    public $user_id;

    public $name;

    public $email;

    public $mobile;

    public $status = 1;

    public $password;

    public $password_confirmation;

    public $search = '';

    public $searchEmail = '';

    public $searchMobile = '';

    public $searchStatus = '';

    public $perPage = 25;

    public ?int $confirmingUserId = null;

    public bool $showDeleteConfirmationDialog = false;

    public $sortCol = null;

    public $sortDir = 'asc';

    public $viewMode = 'normal';

    public $selectedFactoryIds = [];   // multi-select: array of team IDs

    public $factoryDropdownOpen = false;

    public $factorySearchTerm = '';    // kept for backwards-compat; not used in factory view

    /** When navigating from allfactories page, open factory view pre-filtered to one factory */
    public ?int $focusedFactoryId = null;

    // Per-factory search state keyed by factory ID string
    public $factoryFilters = [];       // ['1' => ['name'=>'', 'email'=>'', 'mobile'=>'', 'status'=>''], ...]

    // Per-factory sort state
    public $factorySortCol = [];  // ['1' => 'role']

    public $factorySortDir = [];  // ['1' => 'asc']

    public function mount(): void
    {
        // If arriving from allfactories with ?factory=ID, auto-open factory view
        if ($teamId = request()->query('factory')) {
            $this->focusedFactoryId = (int) $teamId;
            $this->selectedFactoryIds = [(int) $teamId];
            $this->viewMode = 'factory';
        }
    }

    public function doSearch()
    {
        $this->resetPage();
    }

    public function getFactoriesProperty()
    {
        return Team::orderBy('name', 'asc')->get();
    }

    public function sortFactoryBy(string $factoryId, string $col): void
    {
        if (($this->factorySortCol[$factoryId] ?? null) === $col) {
            $this->factorySortDir[$factoryId] = ($this->factorySortDir[$factoryId] ?? 'asc') === 'asc' ? 'desc' : 'asc';
        } else {
            $this->factorySortCol[$factoryId] = $col;
            $this->factorySortDir[$factoryId] = 'asc';
        }
    }

    public function clearFactoryFocus(): void
    {
        $this->focusedFactoryId = null;
        $this->selectedFactoryIds = [];
    }

    /** Return factories to display (all or filtered by selectedFactoryIds), each with per-factory role-grouped users. */
    public function getFactoryUsersProperty(): array
    {
        $factories = $this->factories;
        if (! empty($this->selectedFactoryIds)) {
            $factories = $factories->whereIn('id', $this->selectedFactoryIds)->values();
        }

        $result = [];

        foreach ($factories as $factory) {
            $fid = (string) $factory->id;
            $rawFilters = $this->factoryFilters[$fid] ?? [];
            $filters = [
                'name' => $rawFilters['name'] ?? '',
                'email' => $rawFilters['email'] ?? '',
                'mobile' => $rawFilters['mobile'] ?? '',
                'status' => $rawFilters['status'] ?? '',
            ];

            // Query users belonging to this factory
            $query = User::whereHas('teams', fn ($q) => $q->where('team_id', $factory->id));

            if (! empty($filters['name'])) {
                $query->where('name', 'like', '%'.$filters['name'].'%');
            }
            if (! empty($filters['email'])) {
                $query->where('email', 'like', '%'.$filters['email'].'%');
            }
            if (! empty($filters['mobile'])) {
                $query->where('mobile', 'like', '%'.$filters['mobile'].'%');
            }
            if ($filters['status'] === 'active') {
                $query->where('is_active', 1);
            } elseif ($filters['status'] === 'inactive') {
                $query->where('is_active', 0);
            }
            // No status filter = show ALL (active + inactive)

            $users = $query->with('teams')->get();

            // Apply per-factory sort
            $fSortCol = $this->factorySortCol[$fid] ?? 'role';
            $fSortDir = $this->factorySortDir[$fid] ?? 'asc';

            $roleOrder = ['owner' => 1, 'admin' => 2, 'supervisor' => 3, 'staff' => 4, 'reader' => 5];

            $users = $users->map(function ($u) use ($factory) {
                $pivot = $u->teams->where('id', $factory->id)->first();
                $u->role = $pivot?->pivot?->role ?? null;

                return $u;
            });

            $users = $users->sortBy(function ($u) use ($fSortCol, $roleOrder) {
                return match ($fSortCol) {
                    'role' => $roleOrder[$u->role] ?? 99,
                    'name' => strtolower($u->name),
                    'created' => $u->created_at?->timestamp ?? 0,
                    'status' => $u->is_active ? 0 : 1,
                    default => $roleOrder[$u->role] ?? 99,
                };
            }, SORT_REGULAR, $fSortDir === 'desc');

            $grouped = [
                'Owner' => collect(),
                'Admin' => collect(),
                'Supervisor' => collect(),
                'Staff' => collect(),
                'Superadmin' => collect(),
            ];

            foreach ($users as $u) {
                $role = $u->role;
                if ($role === 'owner') {
                    $grouped['Owner']->push($u);
                } elseif ($role === 'admin') {
                    $grouped['Admin']->push($u);
                } elseif ($role === 'supervisor') {
                    $grouped['Supervisor']->push($u);
                } elseif ($role === 'staff' || $role === 'reader') {
                    $grouped['Staff']->push($u);
                } elseif ($u->isSuperadmin()) {
                    $grouped['Superadmin']->push($u);
                }
            }

            $result[] = [
                'factory' => $factory,
                'groups' => collect($grouped)->filter(fn ($g) => $g->isNotEmpty()),
                'sortCol' => $fSortCol,
                'sortDir' => $fSortDir,
                'allUsers' => $users,
            ];
        }

        return $result;
    }

    public function doFactorySearch(string $factoryId): void
    {
        // Commit any pending deferred factoryFilters values for this factory.
        // Wire:model.defer values are already synced when any wire:click fires,
        // so this no-op method is sufficient to trigger a re-render.
    }

    public function resetFactorySearch(string $factoryId): void
    {
        $this->factoryFilters[$factoryId] = ['name' => '', 'email' => '', 'mobile' => '', 'status' => ''];
    }

    public function toggleFactoryId(int $id): void
    {
        if (in_array($id, $this->selectedFactoryIds)) {
            $this->selectedFactoryIds = array_values(array_filter($this->selectedFactoryIds, fn ($v) => $v !== $id));
        } else {
            $this->selectedFactoryIds[] = $id;
        }
    }

    public function resetSearch()
    {
        $this->search = '';
        $this->searchEmail = '';
        $this->searchMobile = '';
        $this->searchStatus = '';
        $this->selectedFactoryIds = [];
        $this->factoryFilters = [];
        $this->sortCol = null;
        $this->sortDir = 'asc';
        $this->resetPage();
    }

    public function sortBy(string $column)
    {
        if ($this->sortCol === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortCol = $column;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function closeForm()
    {
        $this->showModal = false;
        $this->resetForm();
        $this->resetErrorBag();
    }

    public function resetForm()
    {
        $this->user_id = null;
        $this->name = '';
        $this->email = '';
        $this->mobile = '';
        $this->status = 1;
        $this->editMode = false;
        $this->password = '';
        $this->password_confirmation = '';
    }

    public function edit($id)
    {
        $this->resetErrorBag();
        $this->showModal = true;
        $this->editMode = true;

        $user = User::findOrFail($id);

        $this->user_id = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->mobile = $user->mobile;
        $this->status = $user->is_active;
        $this->password = '';
    }

    public function update()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['required', 'digits:10'],
            'status' => 'required|boolean',
            'password' => ['nullable', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/'],
        ], [
            'name.required' => 'The name field is required.',
            'email.required' => 'The email field is required.',
            'mobile.required' => 'The mobile field is required.',
            'mobile.digits' => 'The mobile field must be 10 digits.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
            'password.regex' => 'Password must contain at least one uppercase letter, one number, and one special character.',
        ]);

        $user = User::findOrFail($this->user_id);

        // Check for uniqueness of email and mobile
        $existingUser = User::where('id', '!=', $user->id)
            ->where(function ($query) {
                $query->where('email', $this->email)
                    ->orWhere('mobile', $this->mobile);
            })->first();

        if ($existingUser) {
            if ($existingUser->email === $this->email && $existingUser->mobile === $this->mobile) {
                $this->addError('email', 'This email and mobile number combination is already in use.');

                return;
            }
            if ($existingUser->email === $this->email && $existingUser->mobile !== $this->mobile) {
                $this->addError('email', 'This email is already in use with a different mobile number.');

                return;
            }
            if ($existingUser->mobile === $this->mobile && $existingUser->email !== $this->email) {
                $this->addError('mobile', 'This mobile number is already in use with a different email.');

                return;
            }
        }

        // Update fields
        $user->name = $this->name;
        $user->email = $this->email;
        $user->mobile = $this->mobile;
        $user->is_active = $this->status;

        $plainPassword = null;
        if (! empty($this->password)) {
            $plainPassword = $this->password;
            $user->password = Hash::make($this->password);
        }

        $user->save();
        // Send email notification about the update
        $updatedFields = [];
        if ($user->wasChanged('name')) {
            $updatedFields['name'] = $user->name;
        }

        if ($user->wasChanged('mobile')) {
            $updatedFields['mobile'] = $user->mobile;
        }

        if ($plainPassword) {
            $updatedFields['password'] = $plainPassword;
        }

        if (! empty($updatedFields)) {
            if (app()->environment('local') && (int) env('APP_SEND_EMAIL', 0) !== 1) {
                Log::info("Skipping user update email sending in local environment for user: {$user->email}");
            } else {
                Mail::to($user->email)->send(new UserUpdatedMail($user, $updatedFields));
            }
        }

        session()->flash('success', 'User updated successfully.');
        $this->closeForm();
    }

    public function confirmDeletion(int $id): void
    {
        $user = User::with('teams')->findOrFail($id);

        if (! $user->isDeletableOrphan()) {
            session()->flash('error', 'Only users with no factory and no records can be deleted.');

            return;
        }

        $this->confirmingUserId = $id;
        $this->showDeleteConfirmationDialog = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteConfirmationDialog = false;
        $this->confirmingUserId = null;
    }

    /**
     * Permanently delete a stale user (no team membership, no associated records).
     */
    public function deleteUser(): void
    {
        abort_unless(auth()->user()?->canAccessSuperadminSettings(), 403);

        $user = User::find($this->confirmingUserId);
        $this->closeDeleteModal();

        if (! $user || $user->is(auth()->user()) || ! $user->isDeletableOrphan()) {
            session()->flash('error', 'Only users with no factory and no records can be deleted.');

            return;
        }

        try {
            DB::transaction(function () use ($user) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('user_device_tokens')->where('user_id', $user->id)->delete();
                DB::table('notifications')->where('user_id', $user->id)->delete();
                DB::table('pulse_user_notification_preferences')->where('user_id', $user->id)->delete();

                app(DeleteUser::class)->delete($user);
            });

            session()->flash('success', 'User deleted successfully.');
        } catch (QueryException $e) {
            // A books_* (or other FK-bound) row still references this user.
            report($e);
            session()->flash('error', 'This user is referenced by existing records and cannot be deleted.');
        }
    }

    public function updated($propertyName)
    {
        $this->resetErrorBag($propertyName);
    }

    public function render()
    {
        $query = User::with('teams')
            ->when($this->search, function ($q) {
                $q->where('name', 'like', "%{$this->search}%");
            })
            ->when($this->searchEmail, function ($q) {
                $q->where('email', 'like', "%{$this->searchEmail}%");
            })
            ->when($this->searchMobile, function ($q) {
                $q->where('mobile', 'like', "%{$this->searchMobile}%");
            })
            ->when($this->searchStatus === 'active', function ($q) {
                $q->where('is_active', 1);
            })
            ->when($this->searchStatus === 'inactive', function ($q) {
                $q->where('is_active', 0);
            });

        $query->orderByDesc('is_superadmin');
        if ($this->sortCol) {
            $query->orderBy($this->sortCol, $this->sortDir);
        } else {
            $query->orderBy('name', 'asc');
        }

        $users = $query->paginate($this->perPage);

        return view('livewire.all-users', compact('users'));
    }
}
