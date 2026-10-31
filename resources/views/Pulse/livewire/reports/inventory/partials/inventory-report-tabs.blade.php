@php
    $currentTeam = auth()->user()?->activeTeam() ?? auth()->user()?->currentTeam;
    $dateQuery = [
        'team' => $teamId,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'preset' => $presetLabel,
    ];
    $tabClass = fn (string $tab) => $activeTab === $tab
        ? 'py-1 text-[#7a221e] border-b-2 border-[#7a221e]'
        : 'py-1 text-gray-500 hover:text-gray-800';
@endphp
<div class="flex items-center gap-6 text-sm font-semibold">
    @if ($currentTeam?->has_pith_module && auth()->user()->can('pith_inventory.inventory_report'))
        <a href="{{ route('reports.inventory.pith', $dateQuery) }}" class="{{ $tabClass('pith') }}">Raw Pith</a>
    @endif
    @if ($currentTeam?->has_pith_module && auth()->user()->can('husk_inventory.inventory_report'))
        <a href="{{ route('reports.inventory.husk', $dateQuery) }}" class="{{ $tabClass('husk') }}">Husk</a>
    @endif
    @if ($currentTeam?->has_pith_module_bales && auth()->user()->can('view-bale-inventory'))
        <a href="{{ route('reports.inventory.bales', $dateQuery) }}" class="{{ $tabClass('bales') }}">Fibre Bales</a>
    @endif
    @if ($currentTeam?->has_pith_module_blocks && auth()->user()->can('view-block-inventory'))
        <a href="{{ route('reports.inventory.blocks', $dateQuery) }}" class="{{ $tabClass('blocks') }}">Pith Blocks</a>
    @endif
</div>
