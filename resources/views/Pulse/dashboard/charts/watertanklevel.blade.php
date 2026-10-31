<div id="waterTankLevelWidget" class="hidden">
    <div class="h-full bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] px-4 pt-4">
        <h3 class="text-base font-semibold text-gray-800 mb-2">Water Tank Level</h3>
        <hr class="border-t border-gray-400 my-2 mb-8">

        <div id="tankGaugeLayout" class="grid grid-cols-2 gap-6"> </div>

        <p id="tank-end-date-message" class="mt-12 text-sm text-gray-700 text-center italic"></p>
        <div class="mt-10"> </div>

        <div id="tank-water-level-empty" class="hidden text-sm text-gray-700 mt-3 pb-3 text-center">
            No water level data available for either tank.
        </div>
    </div>
</div>

<script>
    const tankColors = @json([
        'base' => config('colors.tank_level_charts.base'),
        'stroke' => config('colors.tank_level_charts.stroke')
    ]);

    const BASE_COLOR = tankColors.base;
    const STROKE_COLOR = tankColors.stroke;

    function generateGaugeHTML(tankName, current, max, color) {
        const percent = max > 0 ? Math.min((current / max) * 100, 100) : 0;
        const arcLength = 126;
        const offset = arcLength * (1 - percent / 100);
        
        const displayCurrent = typeof formatToIndianNumberNormal === 'function' 
            ? formatToIndianNumberNormal(Math.round(current)) 
            : Math.round(current);
            
        const displayMax = typeof formatToIndianNumberNormal === 'function' 
            ? formatToIndianNumberNormal(Math.round(max)) 
            : Math.round(max);

        return `
            <div class="relative w-48 h-32 shrink-0 mx-auto">
                <svg viewBox="0 0 100 60" class="w-full h-full">
                    <path d="M10 50 A40 40 0 0 1 90 50" fill="none" stroke="#e5e7eb" stroke-width="10" stroke-linecap="round"/>
                    
                    <path
                        d="M10 50 A40 40 0 0 1 90 50"
                        fill="none"
                        stroke="${color}"
                        stroke-width="10"
                        stroke-linecap="round"
                        stroke-dasharray="126"
                        stroke-dashoffset="${offset}"
                        style="transition: stroke-dashoffset 1s ease-in-out;"
                    />
                    
                    <text x="5" y="62" font-size="6" fill="#6b7280" text-anchor="start">0</text>
                    <text x="100" y="62" font-size="6" fill="#6b7280" text-anchor="end">${displayMax}</text>
                    
                    <text x="50" y="45" font-size="9" fill="${STROKE_COLOR}" text-anchor="middle" class="font-semibold">
                        ${displayCurrent} ltr
                    </text>
                </svg>
                <h4 class="text-sm font-semibold text-center text-gray-700 mt-2 truncate px-2"">
                    ${tankName}
                </h4>
            </div>
        `;
    }

    function renderTankWaterLevel(data, endDate) {
        const widgetContainer = document.getElementById("waterTankLevelWidget");
        const layoutContainer = document.getElementById("tankGaugeLayout");
        const emptyMsg = document.getElementById("tank-water-level-empty");
        const endMsg = document.getElementById("tank-end-date-message");

        if (!widgetContainer || !layoutContainer || !emptyMsg) return;

        // Clear previous content
        layoutContainer.innerHTML = '';

        // Check for data validity
        // 'data' corresponds to $data['waterTankLevel'] from the controller
        if (!data || Object.keys(data).length === 0) {
            widgetContainer.classList.add("hidden"); // Or show with empty message if preferred
            emptyMsg.classList.remove("hidden");
            return;
        }

        let hasAnyData = false;

        // Iterate through dynamic keys (Tank Names)
        Object.entries(data).forEach(([tankName, tankData]) => {
            const current = tankData.current_level_litres || 0;
            const max = tankData.tank_capacity_litres || 0;

            // Only render if capacity is valid > 0
            if (max > 0) {
                hasAnyData = true;
                const gaugeHTML = generateGaugeHTML(tankName, current, max, BASE_COLOR);
                layoutContainer.insertAdjacentHTML('beforeend', gaugeHTML);
            }
        });

        // Handle Empty State
        if (!hasAnyData) {
            widgetContainer.classList.add("hidden");
            emptyMsg.classList.remove("hidden");
            return;
        }

        // Show Widget
        widgetContainer.classList.remove("hidden");
        emptyMsg.classList.add("hidden");

        // Update Date Message
        if (endMsg) {
            const isToday = moment(endDate).isSame(moment(), 'day');
            if (isToday) {
                endMsg.textContent = '';
            } else {
                const formattedDate = endDate ? moment(endDate).format('DD/MM/YYYY') : '';
                const text = ''; // formattedDate ? `At the end of ${formattedDate}` : '';
                endMsg.textContent = text;
            }
        }
    }
</script>