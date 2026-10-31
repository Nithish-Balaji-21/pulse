<div class="h-full bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] px-3 pt-3">
    <h3 class="text-base font-semibold text-gray-800 mb-2">Machine States</h3>
    <hr class="border-t border-gray-400 my-2">
    <div id="device-states-list"></div>
    <p id="device-states-empty" class="hidden text-sm text-gray-700 mt-2 pb-4">No data available for the selected duration.</p>
</div>

<script>
    const stateColors = @json(config('colors.states'));
    function formatDuration(seconds) {
        seconds = Math.floor(seconds);
        if (seconds < 3600) {
            // Less than 1 hour: min:sec
            let m = Math.floor(seconds / 60);
            let s = seconds % 60;
            return `${m}m ${s}s`;
        } else if (seconds < 86400) {
            // Less than 1 day: hours:min
            let h = Math.floor(seconds / 3600);
            let m = Math.floor((seconds % 3600) / 60);
            return `${h}h ${m}m`;
        } else {
            // 1 day or more: days:hours
            let d = Math.floor(seconds / 86400);
            let h = Math.floor((seconds % 86400) / 3600);
            return `${d}d ${h}h`;
        }
    }

    function renderDeviceStatesChart(data) {
        var container = document.getElementById('device-states-list');
        var emptyMessage = document.getElementById('device-states-empty');
        container.innerHTML = '';

        var states = {
            'efficient': {label: 'Efficient', color: stateColors.efficient},
            'idle':      {label: 'Idle',      color: stateColors.idle},
            'overload':  {label: 'Overload',  color: stateColors.overload},
            'off':       {label: 'Off',       color: stateColors.off},
            'shutdown':  {label: 'Shutdown',  color: stateColors.shutdown},
            'missing':   {label: 'Data Missing', color: stateColors.missing},
        };

        var total = Object.values(data).reduce((a, b) => a + parseFloat(b), 0);
        var stateOrder = ['efficient','idle','overload','off','shutdown','missing'];
        var hasData = false;

        const fragment = document.createDocumentFragment();

        for (const state of stateOrder) {
            if (data[state] !== undefined && parseFloat(data[state]) > 0) {
                hasData = true;
                var value = parseFloat(data[state]);
                var percent = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                var duration = formatDuration(value);
                var barColor = states[state]?.color;
                var label = states[state]?.label || state;
                var row = document.createElement('div');

                row.className = 'flex items-center justify-between mb-3';
                const pillBgColor = @json(config('colors.pith.total'));

                row.innerHTML = `
                    <!-- Left Section: Label and Duration -->
                    <div class="flex flex-col w-20 min-w-[80px] max-w-[30%]">
                        <span class="text-sm font-medium text-gray-900">${label}</span>
                        <!-- Use text-xs for duration to match the screenshot -->
                        <span class="text-xs text-gray-500">${duration}</span>
                    </div>

                    <!-- Middle Section: Progress Bar -->
                    <div class="grow mx-4 max-w-[45%]">
                        <div class="w-full h-1.5 bg-gray-200 rounded-full">
                            <div style="width:${percent}%; background:${barColor};" class="h-1.5 rounded-full transition-all"></div>
                        </div>
                    </div>

                    <!-- Right Section: Percentage in a Pill -->
                    <div class="w-16 min-w-[50px] max-w-[25%] flex justify-end">
                        <span class="text-sm font-semibold text-gray-50 px-2 py-1 rounded-md text-center w-16"
                              style="background-color: ${pillBgColor};">
                            ${percent}%
                        </span>
                    </div>
                `;
                fragment.appendChild(row);
            }
        }

        container.appendChild(fragment);
        emptyMessage.classList.toggle('hidden', hasData);
    }
</script>