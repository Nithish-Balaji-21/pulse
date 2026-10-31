<div id="pithLoadsShiftWidget" class="h-full bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] px-3 pt-3" style="display: none;">
    <h3 class="text-base font-semibold text-gray-800 mb-2">Pith by Shift</h3>

    <hr class="border-t border-gray-400 my-2">
    <div id="pithLoadsShiftContainer">
        <table class="w-full text-sm text-left text-gray-500">
            <tbody id="pithLoadsShiftRows"></tbody>
        </table>
    </div>

    <p id="pithLoadsShiftNoData" class="hidden text-sm text-gray-700 mt-2 pb-4">
        No data available for the selected duration.
    </p>
</div>

<script>
    // Global variable to hold ApexCharts instances
    var pithLoadsShiftCharts = {};
    const pithLoadColors = @json(config('colors.pith_load_charts'));
    window.showActuals = {{ auth()->user()->currentTeam->showActuals() ? 'true' : 'false' }};

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const getPeriodId = (period) => {
        const base = String(period ?? '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');

        return base || 'period';
    };

    // Creates the ApexCharts options object for a radial bar chart.
    const createDonutChartOptions = (actual, expected) => {
        if (!showActuals) {
            return null; // Nothing to compare — Actuals are off for this team
        }

        const actualPercent = expected > 0 ? Math.min(100, (actual / expected) * 100) : 0;
        const expectedPercent = 100 - actualPercent;

        return {
            series: [actualPercent, expectedPercent],
            chart: {
                width: 64,
                height: 64,
                type: 'donut',
                sparkline: { enabled: true },
                parentHeightOffset: 0,
            },
            labels: ['Actuals', 'Estimated'],
            colors: [pithLoadColors.actual, pithLoadColors.expected],
            plotOptions: {
                pie: {
                    donut: {
                        size: '60%',
                        labels: {
                            show: false
                        }
                    }
                }
            },
            tooltip: {
                enabled: false
            }
        };
    };

    // Renders or updates the chart and metrics for a single period.
    const updateShiftChart = (periodKey, data) => {
        const actual = data?.actual ?? 0;
        const expected = data?.expected ?? 0;
        const ebPrice = data?.eb_price ?? 0;

        const prefix = `period-${periodKey}`;
        const chartId = `${prefix}DonutChart`;
        const chartContainer = document.getElementById(chartId)?.parentElement;

        if (expected !== null && expected !== undefined) {
            const compactE = formatToIndianNumberWithHover(expected);
            const fullE = formatToIndianNumberNormal(expected, 2);
            $(`#${prefix}ExpectedLoads`).html(compactE);
            // set title on label and value wrapper
            const expEl = document.getElementById(`${prefix}ExpectedLoads`);
            if (expEl) expEl.title = 'Estimated: ' + fullE + ' cft';
            const expLabelContainer = expEl?.previousElementSibling; // inner div
            if (expLabelContainer) expLabelContainer.title = 'Estimated: ' + fullE + ' cft';
        }

        if (showActuals) {
            const compactA = formatToIndianNumberWithHover(actual);
            const fullA = formatToIndianNumberNormal(actual, 2);
            $(`#${prefix}ActualLoads`).html(compactA);
            const actEl = document.getElementById(`${prefix}ActualLoads`);
            if (actEl) actEl.title = 'Actuals: ' + fullA + ' cft';
            const actLabelContainer = actEl?.previousElementSibling; // inner div
            if (actLabelContainer) actLabelContainer.title = 'Actuals: ' + fullA + ' cft';
        }

        const formattedEbPrice = '₹' + (typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(ebPrice) : ebPrice);
        $(`#${prefix}EbPrice`).text(formattedEbPrice);
        $(`#${prefix}EbPrice`).attr('title', '');

        // Skip chart rendering when Actuals are off — nothing to compare
        if (!showActuals || !chartContainer) {
            return;
        }

        // Hide chart container if both values are 0
        if (actual === 0 && expected === 0) {
            chartContainer.style.display = 'none';
            // Destroy existing chart if it exists
            if (pithLoadsShiftCharts[periodKey]) {
                pithLoadsShiftCharts[periodKey].destroy();
                delete pithLoadsShiftCharts[periodKey];
            }
            return;
        }

        // Show chart container if it was previously hidden
        chartContainer.style.display = 'block';

        // Render/Update Chart
        const chartOptions = createDonutChartOptions(actual, expected);

        if (!chartOptions) return;

        if (pithLoadsShiftCharts[periodKey]) {
            // Update existing chart
            pithLoadsShiftCharts[periodKey].updateOptions(chartOptions);
        } else {
            // Create new chart
            const chartElement = document.getElementById(chartId);
            if (chartElement) {
                pithLoadsShiftCharts[periodKey] = new ApexCharts(chartElement, chartOptions);
                pithLoadsShiftCharts[periodKey].render();
            }
        }
    };

    // Main function to render the shift data widget.
    function renderPithLoadsShiftChart(shiftsData) {
        const container = $('#pithLoadsShiftContainer');
        const noData = $('#pithLoadsShiftNoData');
        const widget = $('#pithLoadsShiftWidget');
        const rowsContainer = document.getElementById('pithLoadsShiftRows');

        // Destroy existing charts before re-rendering
        Object.values(pithLoadsShiftCharts).forEach((chart) => chart.destroy());
        pithLoadsShiftCharts = {};
        rowsContainer.innerHTML = '';

        const periods = Object.entries(shiftsData || {})
            .filter(([, data]) => (data?.expected > 0 || data?.actual > 0 || data?.eb_price > 0));

        const hasData = periods.length > 0;

        if (!hasData) {
            container.hide();
            noData.show();
            widget.hide();
            return;
        }

        widget.show();
        container.show();
        noData.hide();

        periods.forEach(([periodName, data], index) => {
            const periodKey = getPeriodId(periodName);
            const rowId = `period-${periodKey}-row`;
            const prefix = `period-${periodKey}`;
            const rowClass = index < periods.length - 1 ? 'bg-white border-b hover:bg-gray-100' : 'bg-white hover:bg-gray-100';

            rowsContainer.insertAdjacentHTML('beforeend', `
                <tr id="${rowId}" class="${rowClass}">
                    <td colspan="1" class="px-2 py-2">
                        <div class="flex justify-between items-center mb-1 border-b border-gray-100 pb-1">
                            <h4 class="text-sm font-semibold text-gray-800">${escapeHtml(periodName)}</h4>
                            <span class="text-sm text-gray-500">
                                EB Cost <span id="${prefix}EbPrice" class="tabular-nums text-gray-900">₹0</span>
                            </span>
                        </div>

                        <div class="flex items-center pt-1">
                            <div class="flex-1 space-y-1 text-sm pl-2 pr-4">
                                <div class="flex items-center justify-between w-full">
                                    <div class="flex items-center">
                                        <span class="h-2 w-2 rounded-full mr-2 bg-[{{ config('colors.pith_load_charts.expected') }}]"></span>
                                        <span class="text-gray-900">Estimated</span>
                                    </div>
                                    <span id="${prefix}ExpectedLoads" class="font-semibold tabular-nums text-gray-800 text-right">0</span>
                                </div>
                                @if (auth()->user()->currentTeam->showActuals())
                                <div class="flex items-center justify-between w-full">
                                    <div class="flex items-center">
                                        <span class="h-2 w-2 rounded-full mr-2 bg-[{{ config('colors.pith_load_charts.actual') }}]"></span>
                                        <span class="text-gray-900">Actuals</span>
                                    </div>
                                    <span id="${prefix}ActualLoads" class="font-semibold tabular-nums text-gray-800 text-right">0</span>
                                </div>
                                @endif
                            </div>

                            @if (auth()->user()->currentTeam->showActuals())
                            <div class="w-16 h-16 shrink-0 ml-3 overflow-hidden rounded-full">
                                <div id="${prefix}DonutChart" class="w-full h-full"></div>
                            </div>
                            @endif
                        </div>
                    </td>
                </tr>
            `);

            updateShiftChart(periodKey, data);
        });
    }
</script>
