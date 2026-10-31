<x-action-section>
    <x-slot name="title">
        {{ __('Delete Factory') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Permanently delete this factory.') }}
    </x-slot>

    <x-slot name="content">
        <div class="max-w-xl text-sm text-gray-600">
            {{ __('Once a factory is deleted, all of its resources and data will be permanently deleted. Before deleting this factory, please download any data or information regarding this factory that you wish to retain.') }}
        </div>

        <div class="mt-5">
            <x-danger-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                {{ __('Delete Factory') }}
            </x-danger-button>
        </div>

        <!-- Delete Factory Confirmation Modal -->
        <x-confirmation-modal wire:model.live="confirmingTeamDeletion">
            <x-slot name="title">
                {{ __('Delete Factory') }}
            </x-slot>

            <x-slot name="content">
                {{ __('Are you sure you want to delete this factory? Once a factory is deleted, all of its resources and data will be permanently deleted.') }}
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3" wire:click="deleteTeam" wire:loading.attr="disabled">
                    {{ __('Delete Factory') }}
                </x-danger-button>
            </x-slot>
        </x-confirmation-modal>
    </x-slot>
</x-action-section>
