<div class="h-full bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] px-4 pt-4 pb-0 flex flex-col justify-center relative" id="hourlyMachineStatesWidget">
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-base font-semibold text-gray-800">Hourly Machine States</h3>
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-3 text-xs font-medium text-gray-600">
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-xs bg-[#22c55e]"></span>
                    ON
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-xs bg-[#D1D5DB]"></span>
                    OFF
                </div>
            </div>
            <button id="resetHourlyMachineStates" type="button" class="text-xs font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 px-3 py-1 rounded z-10" style="display: none;">Reset</button>
        </div>
    </div>
    <hr class="border-t border-gray-400 my-2">
    <div id="machineStateChart"></div>

    <p id="machineStateEmpty" class="hidden text-sm text-gray-700 mt-2 pb-4">
        No data available for the selected duration.
    </p>
</div>

@push('scripts')
<script>
    function renderHourlyMachineStatesChart(data, selectedDate) {
        const widget = document.getElementById('hourlyMachineStatesWidget');
        const chartElement = document.querySelector("#machineStateChart");
        const emptyMessage = document.getElementById('machineStateEmpty'); // Get the new element

        if (!widget || !chartElement || !emptyMessage) {
            console.error('Required chart elements not found');
            return;
        }

        const resetButton = document.getElementById('resetHourlyMachineStates');
        if (resetButton) {
            resetButton.onclick = function () {
                if (window.machineStateChart && typeof window.machineStateChart.destroy === 'function') {
                    window.machineStateChart.destroy();
                }
                chartElement.innerHTML = '';
                resetButton.style.display = 'none';
                renderHourlyMachineStatesChart(data, selectedDate);
            };
        }

        widget.style.display = 'block';

        // Only count actual machine keys (not shift_start/shift_end metadata).
        // Canonical order: Defibre, Baling, Blocks — Baling must render above Blocks.
        const machineKeys = ['defibre', 'baling', 'blocks'];
        const hasData = data && machineKeys.some(m => data[m] && Array.isArray(data[m]) && data[m].length > 0);

        // Destroy previous chart if it exists
        if (window.machineStateChart && typeof window.machineStateChart.destroy === 'function') {
            window.machineStateChart.destroy();
            window.machineStateChart = null;
            chartElement.innerHTML = '';
        }

        if (!hasData) {
            // Show empty message, hide chart container
            chartElement.style.display = 'none';
            emptyMessage.classList.remove('hidden');
            return;
        }

        // Hide empty message, show chart container
        emptyMessage.classList.add('hidden');
        chartElement.style.display = 'block';

        window.machineStateChart = initMachineStateChart(data, chartElement, selectedDate);
    }

    function initMachineStateChart(data, chartElement, selectedDate) {
        if (!chartElement) return null;
        const _date = selectedDate ? new Date(selectedDate) : new Date();

        // Use shift boundaries from data if available, otherwise default to midnight
        const dayStart = data.shift_start ? new Date(data.shift_start).getTime() : new Date(_date).setHours(0,0,0,0);
        const dayEnd   = data.shift_end ? new Date(data.shift_end).getTime() : new Date(_date).setHours(23,59,59,999);
        const renderEnd = data.current_time ? new Date(data.current_time).getTime() : dayEnd;

        const machineLabels = { 'defibre':'Defibre','baling':'Baling','blocks':'Blocks' };

        // Only include machines that exist in the data object (submodule-aware).
        // Canonical order: Defibre, Baling, Blocks — not the backend's key order —
        // so Baling always renders above Blocks.
        const machineKeys = ['defibre', 'baling', 'blocks'];
        const machines = machineKeys.filter(m => data[m]);

        // Helper: format ms timestamp to "h:mm am/pm"
        function fmtTime(ms) {
            const d = new Date(ms);
            return d.toLocaleTimeString([], {hour:'numeric', minute:'2-digit', hour12:true});
        }

        const allData = [];
        machines.forEach((machine) => {
            const events = (data[machine] || [])
                .slice()
                .sort((a, b) => {
                    const ta = typeof a.timestamp === 'number' ? a.timestamp : new Date(a.timestamp).getTime();
                    const tb = typeof b.timestamp === 'number' ? b.timestamp : new Date(b.timestamp).getTime();
                    return ta - tb;
                });
            let segmentCount = 0;

            for (let i = 0; i < events.length; i++) {
                let start = typeof events[i].timestamp === 'number' ? events[i].timestamp : new Date(events[i].timestamp).getTime();
                let state = events[i].state;
                let end;

                if (i + 1 < events.length) {
                    end = typeof events[i+1].timestamp === 'number' ? events[i+1].timestamp : new Date(events[i+1].timestamp).getTime();
                } else {
                    end = renderEnd;
                }

                if (end > start) {
                    allData.push({
                        x: machineLabels[machine],
                        y: [start, end],
                        state: state,
                        fillColor: state === 'on' ? '#22c55e' : '#D1D5DB',
                        segmentTotal: events.length
                    });
                    segmentCount++;
                }
            }

            if (segmentCount === 0) {
                allData.push({
                    x: machineLabels[machine],
                    y: [dayStart, renderEnd],
                    state: 'off',
                    fillColor:'#D1D5DB'
                });
            }
        });

        const series = [{ data: allData }];

        // Scale chart height based on number of machines (76px per row, min 130)
        const chartHeight = Math.max(130, machines.length * 76);

        // Format shift start/end for annotation labels
        const shiftStartLabel = fmtTime(dayStart);
        const shiftEndLabel   = fmtTime(dayEnd);

        const options = {
            chart: {
                type:'rangeBar',
                height: chartHeight,
                zoom: { enabled: true },
                toolbar: { show: false },
                background:'#fff',
                events: {
                    zoomed: function() {
                        const btn = document.getElementById('resetHourlyMachineStates');
                        if (btn) btn.style.display = 'block';
                    },
                    selection: function() {
                        const btn = document.getElementById('resetHourlyMachineStates');
                        if (btn) btn.style.display = 'block';
                    }
                }
            },
            plotOptions: {
                bar:{
                    horizontal:true,
                    barHeight:'55%',
                    dataLabels: {
                        hideOverflowingLabels: true
                    }
                }
            },
            // Prevent bars from dimming/turning white when another segment is hovered
            states: {
                hover:  { filter: { type: 'none' } },
                active: { filter: { type: 'none' }, allowMultipleDataPointsSelection: false }
            },
            fill: { opacity: 1 },
            series: series,
            dataLabels: {
                enabled: true,
                offsetY: 3,
                offsetX: 0,
                textAnchor: 'middle',
                formatter: function(val, opts) {
                    // val is [start, end] for rangeBar
                    const point = opts.w.config.series[opts.seriesIndex].data[opts.dataPointIndex];
                    if (!point || point.state === 'on') return '';
                    // Rows that toggle frequently never have room for a clean label; skip them entirely
                    const MAX_SEGMENTS_FOR_LABEL = 5;
                    if (point.segmentTotal && point.segmentTotal > MAX_SEGMENTS_FOR_LABEL) return '';
                    const durationMs = val[1] - val[0];
                    const durationMins = Math.round(durationMs / 60000);
                    const hrs = Math.floor(durationMins / 60);
                    const mins = durationMins % 60;
                    const durationStr = (hrs > 0 ? hrs + 'h ' : '') + (mins > 0 ? mins + 'm' : '');
                    return durationStr.trim() || '0m';
                },
                style: {
                    fontSize: '11px',
                    fontWeight: '600',
                    colors: ['#374151']
                }
            },
            xaxis: {
                type:'datetime',
                min: dayStart,
                max: dayEnd,
                tickAmount: 6,
                labels: {
                    formatter: function(value) {
                        const d = new Date(value);
                        const h = d.getHours();
                        const m = d.getMinutes();
                        const ampm = h < 12 ? 'am' : 'pm';
                        const hour = h % 12 || 12;
                        return hour + (m ? ':' + String(m).padStart(2,'0') : '') + ampm;
                    },
                    style:{colors:'#6B7280', fontSize:'10px'},
                    rotate: 0,
                    hideOverlappingLabels: false
                }
            },
            // Mark shift start and shift end with vertical lines
            annotations: {
                xaxis: [
                    {
                        x: dayStart,
                        borderColor: '{{ config('colors.pith.total') }}',
                        strokeDashArray: 0,
                        label: {
                            text: 'Start ' + shiftStartLabel,
                            style: { color:'#fff', background:'{{ config('colors.pith.total') }}', fontSize:'10px', padding:{left:4,right:4,top:2,bottom:2} },
                            orientation: 'horizontal',
                            position: 'bottom',
                            textAnchor: 'start',
                            offsetY: 6,
                        }
                    },
                    {
                        x: dayEnd,
                        borderColor: '#6B7280',
                        strokeDashArray: 0,
                        label: {
                            text: 'End ' + shiftEndLabel,
                            style: { color:'#fff', background:'#6B7280', fontSize:'10px', padding:{left:4,right:4,top:2,bottom:2} },
                            orientation: 'horizontal',
                            position: 'bottom',
                            textAnchor: 'end',
                            offsetY: 6,
                        }
                    }
                ]
            },
            yaxis: {
                labels:{ style:{ fontSize:'13px', fontWeight:'500', colors:'#374151' } }
            },
            tooltip: {
                shared: false,
                followCursor: true,
                custom: function({ series, seriesIndex, dataPointIndex, w }) {
                    const point = w.config.series[seriesIndex].data[dataPointIndex];
                    if (!point) return '';
                    const startTime = fmtTime(point.y[0]);
                    const endTime   = fmtTime(point.y[1]);
                    const state = point.state || 'off';
                    const durationMs = point.y[1] - point.y[0];
                    const durationMins = Math.round(durationMs / 60000);
                    const hrs  = Math.floor(durationMins / 60);
                    const mins = durationMins % 60;
                    const durationStr = (hrs > 0 ? hrs + 'h ' : '') + (mins > 0 ? mins + 'm' : '');
                    const finalDurationStr = durationStr.trim() || '0m';
                    const stateColor = state === 'on' ? '#22c55e' : '#9CA3AF';
                    const stateBg    = state === 'on' ? '#f0fdf4' : '#f9fafb';
                    const stateBadge = `<span style="display:inline-block;padding:2px 8px;border-radius:9999px;background:${stateColor};color:#fff;font-weight:600;font-size:12px;letter-spacing:.06em;">${state.toUpperCase()}</span>`;
                    return `<div style="padding:10px 13px;font-size:12px;line-height:1.7;background:${stateBg};border:1px solid ${stateColor}22;border-radius:6px;min-width:140px;">
                        <div style="font-weight:600;color:#111;margin-bottom:4px;">${point.x}</div>
                        <div>${stateBadge}</div>
                        <div style="color:#6B7280;margin-top:5px;font-size:12px;font-variant-numeric:tabular-nums;">${startTime} → ${endTime}</div>
                        <div style="color:#6B7280;font-size:12px;font-variant-numeric:tabular-nums;">${finalDurationStr}</div>
                    </div>`;
                }
            },
            grid: { borderColor:'#E5E7EB', xaxis:{lines:{show:true}}, yaxis:{lines:{show:false}} },
            legend:{ show:false }
        };

        const chart = new ApexCharts(chartElement, options);
        chart.render();
        return chart;
    }

    // Event listeners
    window.removeEventListener('initCharts', initChartsHandler);
    window.removeEventListener('updateCharts', updateChartsHandler);

    function initChartsHandler(event) {
        if (event.detail.hourlyMachineState) renderHourlyMachineStatesChart(event.detail.hourlyMachineState);
    }
    function updateChartsHandler(event) {
        if (event.detail.hourlyMachineState) renderHourlyMachineStatesChart(event.detail.hourlyMachineState);
    }

    window.addEventListener('initCharts', initChartsHandler);
    window.addEventListener('updateCharts', updateChartsHandler);
</script>
@endpush
