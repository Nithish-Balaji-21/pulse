<div>

    <div class="bg-white rounded-lg p-4 shadow">
        <h2 class="font-semibold text-lg mb-2">{{ $editing ? 'Edit Return Record' : 'Add Return Record' }}</h2>

        <div class="mb-3 text-xs text-gray-700">
            <span>Outward: <span class="font-semibold">{{ $outward_quantity }}</span></span>
            <span class="ml-2">Returned: <span class="font-semibold">{{ $returnedSoFar }}</span></span>
            <span class="ml-2">Allowed: <span class="font-semibold">{{ $maxAllowed }}</span></span>
        </div>

        <form wire:submit.prevent="{{ $editing ? 'update' : 'save' }}" class="w-full">
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Returned Date<span class="text-red-500"> *</span></label>
                <input type="date" wire:model="returned_date" onclick="this.showPicker()" placeholder="dd/mm/yyyy" class="input-type w-2/3 text-sm py-1.5 rounded-lg border-gray-200 max-h-10" required />
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Returned Quantity<span class="text-red-500"> *</span></label>
                <div class="w-2/3">
                    <input type="number" wire:model="returned_quantity" placeholder="Enter quantity" class="input-type w-full text-sm" min="1" required />
                    @if($returned_quantity > $maxAllowed)
                        <p class="text-red-600 text-xs mt-1">Warning: Returned quantity ({{ $returned_quantity }}) cannot exceed allowed ({{ $maxAllowed }}).</p>
                    @endif
                </div>
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Remarks</label>
                <textarea wire:model="remarks" rows="3" placeholder="Additional notes..." class="textarea-type w-2/3 text-sm"></textarea>
            </div>

            <div class="w-full flex mb-2">
                <div class="w-1/3"></div>
                <div class="w-2/3 place-content-start text-sm">
                    <button type="submit" class="save-btn">{{ $editing ? 'Update Return' : 'Save Return' }}</button>
                    @if($asModal)
                        <button type="button" wire:click="$dispatch('close-return-modal')" class="cancel-btn">Cancel</button>
                    @else
                        <a href="{{ route('assets.view', ['team' => $teamId, 'id' => $assetId, 'tab' => 'returned']) }}" class="cancel-btn">Cancel</a>
                    @endif
                </div>
            </div>
        </form>
    </div>
</div>