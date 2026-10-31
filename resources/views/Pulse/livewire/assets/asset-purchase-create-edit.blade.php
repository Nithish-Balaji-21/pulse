<div>

    <div class="bg-white rounded-lg p-4 shadow">
        <h2 class="font-semibold text-lg mb-2">{{ $editing ? 'Edit Purchase Record' : 'Add Purchase Record' }}</h2>

        <form wire:submit.prevent="{{ $editing ? 'update' : 'save' }}" class="w-full">
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Inward Type<span class="text-red-500"> *</span></label>
                <select wire:model="purchase_type" class="select-type w-2/3 text-sm max-h-10" required>
                    <option value="Purchased">Purchased</option>
                    <option value="Loaned">Loaned</option>
                </select>
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Purchased Date<span class="text-red-500"> *</span></label>
                <input type="date" wire:model="purchase_date" onclick="this.showPicker()" placeholder="dd/mm/yyyy" max="{{ \Carbon\Carbon::today()->format('Y-m-d') }}" class="input-type w-2/3 text-sm py-1.5 rounded-lg border-gray-200 max-h-10" required />
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">From Company<span class="text-red-500"> *</span></label>
                <input type="text" wire:model="from_company" placeholder="Enter company name" class="input-type w-2/3 text-sm" required />
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Price (₹)</label>
                <input type="number" wire:model="price" placeholder="Enter price" class="input-type w-2/3 text-sm" />
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Purchased Quantity<span class="text-red-500"> *</span></label>
                <input type="number" wire:model="purchased_quantity" placeholder="Enter quantity" class="input-type w-2/3 text-sm" min="1" required />
            </div>
            <div class="w-full flex mb-3">
                <label class="w-1/3 place-content-center text-sm text-nowrap">Remarks</label>
                <textarea wire:model="remarks" rows="3" placeholder="Additional notes..." class="textarea-type w-2/3 text-sm"></textarea>
            </div>

            <div class="w-full flex mb-2">
                <div class="w-1/3"></div>
                <div class="w-2/3 place-content-start text-sm">
                    <button type="submit" class="save-btn">{{ $editing ? 'Update Purchase' : 'Save Purchase' }}</button>
                    @if($asModal)
                        <button type="button" wire:click="$dispatch('close-purchase-modal')" class="cancel-btn">Cancel</button>
                    @else
                        <a href="{{ route('assets.view', ['team' => $teamId, 'id' => $assetId, 'tab' => 'purchased']) }}" class="cancel-btn">Cancel</a>
                    @endif
                </div>
            </div>
        </form>
    </div>
</div>