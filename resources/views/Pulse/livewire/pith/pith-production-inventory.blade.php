@include('pulse::livewire.pith.partials.inventory-modal', [
    'modalTitle' => 'Inventory · ' . \Carbon\Carbon::parse($productionShiftDate)->format(config('app.date_format')) . ' (' . $productionShiftName . ')',
    'closeAction' => 'closeInventoryModal',
    'errorKey' => 'totalError',
    'totalCft' => $productionLoadsTotal,
])
