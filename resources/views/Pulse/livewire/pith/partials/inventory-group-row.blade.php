{{--
    Group roll-up row on the Pith Inventory list. Clicking it opens or closes the group.

    Expects: $group (from PithInventory::buildGroups()), $isExpanded, $isFull (list not narrowed
    by an open location panel), $num, $pct
--}}
@php
    $groupIn = round($group['totalOffloaded']);
    $groupOut = round($group['totalTaken']);
    $groupPct = $pct($groupOut, $groupIn);
@endphp
<div role="button" tabindex="0" wire:key="group-{{ $group['id'] }}" wire:click="toggleGroup({{ $group['id'] }})"
    class="pi-grid {{ $isFull ? 'pi-cols-full' : 'pi-cols-compact' }} pi-grp">
    <div class="pi-grp-name">
        <span class="pi-arrow">{{ $isExpanded ? '▾' : '▸' }}</span>
        <span>{{ $group['name'] }}</span>
        <span class="pi-count">{{ $group['locationCount'] }}</span>
    </div>
    @if ($isFull)
        <div class="pi-num">{{ $groupIn > 0 ? $num($groupIn) : '—' }}</div>
        <div class="pi-out-cell">
            <span>{{ $groupOut > 0 ? $num($groupOut) : '—' }}</span>
            <span class="pi-mini">
                <span class="pi-mini-track"><span style="width: {{ $groupPct }}%"></span></span>
                <span class="pi-mini-pct">{{ $groupOut > 0 ? $groupPct.'%' : '' }}</span>
            </span>
        </div>
    @endif
    <div class="pi-num">{{ $groupIn > 0 ? $num($group['totalInStock']) : '—' }}</div>
    <div class="pi-num pi-water-val">{{ $group['totalWaterLitres'] > 0 ? $num($group['totalWaterLitres']).' L' : '—' }}</div>
    @if ($isFull)
        <div></div>
    @endif
</div>
