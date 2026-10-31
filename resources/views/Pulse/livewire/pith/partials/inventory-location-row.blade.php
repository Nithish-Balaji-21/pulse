{{--
    One location on the Pith Inventory list. Clicking it opens the location panel beside the list.

    Expects: $location (a row of PithInventoryController::index()), $selectedLocationId, $isFull,
    $indented, $num, $pct
--}}
@php
    $in = round((float) ($location->totalLoadsOffloaded ?? 0));
    $out = round((float) ($location->totalLoadsTaken ?? 0));
    $stock = $in - $out;
    $water = (int) ($location->totalWaterLitres ?? 0);
    $rowPct = $pct($out, $in);
    $isSelected = (int) $selectedLocationId === (int) $location->location_id;
    $lastWatered = ! empty($location->lastWateredDate) ? \Illuminate\Support\Carbon::parse($location->lastWateredDate)->format('d M') : '—';
@endphp
<div role="button" tabindex="0" wire:key="location-{{ $location->location_id }}" wire:click="selectLocation({{ $location->location_id }})"
    class="pi-grid {{ $isFull ? 'pi-cols-full' : 'pi-cols-compact' }} pi-row {{ $indented ? '' : 'pi-row--flat' }} {{ $isSelected ? 'is-selected' : '' }}">
    <div class="pi-row-name">
        <span>{{ $location->name }}</span>
        @unless ($location->is_active)
            <span class="pi-tag pi-tag--inactive">{{ __('Inactive') }}</span>
        @endunless
    </div>
    @if ($isFull)
        <div class="pi-num pi-soft">{{ $in > 0 ? $num($in) : '—' }}</div>
        <div class="pi-out-cell pi-soft">
            <span>{{ $out > 0 ? $num($out) : '—' }}</span>
            <span class="pi-mini">
                <span class="pi-mini-track"><span style="width: {{ $rowPct }}%"></span></span>
                <span class="pi-mini-pct">{{ $out > 0 ? $rowPct.'%' : '' }}</span>
            </span>
        </div>
    @endif
    <div class="pi-num pi-strong {{ $stock < 0 ? 'is-red' : '' }}" @if ($stock < 0) title="{{ __('Overdrawn') }}" @endif>{{ $in > 0 || $stock < 0 ? $num($stock) : '—' }}</div>
    <div class="pi-num pi-water-val">{{ $water > 0 ? $num($water).' L' : '—' }}</div>
    @if ($isFull)
        <div class="pi-num is-muted">{{ $lastWatered }}</div>
    @endif
</div>
