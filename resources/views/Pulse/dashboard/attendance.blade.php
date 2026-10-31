<!-- Attendance Hours & Salary -->
<div id="attendanceWidget" class="bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] h-full px-4 pt-4">
    <h3 class="text-base font-semibold text-gray-800">Salary</h3>

    <hr class="border-t border-gray-400 my-2">
    <div id="attendance-chart"></div>
    <p id="attendance-empty" class="hidden text-sm text-gray-700 mt-2 pb-4">
        No data available for the selected duration.
    </p>
</div>

<script>
    const attendanceChartColors = @json(config('colors.attendance_charts'));
    var attendanceChart = null;

    function renderAttendanceChart(attendance)
    {
        // Destroy previous chart if exists
        if (attendanceChart) {
            attendanceChart.destroy();
            attendanceChart = null;
        }

        const hasValues = attendance.some(item => item.total_hours > 0);

        const chartEl = document.getElementById('attendance-chart');
        const emptyEl = document.getElementById('attendance-empty');

        if (!hasValues) {
            chartEl.innerHTML = '';
            emptyEl.classList.remove('hidden');
            return;
        }

        emptyEl.classList.add('hidden');

        // Attendance Hours
        const seriesData = attendance.map(item => item.total_salary);

        // Calculate total salary
        const totalSalary = seriesData.reduce((sum, value) => sum + value, 0);

        var options = {
            chart: {
                type: 'bar',
                height: 280,
                toolbar: {
                    export: {
                        csv: {
                            headerCategory: 'Staff',
                        }
                    }
                },
                zoom: {
                    enabled: false
                }
            },
            colors: [
                attendanceChartColors.salary,
            ],
            series: [{
                name: 'Salary',
                data: seriesData
            }],
            plotOptions: {
                bar: {
                    distributed: true,
                    dataLabels: {
                        position: 'top',
                    }
                },
            },
            legend: {
                show: false
            },
            xaxis: {
                categories: attendance.map(item => item.staff_name)
            },
            yaxis: {
                decimalsInFloat: 0,
                min: 0,
                labels: {
                    formatter: function(value) {
                        return formatToIndianRupees(value);
                    },
                }
            },
            dataLabels: {
                enabled: false,
                formatter: function(value) {
                    return formatToShortNumbers(value);
                },
                style: {
                    colors: [attendanceChartColors.data_labels]
                }
            },
            title: {
                text: 'Total: ' + formatToIndianRupees(totalSalary),
                align: 'center',
                style: {
                    fontSize: '16px',
                    fontWeight: "600",
                    color: attendanceChartColors.title,
                }
                // text-base font-semibold text-gray-800
            }
        };

        attendanceChart = new ApexCharts(document.querySelector("#attendance-chart"), options);
        attendanceChart.render();
    }
</script>