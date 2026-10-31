<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-lg place-content-center">
            {{ __('Machine Summary') }}
        </h2>
    </x-slot>

    <div id="daily-chart" class="py-2">
        <div class="mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg text-center">
                <div id="chart"></div>                
            </div>
        </div>
    </div>
</x-app-layout>
<script>
 document.addEventListener('DOMContentLoaded', function () {
    var dailyData = @json($data['daily']);
    var monthlyData = @json($data['monthly']);

    var options = {
        chart: {
          type: 'bar',
          // height: 600,
          stacked: true,
          zoom: {
            enabled: true,
            type: 'x',
            autoScaleYAxis: true,
            zoomed: function (chartContext, {xaxis}) {
                var diff = xaxis.max - xaxis.min;
                var dayInMillis = 24 * 60 * 60 * 1000;

                // If zoom level is less than or equal to a month, show daily data
                if (diff <= 30 * dayInMillis) {
                    updateChart(dailyData);
                } else {
                    updateChart(monthlyData);
                }
            }            
          }
        }, 
        dataLabels: {
            enabled: true,
            formatter: function (val) {
                return val.toFixed(2);  // Fixes the decimal length to 2 digits
            }
        },        
        series: [],           
        xaxis: {
            type: 'datetime',
            categories: [],
        },
        yaxis: {
            decimalsInFloat: 0,
            title: {
                text: 'Hrs'
            }
        },
        plotOptions: {
            bar: {
                stacked: true,
                dataLabels: {
                    total: {
                        enabled: true,
                        style: {
                            fontSize: '14px',
                            fontWeight: 900
                        }                        
                    }
                }
            }
        },
        colors: ['#0077B5', '#0077B5', '#b45f06', '#5b9144', '#972030', '#0077B5'],        
        title: {
            text: '',
            align: 'center',
            style: {
                fontSize: '20px',
                fontWeight: 'bold',
            }
        }
    };

    var chart = new ApexCharts(document.querySelector("#chart"), options);
    chart.render();

    // Initialize with monthly data
    updateChart(monthlyData);

    function updateChart(data) {
        chart.updateOptions({
            xaxis: {
                categories: data.dates.map(date => new Date(date).getTime())
            },
            series: [
                {
                    name: 'Power Off',
                    data: data.series.poweroff
                },
                {
                    name: 'Machine Off',
                    data: data.series.machineoff
                },
                {
                    name: 'Machine Idle',
                    data: data.series.machineidle
                },
                {
                    name: 'Machine Optimal',
                    data: data.series.machineoptimal
                },
                {
                    name: 'Machine Overload',
                    data: data.series.machineoverload
                },
                {
                    name: 'Machine Missing',
                    data: data.series.machinemissing
                }
            ]
        });
    }
});
</script>