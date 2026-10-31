@props([
    'unit' => 'units',
    'opening' => 0,
    'produced' => 0,
    'lastEntryText' => null,
])

@php
    $fmt = fn ($value) => \App\Services\NumberFormatterService::formatToIndianFormat(round($value), false, 0);
@endphp

<div class="prod-section"
     x-data="{
        soldQty: @entangle('soldQuantity'),
        amount: @entangle('correctionAmount'),
        type: @entangle('correctionType'),
        comment: @entangle('correctionComment'),
        opening: {{ (int) $opening }},
        produced: {{ (int) $produced }},
        get signed() {
            const value = Number(this.amount || 0);
            return this.type === 'deduct' ? -value : value;
        },
        get resulting() {
            return this.opening + this.produced - Number(this.soldQty || 0) + this.signed;
        },
        fmt(n) { return Math.round(n).toLocaleString('en-IN'); },
        bump(step) { this.soldQty = Number(this.soldQty || 0) + step; },
        clearCorrection() { this.amount = null; this.comment = null; }
     }"
     x-effect="opening = {{ (int) $opening }}; produced = {{ (int) $produced }}">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <div class="prod-section-title mb-0">Stock movement</div>
            <p class="text-xs text-gray-500 mt-1">
                Opening {{ $fmt($opening) }} + produced {{ $fmt($produced) }} {{ $unit }}
            </p>
        </div>
        <div class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs text-gray-600">
            <i class="fa-solid fa-box text-[#861e1a]"></i>
            <span>Daily ledger for this production date</span>
        </div>
    </div>

    <div class="space-y-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div>
            <label for="soldQuantity" class="text-sm font-semibold text-gray-900">Sold</label>
            <div class="relative mt-1.5">
                <input type="number" x-model="soldQty" id="soldQuantity" min="0" required
                       class="input-type w-full text-lg font-semibold pr-16" x-on:focus="$event.target.select()" />
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none">{{ $unit }}</span>
            </div>
            @if ($lastEntryText)
                <p class="text-xs text-gray-500 mt-1">{{ $lastEntryText }}</p>
            @endif
            @error('soldQuantity')
                <span class="text-red-500 text-xs">{{ $message }}</span>
            @enderror

            <div class="grid grid-cols-4 gap-2 mt-2.5">
                @foreach ([10, 50, 100] as $step)
                    <button type="button" @click="bump({{ $step }})"
                            class="py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors text-center">
                        +{{ number_format($step) }}
                    </button>
                @endforeach
                <button type="button" @click="soldQty = 0"
                        class="py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors text-center">
                    None
                </button>
            </div>
        </div>

        <div class="bg-gray-50 rounded-2xl p-4 border border-gray-100">
            <p class="text-sm font-semibold text-gray-900 mb-3">
                Stock correction <span class="font-normal text-gray-500">(optional)</span>
            </p>

            <div class="grid grid-cols-2 rounded-lg overflow-hidden border border-gray-300 mb-3">
                <button type="button" @click="type = 'add'"
                        :class="type === 'add' ? 'bg-[#861e1a] text-white' : 'bg-white text-gray-700 hover:bg-gray-100'"
                        class="py-2.5 text-sm font-semibold transition-colors">Add</button>
                <button type="button" @click="type = 'deduct'"
                        :class="type === 'deduct' ? 'bg-[#861e1a] text-white' : 'bg-white text-gray-700 hover:bg-gray-100'"
                        class="py-2.5 text-sm font-semibold transition-colors border-l border-gray-300">Deduct</button>
            </div>

            <div class="relative">
                <input type="number" x-model="amount" min="0" placeholder="0"
                       class="input-type w-full text-lg font-semibold pr-16 bg-white" x-on:focus="$event.target.select()" />
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none">{{ $unit }}</span>
            </div>
            @error('correctionAmount')
                <span class="text-red-500 text-xs">{{ $message }}</span>
            @enderror

            <textarea x-model="comment" rows="2" placeholder="Reason for the correction (optional)"
                      class="input-type w-full text-sm mt-3 bg-white resize-y"></textarea>
            @error('correctionComment')
                <span class="text-red-500 text-xs">{{ $message }}</span>
            @enderror

            <button type="button" @click="clearCorrection()"
                    class="mt-2 text-xs text-gray-500 hover:text-gray-800 transition-colors">Clear correction</button>
        </div>

        <div class="rounded-xl px-4 py-3 flex items-center justify-between gap-3"
             :class="resulting < 0 ? 'bg-rose-50 border border-rose-100' : 'bg-green-50 border border-green-100'">
            <span class="text-sm font-semibold" :class="resulting < 0 ? 'text-rose-700' : 'text-green-700'">Resulting stock</span>
            <span class="text-lg font-semibold tabular-nums text-right" :class="resulting < 0 ? 'text-rose-800' : 'text-green-800'">
                <span x-text="fmt(resulting)"></span> {{ $unit }}
                <span class="text-sm font-semibold" x-show="signed !== 0"
                      x-text="'(' + (signed > 0 ? '+' : '−') + fmt(Math.abs(signed)) + ')'"></span>
            </span>
        </div>
        <p class="text-xs text-rose-600 flex items-center gap-1.5 -mt-2" x-show="resulting < 0">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>This leaves stock negative — check the sold figure or add a correction.</span>
        </p>
    </div>
</div>
