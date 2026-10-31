<div class="p-4">
    <a href="{{ route('assets.index', ['team' => $teamId]) }}" class="text-sm text-blue-600 font-semibold"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Assets</a>

    <div class="mt-4 bg-gray-100 border border-gray-200 rounded-lg p-5">
        <h2 class="text-xl font-semibold mb-4">Asset Summary</h2>
        @php $att = $asset->attachments ? json_decode($asset->attachments, true) : []; @endphp
        <div class="grid grid-cols-5 gap-4 text-sm items-center">
            <div class="flex flex-col items-center">
                @if(!empty($att) && isset($att[0]))
                    <button type="button" wire:click="openImageModal('{{ $att[0] }}')" class="p-0 border-0 bg-transparent mb-2">
                        <img src="{{ Storage::url($att[0]) }}" alt="asset image" class="w-24 h-20 object-cover rounded cursor-pointer" />
                    </button>
                @endif
            </div>
            <div>
                <div class="text-gray-500 mb-1">Asset Name</div>
                <div class="font-medium capitalize">{{ $asset->asset_name }}</div>
            </div>
            <div>
                <div class="text-gray-500 mb-1">Category</div>
                <div class="font-medium capitalize">{{ $asset->assetCategory->name ?? '-' }}</div>
            </div>
            <div>
                <div class="text-gray-500 mb-1">In Stock</div>
                <div class="font-medium">{{ $inStock }}</div>
            </div>
            <div>
                <div class="text-gray-500 mb-1">Remarks</div>
                <div class="font-medium">{{ $asset->remarks ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="mt-3 px-4">
        <div class="space-x-2">
            <button wire:click="openPurchaseCreate" class="add-btn text-sm"><i class="fa-solid fa-plus mr-1"></i> Add Purchase</button>
            <button wire:click="openOutwardCreate" class="add-btn text-sm"><i class="fa-solid fa-plus mr-1"></i> Add Outward</button>
        </div>
    </div>

    <div class="mt-2 px-4 py-2">
        <div class="flex items-center justify-start">
            <div class="inline-flex border rounded-lg overflow-hidden text-sm">
                <button wire:click="switchTab('purchased')"
                        class="px-4 py-2 border-r {{ $activeTab === 'purchased' ? 'bg-blue-50 text-blue-600' : 'bg-white text-gray-900' }}">
                    Purchases
                </button>
                <button wire:click="switchTab('outward')"
                        class="px-4 py-2 border-r {{ $activeTab === 'outward' ? 'bg-blue-50 text-blue-600' : 'bg-white text-gray-900' }}">
                    Outward
                </button>
                <button wire:click="switchTab('returned')"
                        class="px-4 py-2 {{ $activeTab === 'returned' ? 'bg-blue-50 text-blue-600' : 'bg-white text-gray-900' }}">
                    Returned
                </button>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg">
        @if ($activeTab === 'purchased')
            

            @if (session()->has('success'))
                <div class="px-4 py-2 text-center text-green-600 text-sm font-semibold">{{ session('success') }}</div>
            @endif
            @if (session()->has('error'))
                <div class="px-4 py-2 text-center text-red-600 text-sm font-semibold">{{ session('error') }}</div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full table-fixed border-t">
                    <thead>
                        <tr class="table-header text-xs border-b border-gray-300">
                            <th class="py-2 pe-1 font-semibold">Purchased Date</th>
                            <th class="py-2 pe-1 font-semibold">From Company</th>
                            <th class="py-2 pe-1 font-semibold">Price (₹)</th>
                            <th class="py-2 pe-1 font-semibold">Inward Type</th>
                            <th class="py-2 pe-1 font-semibold">Purchased Qty</th>
                            <th class="py-2 pe-1 font-semibold">Remarks</th>
                            <th class="py-2 pe-1 font-semibold w-64">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        @forelse ($purchases as $p)
                            <tr class="text-xs border-b border-gray-300">
                                <td class="text-center text-sm py-3">{{ \Carbon\Carbon::parse($p->purchase_date)->format('d/m/Y') }}</td>
                                <td class="text-center text-sm py-3">{{ $p->from_company }}</td>
                                <td class="text-center text-sm py-3">{{ \App\Services\NumberFormatterService::formatToIndianFormat($p->price ?? 0, true) }}</td>
                                <td class="text-center text-sm py-3">{{ $p->purchase_type }}</td>
                                <td class="text-center text-sm py-3">{{ $p->purchased_quantity }}</td>
                                <td class="text-center text-sm py-3">{{ $p->remarks }}</td>
                                <td class="text-center text-xs py-3 w-64 whitespace-nowrap">
                                    <div class="flex justify-center items-center gap-2">
                                        <button wire:click="openPurchaseEdit({{ $p->asset_purchase_id }})" class="edit-btn btn-xs">
                                            <i class="fa-regular fa-pen-to-square"></i>Edit
                                        </button>
                                        <button onclick="confirmDeletePurchase({{ $p->asset_purchase_id }})" class="delete-btn btn-xs">
                                            <i class="fa-regular fa-trash-can"></i>Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center py-4 text-sm">No records found</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($activeTab === 'outward')
            
            @if (session()->has('success'))
                <div class="px-4 py-2 text-center text-green-600 text-sm font-semibold">{{ session('success') }}</div>
            @endif
            @if (session()->has('error'))
                <div class="px-4 py-2 text-center text-red-600 text-sm font-semibold">{{ session('error') }}</div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full border-t">
                    <thead>
                        <tr class="table-header text-xs border-b border-gray-300">
                            <th class="py-2 pe-1 font-semibold">Outward Date</th>
                            <th class="py-2 pe-1 font-semibold">To Company</th>
                            <th class="py-2 pe-1 font-semibold">Outward type</th>
                            <th class="py-2 pe-1 font-semibold">Outward Qty</th>
                            <th class="py-2 pe-1 font-semibold">Returned Qty</th>
                            <th class="py-2 pe-1 font-semibold">Approved by</th>
                            <th class="py-2 pe-1 font-semibold">Remarks</th>
                            <th class="py-2 pe-1 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        @forelse ($outwards as $o)
                            <tr class="text-xs border-b border-gray-300">
                                <td class="text-center text-sm py-3">{{ \Carbon\Carbon::parse($o->outward_date)->format('d/m/Y') }}</td>
                                <td class="text-center text-sm py-3">{{ $o->to_company }}</td>
                                <td class="text-center text-sm py-3">{{ $o->outward_type }}</td>
                                <td class="text-center text-sm py-3">{{ $o->outward_quantity }}</td>
                                <td class="text-center text-sm py-3">{{ $o->returns->sum('returned_quantity') }}</td>
                                <td class="text-center text-sm py-3">{{ $o->approved_by }}</td>
                                <td class="text-center text-sm py-3">{{ $o->remarks }}</td>
                                <td class="text-center text-sm py-3">
                                    <div class="grid grid-cols-3 gap-2 justify-center items-center">
                                        @php $returnedQty = $o->returns->sum('returned_quantity'); @endphp
                                        @if (in_array($o->outward_type, ['Maintenance','Loaned']) && $returnedQty < $o->outward_quantity)
                                            <button wire:click="openReturnCreate({{ $o->outward_id }})"
                                                class="inline-flex items-center justify-center px-2 py-1 bg-green-500 text-white text-xs font-semibold rounded hover:bg-green-600 whitespace-nowrap">
                                                <i class="fa-solid fa-undo mr-1"></i>Return
                                            </button>
                                        @else
                                            <div></div>
                                        @endif

                                        <button wire:click="openOutwardEdit({{ $o->outward_id }})"
                                            class="inline-flex items-center justify-center px-2 py-1 bg-blue-500 text-white text-xs font-semibold rounded hover:bg-blue-600 whitespace-nowrap">
                                            <i class="fa-regular fa-pen-to-square"></i>Edit
                                        </button>

                                        <button onclick="confirmDeleteOutward({{ $o->outward_id }})"
                                            class="inline-flex items-center justify-center px-2 py-1 bg-red-500 text-white text-xs font-semibold rounded hover:bg-red-600 whitespace-nowrap">
                                            <i class="fa-regular fa-trash-can"></i>Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center py-4 text-sm">No records found</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="flex items-center justify-between px-4">
            </div>

            @if (session()->has('success'))
                <div class="px-4 py-2 text-center text-green-600 text-sm font-semibold">{{ session('success') }}</div>
            @endif
            @if (session()->has('error'))
                <div class="px-4 py-2 text-center text-red-600 text-sm font-semibold">{{ session('error') }}</div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full border-t">
                    <thead>
                        <tr class="table-header text-xs border-b border-gray-300">
                            <th class="py-2 pe-1 font-semibold">Returned Date</th>
                            <th class="py-2 pe-1 font-semibold">Outward Company</th>
                            <th class="py-2 pe-1 font-semibold">Returned Qty</th>
                            <th class="py-2 pe-1 font-semibold">Remarks</th>
                            <th class="py-2 pe-1 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        @forelse ($returns as $r)
                            <tr class="text-xs border-b border-gray-300">
                                <td class="text-center text-sm py-3">{{ \Carbon\Carbon::parse($r->returned_date)->format('d/m/Y') }}</td>
                                <td class="text-center text-sm py-3">{{ $r->outward->to_company ?? '-' }}</td>
                                <td class="text-center text-sm py-3">{{ $r->returned_quantity }}</td>
                                <td class="text-center text-sm py-3">{{ $r->remarks }}</td>
                                <td class="text-center text-xs py-3">
                                    <div class="flex justify-center items-center gap-2">
                                        <button wire:click="openReturnEdit({{ $r->return_id }})" class="edit-btn btn-xs">
                                            <i class="fa-regular fa-pen-to-square"></i>Edit
                                        </button>
                                        <button onclick="confirmDeleteReturn({{ $r->return_id }})" class="delete-btn btn-xs">
                                            <i class="fa-regular fa-trash-can"></i>Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center py-4 text-sm">No records found</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    @if ($showPurchaseModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-5">
            <div class="bg-white sm:w-full md:w-2/3 lg:w-1/2 h-auto p-4 rounded-lg overflow-y-auto">
                <div class="flex justify-between items-center mb-3">
                    <button wire:click="closePurchaseModal" class="text-red-500 hover:text-red-700 text-lg">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                @livewire('pulse.assets.asset-purchase-create-and-edit', ['assetId' => $assetId, 'purchaseId' => $purchaseId, 'asModal' => true], key('purchase-'.$assetId.'-'.$purchaseId))
            </div>
        </div>
    @endif

    @if ($showOutwardModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-5">
            <div class="bg-white sm:w-full md:w-2/3 lg:w-1/2 h-auto p-4 rounded-lg overflow-y-auto">
                <div class="flex justify-between items-center mb-3">
                    <button wire:click="closeOutwardModal" class="text-red-500 hover:text-red-700 text-lg">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                @livewire('pulse.assets.asset-outward-create-and-edit', ['assetId' => $assetId, 'outwardId' => $outwardId, 'asModal' => true], key('outward-'.$assetId.'-'.$outwardId))
            </div>
        </div>
    @endif

    @if ($showReturnModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-5">
            <div class="bg-white sm:w-full md:w-2/3 lg:w-1/2 h-auto p-4 rounded-lg overflow-y-auto">
                <div class="flex justify-between items-center mb-3">
                    <button wire:click="closeReturnModal" class="text-red-500 hover:text-red-700 text-lg">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                @livewire('pulse.assets.asset-returned-create-and-edit', ['outwardId' => $outwardId, 'returnId' => $returnId, 'asModal' => true], key('return-'.$assetId.'-'.$returnId.'-'.$outwardId))
            </div>
        </div>
    @endif
    @if($showImageModal && $imageUrl)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70">
            <div class="bg-white rounded p-4 max-w-3xl w-full">
                <div class="flex justify-end mb-2">
                    <button wire:click="closeImageModal" class="text-red-600 text-xl font-semibold">&times;</button>
                </div>
                <div class="flex justify-center">
                    <img src="{{ $imageUrl }}" alt="image" class="max-h-[80vh] object-contain" />
                </div>
            </div>
        </div>
    @endif
</div>
<script>
    function confirmDeletePurchase(id) {
        if (confirm('Are you sure you want to delete this record?')) {
            @this.call('deletePurchase', id);
        }
    }
    function confirmDeleteOutward(id) {
        if (confirm('Are you sure you want to delete this record?')) {
            @this.call('deleteOutward', id);
        }
    }
    function confirmDeleteReturn(id) {
        if (confirm('Are you sure you want to delete this record?')) {
            @this.call('deleteReturn', id);
        }
    }
</script>