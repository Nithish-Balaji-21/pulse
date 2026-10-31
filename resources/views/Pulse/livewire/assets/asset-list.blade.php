<div>
    <!-- Loading Overlay -->
    <div wire:loading class="loading-overlay">
        <div class="text-center">
            <div class="loading-spinner mx-auto"></div>
            <p class="text-white mt-4 font-medium">Loading assets...</p>
        </div>
    </div>

    <div class="mt-4 px-4">
        <div class="bg-white border border-gray-200 shadow-xs rounded-[28px] p-4 sm:p-5">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-2 text-sm text-gray-500 mb-2">
                    <a href="{{ route('dashboard', ['team' => $teamId]) }}" class="inline-flex items-center gap-2 text-gray-500 hover:text-[#83211b] transition">
                        <i class="fa-solid fa-chart-pie text-xs"></i>
                        <span>Dashboard</span>
                    </a>
                    <span class="text-gray-300">›</span>
                    <span class="font-semibold text-gray-800">Assets</span>
                </div>

                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="flex items-start gap-4 min-w-0">
                        <div class="flex items-center justify-center w-14 h-14 rounded-3xl bg-[#83211b] text-white shadow-xs shrink-0">
                            <i class="fa-solid fa-box-open text-2xl"></i>
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-gray-900 truncate">{{ __('Assets Management') }}</h2>
                            <p class="mt-1 text-sm text-gray-500 leading-relaxed">Track and manage your team assets</p>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3 w-full md:w-auto">
                        <div class="flex flex-1 min-w-0 gap-2 flex-wrap sm:flex-nowrap items-center">
                            <label class="sr-only" for="assetSearch">Search assets</label>
                            <div class="relative flex-1 min-w-0">
                                <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                                <input id="assetSearch"
                                    type="text"
                                    placeholder="Search assets..."
                                    wire:model.live.debounce.300ms="search"
                                    class="w-full text-sm pl-11 pr-4 h-11 rounded-full border border-gray-200 bg-gray-50 text-gray-700 focus:border-[#83211b] focus:ring-2 focus:ring-[#83211b]/20 outline-hidden transition" />
                            </div>

                            <select wire:model.live.debounce.300ms="filterCategoryId" class="text-sm h-11 rounded-xl border border-gray-200 bg-white px-4 text-gray-700 focus:border-[#83211b] focus:ring-2 focus:ring-[#83211b]/20 outline-hidden transition">
                                <option value="">All Categories</option>
                                @foreach ($this->categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            <select wire:model.live.debounce.300ms="filterState" class="text-sm h-11 rounded-xl border border-gray-200 bg-white px-4 text-gray-700 focus:border-[#83211b] focus:ring-2 focus:ring-[#83211b]/20 outline-hidden transition">
                                <option value="">All States</option>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>

                        <button wire:click="openModal" class="add-btn w-full sm:w-auto">
                            <i class="fa-solid fa-plus"></i>
                            <span>New Asset</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="text-green-500 text-md font-semibold text-center mx-4 mt-2">
            {{ session('success') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="text-red-500 text-md font-semibold text-center mx-4 mt-2">
            {{ session('error') }}
        </div>
    @endif

    <!-- Table -->
    <div class="py-2 table-container">
        <div class="bg-white overflow-hidden sm:rounded-lg">
            <div class="overflow-x-auto overflow-y-auto">
                <table class="w-full border-t" data-exportable data-export-name="assets">
                    <thead>
                        <tr class="table-header text-xs border-b border-gray-200 bg-gray-50">
                            <th class="py-3 px-4 font-semibold text-left w-14"></th>
                            <th class="py-3 px-2 font-semibold text-left">Asset Name</th>
                            <th class="py-3 px-2 font-semibold text-center">Category</th>
                            <th class="py-3 px-2 font-semibold text-center">State</th>
                            <th class="py-3 px-2 font-semibold text-right">Purchased</th>
                            <th class="py-3 px-2 font-semibold text-right">Outward</th>
                            <th class="py-3 px-2 font-semibold text-right whitespace-nowrap">In Stock</th>
                            <th class="py-3 px-2 font-semibold text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="wire-loading">
                        @forelse ($assets as $asset)
                            <tr class="text-xs border-b border-gray-100 hover:bg-gray-50 cursor-pointer transition-colors"
                                onclick="window.location.href='{{ route('assets.view', ['team' => $teamId, 'id' => $asset->id]) }}'">

                                <!-- Thumbnail -->
                                <td class="py-2 px-4">
                                    @php $att = $asset->attachments ? json_decode($asset->attachments, true) : []; @endphp
                                    @if(!empty($att) && isset($att[0]))
                                        <img src="{{ Storage::url($att[0]) }}" alt="thumb"
                                            class="w-10 h-10 object-cover rounded-lg border border-gray-100" />
                                    @else
                                        <div class="w-10 h-10 rounded-lg bg-gray-100 border border-gray-200 flex items-center justify-center">
                                            <i class="fa-solid fa-box text-gray-400 text-sm"></i>
                                        </div>
                                    @endif
                                </td>

                                <!-- Asset Name -->
                                <td class="text-left text-sm py-3 px-2 text-gray-900 capitalize">
                                    {{ $asset->asset_name }}
                                </td>

                                <!-- Category -->
                                <td class="text-center text-sm py-3 px-2 text-gray-900 capitalize">
                                    {{ $asset->assetCategory->name ?? '-' }}
                                </td>

                                <!-- State -->
                                <td class="text-center py-3 px-2">
                                    @if ($asset->state)
                                        <span class="status-badge status-on"><i class="fa-solid fa-circle-check text-[9px]"></i>Active</span>
                                    @else
                                        <span class="status-badge status-off"><i class="fa-solid fa-circle-minus text-[9px]"></i>Inactive</span>
                                    @endif
                                </td>

                                <!-- Numbers -->
                                <td class="text-right text-sm py-3 px-2 tabular-nums text-gray-900">{{ $asset->purchased_total ?? 0 }}</td>
                                <td class="text-right text-sm py-3 px-2 tabular-nums text-gray-900">{{ $asset->outward_total ?? 0 }}</td>
                                <td class="text-right text-sm py-3 px-2 tabular-nums text-gray-900">{{ $asset->in_stock ?? 0 }}</td>

                                <!-- Actions: icon buttons with labels -->
                                <td class="text-center py-3 px-2">
                                    <div class="flex flex-wrap justify-center gap-2">

                                        <!-- View -->
                                        <button
                                            onclick="event.stopPropagation(); window.location.href='{{ route('assets.view', ['team' => $teamId, 'id' => $asset->id]) }}'"
                                            title="View details"
                                            aria-label="View details"
                                            class="cancel-btn btn-xs">
                                            <i class="fa-solid fa-eye"></i>
                                            <span class="hidden sm:inline">View</span>
                                        </button>

                                        <!-- Edit -->
                                        <button
                                            onclick="event.stopPropagation()"
                                            wire:click="edit({{ $asset->id }})"
                                            title="Edit asset"
                                            aria-label="Edit asset"
                                            class="edit-btn btn-xs">
                                            <i class="fa-regular fa-pen-to-square"></i>
                                            <span class="hidden sm:inline">Edit</span>
                                        </button>

                                        <!-- Delete -->
                                        @if(($asset->purchased_total ?? 0) > 0)
                                            <button
                                                type="button"
                                                onclick="event.stopPropagation()"
                                                disabled
                                                title="Can't delete — asset has purchase entries"
                                                aria-label="Delete disabled"
                                                class="delete-btn btn-xs">
                                                <i class="fa-regular fa-trash-can"></i>
                                                <span class="hidden sm:inline">Delete</span>
                                            </button>
                                        @else
                                            <button
                                                onclick="event.stopPropagation(); confirmDeletion({{ $asset->id }})"
                                                title="Delete asset"
                                                aria-label="Delete asset"
                                                class="delete-btn btn-xs">
                                                <i class="fa-regular fa-trash-can"></i>
                                                <span class="hidden sm:inline">Delete</span>
                                            </button>
                                        @endif

                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-12 text-gray-500">
                                    <i class="fa-solid fa-box-open text-3xl mb-3 block text-gray-300"></i>
                                    <span class="text-sm">No assets found</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-3">{{ $assets->links() }}</div>
            </div>
        </div>
    </div>

    @if ($isModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-5">
            <div class="w-full sm:w-[95%] md:w-2/3 lg:w-1/2 xl:w-2/5 overflow-hidden rounded-[28px] border border-[#e5e7eb] bg-white shadow-[0_24px_70px_rgba(15,23,42,0.12)]">
                <div class="flex items-center gap-3 bg-[#83211b] px-5 py-3 text-white">
                    <div class="flex items-center justify-center w-12 h-12 rounded-2xl bg-[#ce7716] text-white text-xl shadow-xs">
                        <i class="fa-solid fa-box-open"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold">{{ $editing ? 'Edit Asset' : 'Add Asset' }}</h3>
                    </div>
                    <button wire:click="closeModal" class="ml-auto text-white hover:text-gray-200 transition">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                <div class="px-5 py-5">
                    <form wire:submit.prevent="{{ $editing ? 'update' : 'store' }}">
                        <div class="grid gap-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 items-start">
                                <label for="assetName" class="text-sm font-semibold text-gray-700">Asset Name</label>
                                <div class="sm:col-span-2">
                                    <input type="text" id="assetName" wire:model="assetName"
                                        class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 shadow-xs outline-hidden focus:border-[#83211b] focus:ring-2 focus:ring-[#83211b]/10"
                                        maxlength="50" />
                                    @error('assetName') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 items-start">
                                <label for="assetCategoryId" class="text-sm font-semibold text-gray-700">Category</label>
                                <div class="sm:col-span-2">
                                    <select id="assetCategoryId" wire:model="assetCategoryId"
                                        class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 shadow-xs outline-hidden focus:border-[#83211b] focus:ring-2 focus:ring-[#83211b]/10">
                                        <option value="">Select a category</option>
                                        @foreach ($this->categories as $cat)
                                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                        @endforeach
                                    </select>
                                    @if($this->categories->isEmpty())
                                        <div class="mt-2 p-3 bg-gray-50 border border-gray-300 rounded-2xl text-sm text-gray-700">
                                            <div class="flex items-start gap-2">
                                                <i class="fa-solid fa-triangle-exclamation text-orange-500 mt-0.5"></i>
                                                <div>
                                                    <p class="font-semibold">No categories found.</p>
                                                    <p class="text-xs text-gray-500">Add a category first to assign this asset.</p>
                                                    <a href="{{ route('assetcategories.index', ['team' => $teamId]) }}" class="inline-flex items-center gap-1 text-[#83211b] font-semibold mt-2 hover:underline">
                                                        Add a category
                                                        <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                    @error('assetCategoryId') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 items-start">
                                <label for="state" class="text-sm font-semibold text-gray-700">State</label>
                                <div class="sm:col-span-2">
                                    <select id="state" wire:model="state"
                                        class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 shadow-xs outline-hidden focus:border-[#83211b] focus:ring-2 focus:ring-[#83211b]/10">
                                        <option value="1">Active</option>
                                        <option value="0">Inactive</option>
                                    </select>
                                    @error('state') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 items-start">
                                <label for="remarks" class="text-sm font-semibold text-gray-700">Remarks</label>
                                <div class="sm:col-span-2">
                                    <textarea id="remarks" wire:model="remarks"
                                        class="w-full min-h-[120px] rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 shadow-xs outline-hidden focus:border-[#83211b] focus:ring-2 focus:ring-[#83211b]/10"
                                        maxlength="65"></textarea>
                                    @error('remarks') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 items-start">
                                <label for="attachments" class="text-sm font-semibold text-gray-700">Upload Image</label>
                                <div class="sm:col-span-2">
                                    <input type="file" id="attachments" wire:model="uploadedFiles"
                                        accept=".jpg,.jpeg,.png,.gif,.tiff,.svg"
                                        class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 shadow-xs outline-hidden"
                                        @if(count($existingAttachments) + count($newAttachments ?? []) >= 1) disabled @endif />
                                    @error('uploadedFiles') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                                    <div class="mt-3 flex flex-wrap gap-3 items-center">
                                        @if(count($existingAttachments) > 0)
                                            <div class="relative w-16 h-16 rounded-xl overflow-hidden border border-gray-200">
                                                <img src="{{ Storage::url($existingAttachments[0]) }}" class="w-full h-full object-cover" alt="attachment" />
                                                <button type="button" wire:click.prevent="removeExistingAttachment(0)"
                                                    class="absolute -top-1 -right-1 bg-red-600 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center" title="Remove">&times;</button>
                                            </div>
                                        @elseif(!empty($newAttachments) && isset($newAttachments[0]))
                                            <div class="relative w-16 h-16 rounded-xl overflow-hidden border border-gray-200">
                                                <img src="{{ $newAttachments[0]->temporaryUrl() }}" class="w-full h-full object-cover" alt="new" />
                                                <button type="button" wire:click.prevent="removeNewAttachment(0)"
                                                    class="absolute -top-1 -right-1 bg-red-600 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center">&times;</button>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row sm:justify-end sm:items-center mt-2">
                                <button type="button" wire:click="closeModal" class="cancel-btn">
                                    Cancel
                                </button>
                                <button type="submit" class="save-btn">
                                    <i class="fa-solid fa-check"></i>
                                    {{ $editing ? 'Update Asset' : 'Add Asset' }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($showViewModal && $selectedAsset)
        {{-- View page now uses a dedicated route; keep modal disabled. --}}
    @endif
</div>

<script>
    function confirmDeletion(id) {
        if (confirm('Are you sure you want to delete this asset? This action cannot be undone.')) {
            @this.call('delete', id);
        }
    }
</script>
