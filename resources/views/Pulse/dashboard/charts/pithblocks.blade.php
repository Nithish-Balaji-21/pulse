<!-- Pith Blocks Production chart -->
<div class="h-full bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] px-4 pt-4">
    <h3 class="text-base font-semibold text-gray-800">Pith Blocks Production</h3>

    <hr class="border-t border-gray-400 my-2">
    <div id="pith-blocks-chart" style="display: none;"></div> 
    
    <p id="pith-blocks-empty" class="hidden text-sm text-gray-700 mt-2 pb-4">No data available for the selected duration.</p>
</div>

<script>
    const pithBlocksChartColors = @json(config('colors.pith_blocks_charts'));
    var pithBlocksChart = null;

    function renderPithBlocksChart(pithBlocks)
    {
        // Destroy previous chart if exists
        if (pithBlocksChart) {
            pithBlocksChart.destroy();
            pithBlocksChart = null;
        }

        const chartContainer = document.getElementById('pith-blocks-chart');
        const emptyMessage = document.getElementById('pith-blocks-empty');
        const hasValues = pithBlocks.data.some(item => item.blocks > 0);
        if (!hasValues) {
            document.getElementById('pith-blocks-chart').innerHTML = '';
            chartContainer.innerHTML = '';
            chartContainer.style.display = 'none';
            // document.getElementById('pith-blocks-metrics').style.display = 'none';
            emptyMessage.classList.remove('hidden');
            document.getElementById('pith-blocks-empty').classList.remove('hidden');
            return;
        }

        // document.getElementById('pith-blocks-metrics').style.display = 'grid';
        emptyMessage.classList.add('hidden'); // Hide the message
        chartContainer.style.display = 'block';
        document.getElementById('pith-blocks-empty').classList.add('hidden');

        const categories = pithBlocks.data.map(item => item.period);

        const blocksData = pithBlocks.data.map(item => item.blocks);
        const maxValue   = roundUpToNearest(Math.max(...blocksData));

        // Display totals
        // document.getElementById("total-blocks-produced").textContent = formatToIndianNumber(pithBlocks.total_blocks);

        var options = {
            series: [{
                name: "Blocks Estimated",
                type: "bar",
                data: blocksData,
            }],
            chart: {
                height: 250,
                type: "bar",
                toolbar: {
                    export: {
                        csv: {
                            headerCategory: 'Date',
                        }
                    }
                },
                zoom: {
                    enabled: false
                }
            },
            plotOptions: {
                bar: {
                    columnWidth: '75%',
                },
            },
            stroke: {
                width: [1]
            },
            markers: {
                size: 4,
                colors: [pithBlocksChartColors.actual],
                strokeColors: pithBlocksChartColors.stroke,
                strokeWidth: 1,
                hover: {
                    size: 6,
                }
            },
            colors: [pithBlocksChartColors.actual],
            xaxis: {
                categories: categories,
                labels: {
                    rotate: categories.length > 3 ? -45 : 0,
                    rotateAlways: categories.length > 3,
                    style: {
                        fontSize: "11px",
                    },
                },
            },
            yaxis: {
                min: 0,
                max: maxValue,
                decimalsInFloat: 0,
                title: {
                    text: "Blocks",
                    style: {
                        fontWeight: 500,
                    }
                },
                tickAmount: 4,
            },
            legend: {
                show: false,
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: function(val) {
                        return typeof formatToIndianNumberWithHover === 'function' ? formatToIndianNumberWithHover(val) : val;
                    }
                },
            },
            dataLabels: {
                enabled: false,
            },
        };

        pithBlocksChart = new ApexCharts(document.querySelector("#pith-blocks-chart"), options);
        pithBlocksChart.render();
    }
</script>