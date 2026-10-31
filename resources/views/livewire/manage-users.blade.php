<div>
    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">
            {{ __('People: Users') }}
        </h2>
        @if (session()->has('success'))
            <div class="text-green-500 text-md font-semibold text-center grow mx-4">
                {{ session('success') }}
            </div>
        @endif
        @if (session()->has('error'))
            <div class="text-red-500 text-md font-semibold text-center grow mx-4">
                {{ session('error') }}
            </div>
        @endif
        <div class="flex items-center gap-2">
            @if (auth()->user()?->canAddUsers())
                <div>
                    <button wire:click="openForm" class="add-btn text-sm" wire:loading.attr="disabled" wire:target="store" wire:loading.class="opacity-50 cursor-not-allowed">
                        <i class="fa-solid fa-plus text-sm"></i> Add User
                    </button>
                </div>
            @endif
        </div>
    </div>
    <div class="py-2">
        @if (app()->environment('local') && (int) env('APP_SEND_EMAIL', 0) !== 1)
            <div class="mb-4 rounded border border-yellow-300 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
                Local email sending is disabled because <code>APP_SEND_EMAIL=0</code>. Set <code>APP_SEND_EMAIL=1</code> to enable email delivery in local.
            </div>
        @endif
        <div class="">
            <div class="bg-white overflow-hidden sm:rounded-lg">
                <div>
                    <!-- User List -->
                    <div>
                        <table class="w-full border-t">
                            <thead>
                                <tr class="table-header text-xs border-b border-gray-300">
                                    <th class="py-2 pe-1 font-semibold ps-4 text-left">Name</th>
                                    <th class="py-2 pe-1 font-semibold">Role
                                        <a href="javascript:void(0)"
                                            class="text-blue-500 text-xs ml-1 underline"
                                            wire:click="$set('showRoleHelp', true)">
                                                (?)
                                        </a>
                                    </th>
                                    <th class="py-2 pe-1 font-semibold">Email</th>
                                    <th class="py-2 pe-1 font-semibold">Phone</th>
                                    <th class="py-2 pe-1 font-semibold">Status</th>
                                    <th class="py-2 pe-1 font-semibold">Actions</th>
                                    <th> </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($users as $user)
                                    <tr class="border-b border-gray-300">
                                        <td class="text-left text-sm py-3 ps-4">
                                            {{ $user->name }}
                                            @if ($user->role === 'owner')
                                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-800">
                                                    <i class="fa-solid fa-industry mr-1"></i> Owner
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-left text-sm py-3 ps-4 capitalize">
                                            @if ($user->role === 'owner')
                                                <span class="text-amber-700">{{ $this->roleLabels['owner'] ?? 'Owner' }}</span>
                                            @else
                                                {{ $this->roleLabels[$user->role] ?? ucfirst($user->role) }}
                                            @endif
                                        </td>
                                        <td class="text-center text-sm py-3">{{ $user->email }}</td>
                                        <td class="text-center text-sm py-3">{{ $user->mobile ?? '' }}</td>
                                        <td
                                            class="text-center text-sm py-3 {{ $user->is_active ? 'Active' : 'text-red-500' }}">
                                            {{ $user->is_active ? 'Active' : 'Inactive' }}</td>
                                        <td class="text-center text-xs py-3 space-x-2">
                                            @if ($user->role === 'owner')
                                                @if (auth()->user()?->isSuperadmin())
                                                    <div class="w-fit inline-block">
                                                        <button wire:click="openTransferOwnerModal"
                                                            class="warn-btn btn-xs">
                                                            <i class="fa-solid fa-industry mr-1"></i> Change Owner
                                                        </button>
                                                    </div>
                                                @endif
                                            @else
                                                <div class="w-fit inline-block">
                                                    <button wire:click="edit({{ $user->id }})"
                                                        class="edit-btn btn-xs">
                                                        <i class="fa-regular fa-pen-to-square"></i>Edit
                                                    </button>
                                                </div>
                                                <div class="w-fit inline-block">
                                                    <button wire:click="confirmDeletion({{ $user->id }})"
                                                        class="delete-btn btn-xs">
                                                        <i class="fa-regular fa-trash-can"></i>Remove
                                                    </button>
                                                </div>
                                            @endif

                                            @if (auth()->user()?->isSuperadmin())
                                                <div class="w-fit inline-block">
                                                    <x-impersonate-button :user="$user" />
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Transfer Ownership Modal & Dialog -->
                    @if ($showTransferModal)
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-500/50">
                            <div class="bg-white rounded-lg p-6 max-w-lg w-full shadow-xl">
                                <h3 class="text-lg font-semibold text-gray-900 mb-2">
                                    <i class="fa-solid fa-industry text-amber-600 mr-2"></i> Change Factory Owner
                                </h3>
                                @if(! $confirmingTransfer)
                                    <p class="text-sm text-gray-600 mb-4">
                                        Select a team member to assign as the new Factory Owner. The current owner (<strong>{{ $currentOwnerUser?->name ?? 'None' }}</strong>) will automatically be assigned the <strong>Admin</strong> role.
                                    </p>
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Select New Owner</label>
                                        <div class="border border-gray-200 rounded-lg overflow-hidden divide-y divide-gray-100 max-h-56 overflow-y-auto">
                                            @forelse ($this->candidateAdmins as $u)
                                                <label class="flex items-center gap-3 px-4 py-2.5 hover:bg-amber-50 cursor-pointer {{ $selectedNewOwnerId == $u->id ? 'bg-amber-50' : '' }}">
                                                    <input type="radio" wire:model="selectedNewOwnerId" value="{{ $u->id }}" class="text-amber-600 border-gray-300 focus:ring-amber-500">
                                                    <div class="flex-1 min-w-0">
                                                        <div class="text-sm font-semibold text-gray-800">{{ $u->name }} <span class="text-xs font-normal text-gray-400">({{ $u->is_superadmin ? 'superadmin' : 'admin' }})</span></div>
                                                        <div class="text-xs text-gray-500 truncate">{{ $u->email }}</div>
                                                    </div>
                                                </label>
                                            @empty
                                                <div class="px-4 py-3 text-sm text-gray-400 italic">No candidate users available for ownership transfer.</div>
                                            @endforelse
                                        </div>
                                        @error('selectedNewOwnerId')
                                            <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" wire:click="$set('showTransferModal', false)" class="cancel-btn">Cancel</button>
                                        <button type="button" wire:click="initiateTransferOwner" class="warn-btn">
                                            Next &rarr;
                                        </button>
                                    </div>
                                @else
                                    <div class="bg-amber-50 border border-amber-200 rounded p-4 mb-4">
                                        <h4 class="font-semibold text-amber-900 text-sm mb-2">Confirm Ownership Transfer</h4>
                                        <p class="text-sm text-amber-800">
                                            Are you sure you want to transfer Factory Ownership from <strong>{{ $currentOwnerUser?->name }}</strong> to <strong>{{ $candidateOwnerUser?->name }}</strong>? <strong>{{ $currentOwnerUser?->name }}</strong> will be assigned the Admin role.
                                        </p>
                                    </div>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" wire:click="$set('confirmingTransfer', false)" class="cancel-btn">Back</button>
                                        <button type="button" wire:click="confirmTransferOwner" class="warn-btn">
                                            Confirm Transfer
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- Generic Error Dialog Box -->
                    @if ($showErrorDialog)
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60">
                            <div class="bg-white rounded-lg p-6 max-w-md w-full shadow-2xl border-t-4 border-red-600">
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center text-red-600 text-lg font-semibold">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-900">Action Blocked</h3>
                                </div>
                                <p class="text-sm text-gray-700 mb-5 leading-relaxed">
                                    {{ $errorMessage }}
                                </p>
                                <div class="flex justify-end">
                                    <button type="button" wire:click="closeErrorDialog" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded hover:bg-gray-900">
                                        OK
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Modal Form -->
                    @if ($showModal)
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-500/50">
                            <div
                                class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:align-middle sm:max-w-lg sm:w-full sm:h-auto my-1">
                                <div class="bg-white px-6 pt-5 pb-4">
                                    <div class="flex justify-between items-center">
                                        <h3 class="text-lg leading-6 font-medium text-gray-900">
                                            {{ $editMode ? 'Edit User' : 'Add User' }}
                                        </h3>
                                        <div>
                                            <button wire:click="$set('showModal', false)"
                                                class="text-red-500 hover:text-red-700 text-lg">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex">
                                        <div class="w-1/3"></div>
                                        @if (session()->has('error'))
                                            <div
                                                class="w-2/3 text-red-500 text-md place-content-center text-left font-semibold">
                                                {{ session('error') }}</div>
                                        @endif
                                    </div>
                                    <!-- Form -->
                                    <form wire:submit.prevent="{{ $editMode ? 'update' : 'store' }}">
                                        <div class="grid grid-cols-3 items-center mt-4">
                                            <label for="userName"
                                                class="place-content-center text-sm text-nowrap">Name</label>
                                            <input type="text" id="userName" wire:model="name"
                                                class="col-span-2 text-sm border rounded p-2 w-full focus:outline-hidden focus:ring-2 focus:ring-blue-400"
                                                @if($editMode) disabled style="background-color: lightgrey" @endif>
                                            @error('name')
                                                <span
                                                    class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="grid grid-cols-3 items-center mt-4">
                                            <label for="userEmail"
                                                class="place-content-center text-sm text-nowrap">Email</label>
                                            <input type="email" id="userEmail" wire:model="email"
                                                class="col-span-2 text-sm border rounded p-2 w-full focus:outline-hidden focus:ring-2 focus:ring-blue-400"
                                                @if($editMode) disabled style="background-color: lightgrey" @endif>
                                            @error('email')
                                                <span
                                                    class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="grid grid-cols-3 items-center mt-4">
                                            <label for="userPhone"
                                                class="place-content-center text-sm text-nowrap">Mobile</label>
                                            <input type="text" id="userPhone" wire:model="mobile"
                                                class="col-span-2 text-sm border rounded p-2 w-full focus:outline-hidden focus:ring-2 focus:ring-blue-400"
                                                @if($editMode) disabled style="background-color: lightgrey" @endif>
                                            @error('mobile')
                                                <span
                                                    class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="grid grid-cols-3 items-center mt-4">
                                            <label class="place-content-center text-sm text-nowrap">Role
                                                <a href="javascript:void(0)"
                                                    class="text-blue-500 text-xs ml-1 underline"
                                                    wire:click="$set('showRoleHelp', true)">
                                                        (?)
                                                </a>
                                            </label>
                                            {{-- Only the roles this user may hand out: an admin manages the
                                                 roles beneath them, an owner may also create admins. --}}
                                            <div class="col-span-2 flex gap-4">
                                                @foreach (auth()->user()->assignableRoles() as $assignableRole)
                                                    <label class="flex items-center text-sm">
                                                        <input type="radio" wire:model="role" value="{{ $assignableRole }}" class="mr-2">
                                                        {{ $this->roleLabels[$assignableRole] ?? ucfirst($assignableRole) }}
                                                    </label>
                                                @endforeach
                                            </div>
                                            @error('role')
                                                <span
                                                    class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="flex justify-end space-x-2 mt-4 text-sm">
                                            @if ($editMode)
                                                <button type="submit" class="add-btn">
                                                    <i class="fa-solid fa-plus"></i> Update User
                                                </button>
                                                <button type="button" wire:click="closeForm"
                                                    class="cancel-btn">Cancel</button>
                                            @else
                                                <button type="submit" class="add-btn" wire:loading.attr="disabled" wire:target="store">
                                                    <span wire:loading wire:target="store" class="inline-flex items-center">
                                                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                        </svg>
                                                        Adding ...
                                                    </span>
                                                    <span wire:loading.remove wire:target="store">
                                                        <i class="fa-solid fa-plus"></i> Add User
                                                    </span>
                                                </button>
                                                <button type="button" wire:click="closeForm"
                                                    class="cancel-btn" wire:loading.attr="disabled" wire:target="store">Cancel</button>
                                            @endif
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif
                    <!-- Role Help Modal -->
                    @if($showRoleHelp)
                        <div class="fixed inset-0 flex items-center justify-center bg-gray-800/50 z-50">
                            <div class="bg-white rounded-lg shadow-xl w-3/4 max-w-lg p-6 relative text-center">
                                <button class="absolute top-2 right-2 text-gray-600 hover:text-gray-900"
                                        wire:click="$set('showRoleHelp', false)">
                                    ✖
                                </button>
                                <h2 class="text-lg font-semibold mb-3">Role Permissions</h2>
                                <p class="text-sm text-gray-600 mb-4">
                                    View and configure what each role can do in the factory permissions matrix.
                                </p>
                                <a href="{{ route('roles.index', ['team' => $teamId]) }}"
                                   class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">
                                    Open Roles & Permissions
                                </a>
                            </div>
                        </div>
                    @endif
                    @if ($showDeleteConfirmationDialog)
                        <div class="fixed z-50 inset-0 overflow-y-auto bg-black/60">
                            <div class="flex items-center justify-center min-h-screen">
                                <div
                                    class="bg-white p-6 rounded-lg shadow-lg w-1/3 max-[600px]:w-9/12 max-[900px]:w-7/12 max-[1200px]:w-5/12">
                                    <h2 class="text-md font-semibold mb-2">Confirm Deletion</h2>
                                    <p class="text-sm">Are you sure you want to remove this user from the factory?</p>
                                    <div class="mt-4 flex justify-end gap-4">
                                        <!-- Confirm Button -->
                                        <button class="delete-btn" wire:click="deleteUser">
                                            Confirm
                                        </button>

                                        <!-- Cancel Button -->
                                        <button class="cancel-btn" wire:click="closeDeleteModal">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
{{-- <script>
    function togglePasswordVisibility(fieldId, button) {
        const input = document.getElementById(fieldId);
        const icon = button.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
</script> --}}
