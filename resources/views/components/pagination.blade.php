<div class="flex place-content-between items-center p-3">
    <div class="text-gray-800 text-sm">
        Total Count: <span class="text-black">{{ $items->total() }}</span>
    </div>
    <!-- Records Per Page Dropdown -->
    <div class="flex border border-gray-300 rounded-md">
        <div class="w-28 bg-[#f7f7fe] rounded-md">
            <select id="perPage" wire:model.change="{{ $perPageBinding }}"
                class="cursor-pointer rounded-md px-1.5 py-1.5 bg-[#f7f7fe] w-full text-sm border-0 text-gray-700 hover:text-[#2485e8] focus:ring-0">
                <option value="10" class="text-gray-700">10 per page</option>
                <option value="25" class="text-gray-700">25 per page</option>
                <option value="50" class="text-gray-700">50 per page</option>
                <option value="100" class="text-gray-700">100 per page</option>
            </select>
        </div>
        <div class="flex w-fit border-l border-gray-300">
            <!-- Previous Button -->
            <button
                wire:click="previousPage"
                wire:loading.attr="disabled"
                class="px-3 py-1 text-sm {{ $items->onFirstPage() ? 'opacity-50 cursor-not-allowed' : '' }}"
                {{ $items->onFirstPage() ? 'disabled' : '' }}>
                <i class="fa-solid fa-chevron-left text-[10px] text-[#83211b]"></i>
            </button>

            <!-- Pagination Range -->
            <div class="text-sm font-medium place-content-center pt-0.5 text-gray-700 cursor-pointer">
                <span>{{ $items->firstItem() }}</span> - <span>{{ $items->lastItem() }}</span>
            </div>

            <!-- Next Button -->
            <button
                wire:click="nextPage"
                wire:loading.attr="disabled"
                class="px-3 py-1 text-sm {{ !$items->hasMorePages() ? 'opacity-50 cursor-not-allowed' : '' }}"
                {{ !$items->hasMorePages() ? 'disabled' : '' }}>
                <i class="fa-solid fa-chevron-right text-[10px] text-[#83211b]"></i>
            </button>
        </div>
    </div>
</div>
