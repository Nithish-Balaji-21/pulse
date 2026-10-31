<?php

namespace App\Livewire;

use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Http\Controllers\UserController;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ManageUsers extends Component
{
    public $teamId;

    protected $userController;

    public $users = [];

    public $showModal = false;

    public $editMode = false;

    public $user_id;

    public $name;

    public $email;

    public $mobile;

    public $confirmingUserId;

    public $showDeleteConfirmationDialog = false;

    public $team;

    public $role;

    public $showRoleHelp = false;

    public $showErrorDialog = false;

    public $errorMessage = '';

    public $showTransferModal = false;

    public $selectedNewOwnerId = null;

    public $confirmingTransfer = false;

    public $currentOwnerUser = null;

    public $candidateOwnerUser = null;

    public $transferTeamId = null;

    /**
     * Role Comparison modal: capability label => role key => allowed.
     * Built from the factory matrix (same source as Roles & access).
     *
     * @var array<string, array<string, bool>>
     */
    public $roleFeatures = [];

    /** @var array<string, string> role key => display name */
    public $roleLabels = [];

    public function __construct()
    {
        $this->userController = new UserController;
    }

    public function mount()
    {
        $this->team = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        $this->teamId = $this->team->id;
        $this->index();
    }

    public function buildRoleFeatures(): void
    {
        // Kept for backward compatibility in tests — capabilities come from the matrix.
        $this->roleLabels = collect(TeamRolePermissions::rolesForTeam($this->teamId))
            ->pluck('name', 'key')
            ->all();
        $this->roleFeatures = [];
    }

    public function getCandidateAdminsProperty()
    {
        $targetId = $this->transferTeamId ?: $this->teamId;
        $factory = Team::find($targetId);

        if (! $factory) {
            return collect();
        }

        $currentOwnerId = $factory->owner?->id;

        // 1. Get all factory users with 'admin' role
        $adminUsers = $factory->users()
            ->wherePivot('role', 'admin')
            ->get();

        // 2. Get all Superadmins in the system
        $superadmins = User::where('is_superadmin', 1)->get();

        // 3. Merge, exclude current owner, keep unique by ID, and sort by name
        return $adminUsers->concat($superadmins)
            ->reject(fn ($u) => $u->id === $currentOwnerId)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    public function index()
    {
        $response = $this->userController->index($this->teamId);
        $this->users = $response->getData();
    }

    public function openForm()
    {
        if (! Auth::user()?->canAddUsers()) {
            session()->flash('error', 'You are not allowed to add users.');

            return;
        }

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
        $this->role = 'staff'; // Reset role
        $this->editMode = false;
    }

    public function store()
    {
        $existingUser = User::where('email', $this->email)
            ->orWhere('mobile', $this->mobile)
            ->first();

        if ($existingUser) {

            if ($existingUser->email === $this->email && $existingUser->mobile !== $this->mobile) {
                $this->addError('email', 'This email is already in use with a different mobile number.');

                return;
            }

            if ($existingUser->mobile === $this->mobile && $existingUser->email !== $this->email) {
                $this->addError('mobile', 'This mobile number is already in use with a different email.');

                return;
            }

            if ($existingUser->teams()->where('team_id', $this->team->id)->exists()) {
                $this->addError('email', 'This user is already a member of this team.');

                return;
            }

        }

        // Validate fields
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['required', 'digits:10'],
            // Only roles the actor may hand out — an admin cannot mint another admin.
            'role' => ['required', Rule::in(Auth::user()?->assignableRoles() ?? [])],
        ], [
            'name.required' => 'The name field is required.',
            'email.required' => 'The email field is required.',
            'mobile.required' => 'The mobile field is required.',
            'mobile.digits' => 'The mobile field must be 10 digits.',
            'role.required' => 'Please select a role.',
        ]);

        // Add user to team
        $this->addUserToTeam($existingUser);
        $this->closeForm();
        $this->index();
    }

    protected function addUserToTeam($existingUser = null)
    {
        $authUser = Auth::user();

        if (! $authUser instanceof User) {
            throw new \Exception('No authenticated user found');
        }

        $addTeamMember = new AddTeamMember;

        $email = $existingUser?->email ?? $this->email;
        $mobile = $existingUser?->mobile ?? $this->mobile;
        $name = $existingUser?->name ?? $this->name;

        $addTeamMember->add($authUser, $this->team, $email, $this->role, $mobile, $name);
    }

    public function openTransferOwnerModal($factoryId = null)
    {
        if (! Auth::user()?->canTransferOwnership()) {
            $this->triggerErrorDialog('Only a Superadmin can transfer factory ownership.');

            return;
        }

        $targetTeam = $factoryId ? Team::find($factoryId) : $this->team;
        if (! $targetTeam) {
            return;
        }

        $this->transferTeamId = $targetTeam->id;
        $this->currentOwnerUser = $targetTeam->owner;
        $this->selectedNewOwnerId = null;
        $this->confirmingTransfer = false;
        $this->candidateOwnerUser = null;
        $this->showTransferModal = true;
    }

    public function initiateTransferOwner()
    {
        if (! Auth::user()?->canTransferOwnership()) {
            $this->triggerErrorDialog('Only the Factory Owner or a Superadmin can transfer factory ownership.');

            return;
        }

        if (! $this->selectedNewOwnerId) {
            $this->addError('selectedNewOwnerId', 'Please select a user to assign as the new owner.');

            return;
        }

        $this->candidateOwnerUser = User::find($this->selectedNewOwnerId);
        if (! $this->candidateOwnerUser) {
            $this->addError('selectedNewOwnerId', 'Selected user not found.');

            return;
        }

        if (empty($this->candidateOwnerUser->email) || empty($this->candidateOwnerUser->mobile)) {
            $this->triggerErrorDialog('The selected user must have both a valid email and mobile number before becoming Factory Owner.');

            return;
        }

        $this->confirmingTransfer = true;
    }

    public function confirmTransferOwner()
    {
        if (! Auth::user()?->canTransferOwnership()) {
            $this->triggerErrorDialog('Only the Factory Owner or a Superadmin can transfer factory ownership.');

            return;
        }

        if (! $this->selectedNewOwnerId || ! $this->candidateOwnerUser) {
            $this->triggerErrorDialog('No valid replacement owner selected.');

            return;
        }

        $targetTeam = Team::find($this->transferTeamId ?: $this->team->id);
        if (! $targetTeam) {
            return;
        }

        DB::transaction(function () use ($targetTeam) {
            // Demote existing owner(s) to admin
            $currentOwner = $targetTeam->owner;
            if ($currentOwner) {
                $targetTeam->users()->updateExistingPivot($currentOwner->id, ['role' => 'admin']);
            }

            // Promote candidate to owner
            if ($targetTeam->users()->where('user_id', $this->candidateOwnerUser->id)->exists()) {
                $targetTeam->users()->updateExistingPivot($this->candidateOwnerUser->id, ['role' => 'owner']);
            } else {
                $targetTeam->users()->attach($this->candidateOwnerUser->id, ['role' => 'owner']);
            }
        });

        session()->flash('success', "Factory ownership successfully transferred to {$this->candidateOwnerUser->name}.");
        $this->showTransferModal = false;
        $this->confirmingTransfer = false;
        $this->index();
    }

    public function triggerErrorDialog($message)
    {
        $this->errorMessage = $message;
        $this->showErrorDialog = true;
    }

    public function closeErrorDialog()
    {
        $this->showErrorDialog = false;
        $this->errorMessage = '';
    }

    public function edit($teamMemberId)
    {
        $this->resetErrorBag();

        // Find the user to edit
        $user = User::findOrFail($teamMemberId);
        $teamPivot = $user->teams()->where('team_id', $this->team->id)->first();

        if ($teamPivot && $teamPivot->pivot->role === 'owner') {
            if (! Auth::user()?->canTransferOwnership()) {
                $this->triggerErrorDialog('Only the Factory Owner or a Superadmin can change the Factory Owner.');

                return;
            }
            // The owner's role never changes through the form — send them to the
            // transfer flow, which reassigns and demotes in one step.
            $this->openTransferOwnerModal();

            return;
        }

        // An admin manages the roles beneath them only; editing a peer admin is
        // the owner's business. Checked against the target's *current* role, so
        // demoting an admin is refused as firmly as promoting one.
        if (! Auth::user()?->canManageUserWithRole($teamPivot?->pivot?->role)) {
            $this->triggerErrorDialog('You are not allowed to edit a user with this role.');

            return;
        }

        $this->showModal = true;
        $this->editMode = true;

        // Populate the form fields with the user's data
        $this->user_id = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->mobile = $user->mobile;
        $this->role = $teamPivot?->pivot?->role ?? 'staff';
    }

    public function update()
    {
        $user = User::findOrFail($this->user_id);
        $teamPivot = $user->teams()->where('team_id', $this->team->id)->first();

        if ($teamPivot && $teamPivot->pivot->role === 'owner') {
            $this->triggerErrorDialog('Cannot directly change Factory Owner role. Please use the Change Factory Owner button.');
            $this->closeForm();

            return;
        }

        // Validate fields
        $this->validate([
            // Only roles the actor may hand out — an admin cannot mint another admin.
            'role' => ['required', Rule::in(Auth::user()?->assignableRoles() ?? [])],
        ], [
            'role.required' => 'Please select a role.',
        ]);

        // Update the user's role in the team
        $user->teams()->updateExistingPivot($this->team->id, ['role' => $this->role]);
        $user->forgetRoleSessionCache();

        session()->flash('success', 'User updated successfully.');

        $this->closeForm();
        $this->index();
    }

    public function confirmDeletion($id)
    {
        $user = User::findOrFail($id);
        $teamPivot = $user->teams()->where('team_id', $this->team->id)->first();

        if ($teamPivot && $teamPivot->pivot->role === 'owner') {
            $this->triggerErrorDialog("Cannot remove active Factory Owner. Please assign another user as owner first, and then remove {$user->name}.");

            return;
        }

        $this->confirmingUserId = $id;
        $this->showDeleteConfirmationDialog = true;
    }

    public function closeDeleteModal()
    {
        $this->showDeleteConfirmationDialog = false;
        $this->confirmingUserId = null;
    }

    public function deleteUser()
    {
        $authUser = Auth::user();

        if (! $authUser instanceof User) {
            throw new \Exception('No authenticated user found or invalid user type');
        }

        if (! $this->confirmingUserId) {
            session()->flash('error', 'Please select a user to remove.');
            $this->closeDeleteModal();

            return;
        }

        $teamMember = User::findOrFail($this->confirmingUserId);
        $teamPivot = $teamMember->teams()->where('team_id', $this->team->id)->first();

        if ($teamPivot && $teamPivot->pivot->role === 'owner') {
            $this->closeDeleteModal();
            $this->triggerErrorDialog("Cannot remove active Factory Owner. Please assign another user as owner first, and then remove {$teamMember->name}.");

            return;
        }

        // Removing a peer admin is the owner's business, not another admin's.
        if (! $authUser->canManageUserWithRole($teamPivot?->pivot?->role)) {
            $this->closeDeleteModal();
            $this->triggerErrorDialog('You are not allowed to remove a user with this role.');

            return;
        }

        try {
            // Wrap in transaction to prevent race conditions
            DB::transaction(function () use ($authUser, $teamMember) {
                // Remove the user from the current team
                (new RemoveTeamMember)->remove($authUser, $this->team, $teamMember);

                // If their active team was this team, pick another (or leave as-is if none)
                if ((int) $teamMember->current_team_id === (int) $this->team->id) {
                    $otherTeam = $teamMember->teams()->where('team_id', '!=', $this->team->id)->first();
                    $teamMember->current_team_id = $otherTeam?->id;
                    $teamMember->save();
                }
            });

            session()->flash('success', 'User removed successfully.');
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Unable to remove this user.';
            session()->flash('error', $message);
        } catch (AuthorizationException) {
            session()->flash('error', 'You are not allowed to remove this user.');
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', 'Something went wrong while removing the user.');
        } finally {
            $this->closeDeleteModal();
            $this->index();
        }
    }

    public function updated($propertyName)
    {
        $this->resetErrorBag($propertyName);
    }

    public function render()
    {
        return view('livewire.manage-users');
    }
}
