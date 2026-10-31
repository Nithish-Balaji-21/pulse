<div>

    <div class="bg-white rounded-lg p-4 shadow">
        <h2 class="font-semibold text-lg mb-2">{{ $editing ? 'Edit Outward Record' : 'Add Outward Record' }}</h2>

        <div class="mb-3 text-xs text-gray-700">
            Allowed quantity: <span class="font-semibold">{{ $maxAllowed }}</span>
            @if($editing)
                <span class="ml-2">Already returned for this outward: <span class="font-semibold">{{ $alreadyReturnedForThisOutward }}</span></span>
            @endif
        </div>

        <form wire:submit.prevent="{{ $editing ? 'update' : 'save' }}" class="w-full">
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Outward Type</label>
                <select wire:model="outward_type" class="select-type w-2/3 text-sm max-h-10">
                    <option value="Maintenance">Maintenance</option>
                    <option value="Loaned">Loaned</option>
                    <option value="Scrap">Scrap</option>
                    <option value="Sold">Sold</option>
                    <option value="Transferred">Transferred</option>
                </select>
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Outward Date<span class="text-red-500"> *</span></label>
                <input type="date" wire:model="outward_date" onclick="this.showPicker()" placeholder="dd/mm/yyyy" max="{{ \Carbon\Carbon::today()->format('Y-m-d') }}" class="input-type w-2/3 text-sm py-1.5 rounded-lg border-gray-200 max-h-10" required />
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">To Company<span class="text-red-500"> *</span></label>
                <input type="text" wire:model="to_company" placeholder="Enter company name" class="input-type w-2/3 text-sm" required />
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Outward Quantity<span class="text-red-500"> *</span></label>
                <div class="w-2/3">
                    <input type="number" wire:model="outward_quantity" placeholder="Enter quantity" class="input-type w-full text-sm" min="1" required />
                    @if($outward_quantity > $maxAllowed)
                        <p class="text-red-600 text-xs mt-1">Warning: Outward quantity ({{ $outward_quantity }}) cannot exceed allowed ({{ $maxAllowed }}).</p>
                    @endif
                </div>
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Approved By</label>
                <input type="text" wire:model="approved_by" placeholder="Approver name" class="input-type w-2/3 text-sm" />
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Remarks</label>
                <input type="text" wire:model="remarks" placeholder="Additional notes..." class="input-type w-2/3 text-sm" />
            </div>

            <div class="w-full flex mb-2">
                <div class="w-1/3"></div>
                <div class="w-2/3 place-content-start text-sm">
                    <button type="submit" class="save-btn">{{ $editing ? 'Update Outward' : 'Save Outward' }}</button>
                    @if($asModal)
                        <button type="button" wire:click="$dispatch('close-outward-modal')" class="cancel-btn">Cancel</button>
                    @else
                        <a href="{{ route('assets.view', ['team' => $teamId, 'id' => $assetId, 'tab' => 'outward']) }}" class="cancel-btn">Cancel</a>
                    @endif
                </div>
            </div>
        </form>
    </div>
</div>