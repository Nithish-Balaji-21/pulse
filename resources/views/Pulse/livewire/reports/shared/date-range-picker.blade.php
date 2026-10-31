<div>
@once
    <link rel="stylesheet" href="@assetVersion('css/date-range-selector.css')">
    <script src="@assetVersion('js/daterangeselector.js')"></script>
@endonce

<div wire:ignore
    x-data
    x-init="initReportsRangeSelector({
        startDate: @js($startDate),
        endDate: @js($endDate),
        onApply: (s, e, p) => $wire.call('updateDates', s, e, p)
    })">
    @include('pulse::dashboard.date-range-selector')
</div>
</div>
