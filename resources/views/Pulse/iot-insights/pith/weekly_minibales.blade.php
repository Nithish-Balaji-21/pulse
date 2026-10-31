<x-app-layout>
    @include('pulse::iot-insights.pith.header')

    <div id="summary" style="display:none;"></div>

    @if(isset($machines) && count($machines) > 1)
    @php $machinePalette = config('colors.minibales_charts.machines', []); @endphp

    <div class="flex items-center gap-2 px-2.5 py-2" id="machine-pills">
        <button id="pill-all"
            onclick="filterByMachine(null, this)"
            class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border transition-all duration-150"
            style="background:#2c2c2a; color:#fff; border-color:#2c2c2a;">
            All
        </button>

        @foreach($machines as $i => $machine)
            @php $color = $machinePalette[$i] ?? '#6b7280'; @endphp
            <button
                id="pill-{{ $machine->id }}"
                onclick="filterByMachine({{ $machine->id }}, this)"
                data-color="{{ $color }}"
                class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border transition-all duration-150"
                style="background:#fff; color:{{ $color }}; border-color:{{ $color }};">
                <span style="width:7px;height:7px;border-radius:50%;background:{{ $color }};display:inline-block;flex-shrink:0;"></span>
                {{ $machine->machine_name }}
            </button>
        @endforeach
    </div>
    @endif

    <div class="px-4 pb-6 mt-4">
        {{-- No overflow-hidden on this card: it makes the card a clipping context, which
             kills `position: sticky` on the calendar's toolbar and day-name row inside
             it. The bottom bar rounds its own corners instead. --}}
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 calendar-container">
            <div class="rounded-t-2xl border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white px-6 py-4">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Weekly Production Schedule</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Real-time fibre bales tracking</p>
                    </div>
                    <div class="flex items-center gap-3 text-sm flex-wrap">
                        <div id="density-legend" class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-50 border border-gray-200" style="display:none;">
                            <span id="density-track" class="inline-block rounded-sm" style="width:40px; height:10px;">
                                <span id="density-bar" class="block rounded-sm" style="width:70%; height:10px;"></span>
                            </span>
                            <span id="density-scale" class="text-xs text-gray-500"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div id="calendar" class="w-full px-2.5"></div>

            {{-- Bottom Legend Bar --}}
            <div class="flex items-center justify-between px-6 py-3 border-t border-gray-100 rounded-b-2xl bg-gradient-to-r from-gray-50/50 via-white to-gray-50/50 text-xs text-gray-500 font-medium">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                        <path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    <span>Values are in Bales</span>
                </div>
                <div class="flex items-center gap-2">
                    <span>Low Production</span>
                    <div class="flex items-center gap-1">
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#f8fafc; border:1px solid #e2e8f0;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#e2e8f0;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#cbd5e1;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#94a3b8;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#64748b;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#334155;"></span>
                    </div>
                    <span>High Production</span>
                </div>
            </div>
        </div>
    </div>

    <script src="@assetVersion('js/utils.js')"></script>
    <script>
        let activeMachineId = null;
        let calendar;

        function filterByMachine(machineId, btn) {
            activeMachineId = machineId;

            document.querySelectorAll('#machine-pills button').forEach(p => {
                const color = p.dataset.color;
                if (color) {
                    p.style.background  = '#fff';
                    p.style.color       = color;
                    p.style.borderColor = color;
                } else {
                    p.style.background  = 'transparent';
                    p.style.color       = 'var(--color-text-secondary)';
                    p.style.borderColor = 'var(--color-border-secondary)';
                }
            });

            const color = btn.dataset.color;
            if (color) {
                btn.style.background  = color;
                btn.style.color       = '#fff';
                btn.style.borderColor = color;
            } else {
                btn.style.background  = '#2c2c2a';
                btn.style.color       = '#fff';
                btn.style.borderColor = '#2c2c2a';
            }

            calendar.refetchEvents();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const showActuals = {{ auth()->user()->currentTeam->showActuals() ? 'true' : 'false' }};
            const calendarEl = document.getElementById('calendar');
            let today = '{{ $today }}';
            let liveDay = '{{ $liveDay }}';
            const oneYearAgo = moment(today).subtract(1, 'year');
            const validRangeEnd = () => moment(today).add(1, 'day').format('YYYY-MM-DD');

            const slotMinTime = '{{ $shiftStartTime }}';
            const slotMaxTime = calculateSlotMaxTime(slotMinTime, 24);
            let slotDuration = '00:30:00';

            const DENSITY_MIN_BUCKET = 300;
            const modeForBucket = (seconds) => seconds >= DENSITY_MIN_BUCKET ? 'density' : 'marks';
            let bucketSeconds = 1800;
            let renderMode = modeForBucket(bucketSeconds);
            let densityFill = '{{ config('colors.fibre.total') }}';
            const shiftColors = @json([
                config('colors.shift.1'),
                config('colors.shift.2'),
                config('colors.shift.3')
            ]);

            function removeAllTooltips() {
                document.querySelectorAll('.event-tooltip').forEach(el => el.remove());
            }

            function setSlotDuration(duration, activeButton) {
                removeAllTooltips();
                calendar.setOption('slotDuration', duration);
                $('.ec-mins1, .ec-mins5, .ec-mins15, .ec-mins30, .ec-hr1, .ec-hr2').removeClass('ec-active');
                $(`.ec-${activeButton}`).addClass('ec-active');

                const [h, m, s] = duration.split(':').map(Number);
                const previousMode = renderMode;
                bucketSeconds = h * 3600 + m * 60 + s;
                renderMode = modeForBucket(bucketSeconds);

                calendar.refetchEvents();
            }

            function injectSummaryIntoToolbar() {
                const toolbarCenter = calendarEl.querySelector('.ec-toolbar .ec-center');
                if (toolbarCenter) {
                    const summaryEl = document.getElementById('summary');
                    if (summaryEl && !toolbarCenter.contains(summaryEl)) {
                        toolbarCenter.innerHTML = '';
                        toolbarCenter.appendChild(summaryEl);
                    }
                }
                syncStickyHeaderOffset();
            }

            // Where the pinned day-name row has to sit: directly under the pinned
            // toolbar, which is itself pinned under the app's 50px navigation. Measured
            // because the toolbar's height depends on what it is carrying and on how
            // narrow the window is.
            function syncStickyHeaderOffset() {
                const toolbar = calendarEl.querySelector('.ec-toolbar');
                if (!toolbar) {
                    return;
                }
                calendarEl.style.setProperty('--ec-header-top', `${50 + toolbar.offsetHeight}px`);
            }

            window.addEventListener('resize', syncStickyHeaderOffset);

            function updateSummary(data = {}) {
                const summaryContainer = document.getElementById('summary');
                summaryContainer.innerHTML = '';
                summaryContainer.style.display = 'flex';
                summaryContainer.style.alignItems = 'center';

                const entries = Object.entries(data);
                const iconPath = 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10';

                if (entries.length === 0) {
                    summaryContainer.innerHTML = `
                        <div style="display:flex; align-items:center; gap:10px; padding:6px 14px; background:white; border:1px solid #e5e7eb; border-radius:10px;">
                            <div style="width:28px; height:28px; border-radius:7px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <svg width="16" height="16" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path d="${iconPath}"/>
                                </svg>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#374151; font-weight:500;">Total Bales</div>
                                <div style="font-size:16px; font-weight:600; color:#111827; line-height:1.2;">
                                    — <span style="font-size:12px; color:#6b7280; font-weight:400;">No shift data</span>
                                </div>
                            </div>
                        </div>`;
                    injectSummaryIntoToolbar();
                    return;
                }

                const strip = document.createElement('div');
                strip.style.cssText = `display:inline-flex; align-items:stretch; background:white; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06);`;

                let totalBalesExpected = 0;
                let totalBalesProduced = 0;

                entries.forEach(([label, value], index) => {
                    const expected = typeof value === 'object' ? (value.bales_expected ?? 0) : 0;
                    const produced = typeof value === 'object' ? (value.bales_produced ?? 0) : (value ?? 0);
                    const borderColor = shiftColors[index % shiftColors.length];

                    totalBalesExpected += expected;
                    totalBalesProduced += produced;

                    if (index > 0) {
                        const sep = document.createElement('div');
                        sep.style.cssText = 'width:1px; background:#e5e7eb; flex-shrink:0;';
                        strip.appendChild(sep);
                    }

                    const cell = document.createElement('div');
                    cell.style.cssText = 'display:flex; align-items:center; gap:10px; padding:6px 14px; min-width:0;';
                    cell.innerHTML = `
                        <div style="width:28px; height:28px; border-radius:7px; background:${borderColor}18; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="16" height="16" fill="none" stroke="${borderColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                <path d="${iconPath}"/>
                            </svg>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:12px; color:#374151; font-weight:500;">${label}</div>
                            <div style="font-size:16px; font-weight:600; color:${borderColor}; line-height:1.2;">
                              ${(!showActuals || produced == 0) ? `${formatToIndianNumber(expected)} <span style="font-size:12px; color:#6b7280; font-weight:400;">est</span>` : `<span class="counter-value">0</span><span style="font-size:12px; color:#6b7280; font-weight:400;"> / ${formatToIndianNumber(expected)} est</span>`}
                            </div>
                        </div>`;

                    strip.appendChild(cell);

                    setTimeout(() => {
                        const span = cell.querySelector('.counter-value');
                        if (span) animateValue(span, 0, produced, 800);
                    }, index * 100);
                });

                if (entries.length > 1) {
                    const sep = document.createElement('div');
                    sep.style.cssText = 'width:1px; background:#e5e7eb; flex-shrink:0;';
                    strip.appendChild(sep);

                    const totalCell = document.createElement('div');
                    totalCell.style.cssText = 'display:flex; align-items:center; gap:10px; padding:6px 14px; background:#f9fafb; min-width:0;';
                    totalCell.innerHTML = `
                        <div style="width:28px; height:28px; border-radius:7px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="16" height="16" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                <path d="${iconPath}"/>
                            </svg>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:12px; color:#374151; font-weight:500;">Total</div>
                            <div style="font-size:16px; font-weight:600; color:#111827; line-height:1.2;">
                                ${(!showActuals || totalBalesProduced == 0) ? `${formatToIndianNumber(totalBalesExpected)} <span style="font-size:12px; color:#6b7280; font-weight:400;">est</span>` : `<span class="counter-value">0</span><span style="font-size:12px; color:#6b7280; font-weight:400;"> / ${formatToIndianNumber(totalBalesExpected)} est</span>`}
                            </div>
                        </div>`;

                    strip.appendChild(totalCell);

                    setTimeout(() => {
                        const span = totalCell.querySelector('.counter-value');
                        if (span) animateValue(span, 0, totalBalesProduced, 1000);
                    }, entries.length * 100);
                }

                summaryContainer.appendChild(strip);
                injectSummaryIntoToolbar();
            }

            function animateValue(element, start, end, duration) {
                if (!element) return;
                const range = end - start;
                const increment = range / (duration / 16);
                let current = start;

                const timer = setInterval(() => {
                    current += increment;
                    if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
                        current = end;
                        clearInterval(timer);
                    }
                    element.textContent = formatToIndianNumber(Math.floor(current));
                }, 16);
            }

            function renderDensityLegend(density) {
                const legendEl = document.getElementById('density-legend');
                if (!density || renderMode !== 'density') {
                    legendEl.style.display = 'none';
                    return;
                }

                densityFill = density.fill;
                legendEl.style.display = 'flex';
                // The ramp's top step, not `density.fill`: that is the shift palette in even
                // bands, and since the bars themselves went neutral it was showing a colour
                // the grid no longer contains — which read as a step the bars never reached.
                document.getElementById('density-bar').style.background = '#334155';
                document.getElementById('density-track').style.background = density.track;
                const unit = density.unit || 'bales';
                document.getElementById('density-scale').textContent =
                    `full bar = ${formatToIndianNumber(density.scale)} ${unit} / ${describeBucket(density.bucketSeconds)}`
                    + (density.peak > density.scale ? ` · peak ${formatToIndianNumber(density.peak)}` : '');
            }

            function describeBucket(seconds) {
                return seconds >= 3600 ? `${seconds / 3600}h` : `${seconds / 60}m`;
            }

            calendar = EventCalendar.create(calendarEl, {
                view: 'timeGridWeek',
                firstDay: 1,
                allDaySlot: true,
                allDayContent: { html: '<span class="caps-label">Day</span>' },
                locale: 'en-IN',
                slotDuration: slotDuration,
                slotMinTime: slotMinTime,
                slotMaxTime: slotMaxTime,
                validRange: {
                    start: oneYearAgo.format('YYYY-MM-DD'),
                    end: validRangeEnd()
                },
                slotLabelInterval: 0,
                dayHeaderFormat: function (date) {
                    const dayName = moment(date).format('ddd');
                    const dayDate = moment(date).format('D MMM');
                    const formattedDate = moment(date).format('YYYY-MM-DD');
                    const isToday = (formattedDate === liveDay);
                    const badge = isToday
                        ? `<span style="
                        display:inline-flex;
                        align-items:center;
                        gap:3px;
                        margin-left:4px;
                        padding:1px 6px 1px 5px;
                        font-size:12px;
                        font-weight:600;
                        background:#22c55e;
                        color:#fff;
                        border-radius:99px;
                        vertical-align:middle;
                        line-height:16px;">
                        <span style="width:5px;height:5px;border-radius:50%;background:#fff;display:inline-block;"></span>Live
                    </span>`
                        : '';
                    return { html: `<span class="inline-flex items-baseline gap-1.5"><span class="caps-label">${dayName}</span><span class="text-sm font-semibold normal-case tabular-nums text-gray-900">${dayDate}</span>${badge}</span>` };
                },
                headerToolbar: {
                    start: 'prev, datePickerTitle, next',
                    center: '',
                    end: 'mins1,mins5,mins15,mins30,hr1,hr2'
                },
                customButtons: {
                    datePickerTitle: { text: '' },
                    mins1:  { text: '1m',  click: function() { setSlotDuration('00:01:00', 'mins1');  } },
                    mins5:  { text: '5m',  click: function() { setSlotDuration('00:05:00', 'mins5');  } },
                    mins15: { text: '15m', click: function() { setSlotDuration('00:15:00', 'mins15'); } },
                    mins30: { text: '30m', click: function() { setSlotDuration('00:30:00', 'mins30'); }, active: true },
                    hr1:    { text: '1h',  click: function() { setSlotDuration('01:00:00', 'hr1');    } },
                    hr2:    { text: '2h',  click: function() { setSlotDuration('02:00:00', 'hr2');    } },
                },
                eventSources: [{
                    events: function (fetchInfo, successCallback, failureCallback) {
                        const startStr = moment(fetchInfo.startStr).format('YYYY-MM-DD');
                        const endStr = moment(fetchInfo.endStr).subtract(1, 'day').format('YYYY-MM-DD');

                        $.ajax({
                            url: '/{{ $teamId }}/iot-fibre-minibales/fetch-events',
                            method: 'GET',
                            data: {
                                start: startStr,
                                end: endStr,
                                machine_id: activeMachineId,
                                mode: renderMode,
                                bucket: bucketSeconds
                            },
                            success: function (response) {
                                if (response.today) {
                                    today = response.today;
                                    calendar.setOption('validRange', {
                                        start: oneYearAgo.format('YYYY-MM-DD'),
                                        end: validRangeEnd()
                                    });
                                }
                                if (response.liveDay) {
                                    liveDay = response.liveDay;
                                }
                                successCallback(response.events || []);
                                updateSummary(response.totals || {});
                                renderDensityLegend(response.density);
                            },
                            error: function () {
                                alert('Failed to fetch events');
                                failureCallback();
                            }
                        });
                    }
                }],
                eventContent: function (info) {
                    if (info.event.allDay) {
                        let title = info.event.title;
                        title = title.replace(/\b\d+(?=\s+bales)/g, match => formatToIndianNumber(match));
                        return {
                            html: `<div class="all-day-event"><span class="all-day-event-title">${title}</span></div>`
                        };
                    }

                    const props = info.event.extendedProps;
                    if (renderMode !== 'density' || !props.bales) {
                        return { html: '' };
                    }

                    const pct = props.pct || 0;

                    // Neutral slate, deliberately: the shift palette is entirely warm
                    // red-orange (#83211b / #ce7716 / #a7490b) and both appear on this page
                    // at once — shift colours on the all-day chips and the summary pills,
                    // this ramp on the bars beneath them. The previous ramp's top step was
                    // #ce7716 itself, i.e. shift 2's colour byte for byte, so a busy bucket
                    // read as a shift marker. Same reasoning as `weights` in config/colors.php.
                    let fillBg = '#f8fafc';
                    let textColor = '#475569';

                    if (pct > 80) {
                        fillBg = '#334155';
                        textColor = '#ffffff';
                    } else if (pct > 60) {
                        fillBg = '#64748b';
                        textColor = '#ffffff';
                    } else if (pct > 40) {
                        fillBg = '#94a3b8';
                        textColor = '#1e293b';
                    } else if (pct > 25) {
                        fillBg = '#cbd5e1';
                        textColor = '#1e293b';
                    } else if (pct > 10) {
                        fillBg = '#e2e8f0';
                        textColor = '#334155';
                    } else {
                        fillBg = '#f8fafc';
                        textColor = '#475569';
                    }

                    const label = bucketSeconds >= 900
                        ? `${formatToIndianNumber(props.bales)}`
                        : '';

                    // Wide bars caption themselves inside; a short one has no room, so its
                    // count goes in the empty remainder of the track instead of being
                    // squeezed out of the row.
                    const inside = pct >= 25;
                    const over = props.clamped ? ' bale-bar-over' : '';

                    return {
                        html: `<div class="bale-bar-row">`
                            + `<div class="bale-bar-fill${over}" style="width:${pct}%; background:${fillBg}; color:${textColor};">`
                            + `${inside && label ? `<span class="bale-bar-text">${label}</span>` : ''}`
                            + `</div>`
                            + `${!inside && label ? `<span class="bale-bar-text bale-bar-text-out">${label}</span>` : ''}`
                            + `</div>`
                    };
                },
                eventMouseEnter: function(info) {
                    if (info.event.allDay) {
                        return;
                    }
                    removeAllTooltips();

                    const props = info.event.extendedProps || {};
                    const bales = props.bales;

                    let caption;
                    if (bales !== undefined && bales !== null && bales > 0) {
                        const isDensity = renderMode === 'density' && info.event.end;
                        const timeSpan = isDensity
                            ? `${moment(info.event.start).format('h:mm a')} – ${moment(info.event.end).format('h:mm a')}`
                            : moment(info.event.start).format('h:mm:ss a');
                        caption = `${bales} bale${bales > 1 ? 's' : ''} · ${timeSpan}`;
                    } else {
                        caption = moment(info.event.start).format('h:mm:ss a');
                    }

                    const tooltip = document.createElement('div');
                    tooltip.className = 'event-tooltip';
                    tooltip.innerText = caption;
                    document.body.appendChild(tooltip);

                    function moveTooltip(e) {
                        tooltip.style.top = (e.pageY + 12) + 'px';
                        tooltip.style.left = (e.pageX + 12) + 'px';
                    }
                    moveTooltip(info.jsEvent);

                    info.el.addEventListener('mousemove', moveTooltip);
                    info.el._tooltip = tooltip;
                    info.el._moveHandler = moveTooltip;
                },
                eventMouseLeave: function(info) {
                    removeAllTooltips();
                    if (info.el && info.el._moveHandler) {
                        info.el.removeEventListener('mousemove', info.el._moveHandler);
                        delete info.el._tooltip;
                        delete info.el._moveHandler;
                    }
                },
                datesSet: function (info) {
                    removeAllTooltips();
                    const titleBtn = document.querySelector('.ec-datePickerTitle');
                    if (titleBtn) {
                        titleBtn.textContent = info.view.title;
                    }
                    setTimeout(() => injectSummaryIntoToolbar(), 50);
                },
                editable: false,
                eventStartEditable: false,
                eventDurationEditable: false
            });

            setTimeout(() => injectSummaryIntoToolbar(), 100);

            $('.ec-toolbar .ec-datePickerTitle').daterangepicker({
                singleDatePicker: true,
                locale: { firstDay: 1 },
                showDropdowns: true,
                autoApply: true,
                minDate: oneYearAgo,
                maxDate: moment().add(1, 'days'),
            }).on('apply.daterangepicker', function (ev, picker) {
                calendar.setOption('date', picker.startDate.toDate());
            });
        });
    </script>

    <style>
        .ec-toolbar .ec-center {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 12px;
        }
        /* ── Sticky stack ────────────────────────────────────────────────
           Three things pin as the page scrolls: the app's navigation at the top
           (50px tall), the calendar toolbar under it (iotinsights.css, top: 50px),
           and the day-name row under that. iotinsights.css pins the day-name row at
           a hard-coded 123px, i.e. it assumes a 73px toolbar — this page's toolbar
           carries the week summary and is taller, and the shortfall showed as a strip
           of the grid scrolling through the gap between the two. The offset is
           measured instead (see syncStickyHeaderOffset), with 123px as the fallback.

           The all-day band is deliberately not pinned: pinning the day cards on top
           of a pinned toolbar and day-name row leaves almost no grid visible. */
        #calendar .ec-header {
            top: var(--ec-header-top, 123px);
            z-index: 35;
        }
        #calendar .ec-all-day {
            position: relative !important;
            top: auto !important;
            z-index: 30;
        }
        #summary {
            max-width: 100%;
        }
        .shift1-color {
            background-color: {{ $colors['shifts'][1] }};
        }
        .shift2-color {
            background-color: {{ $colors['shifts'][2] }} !important;
        }
        .shift3-color {
            background-color: {{ $colors['shifts'][3] }} !important;
        }
        .totals-color {
            background-color: {{ $colors['totals'] }} !important;
        }
        .ec-event-bales {
            background-color: {{ $fibreColors['total'] }} !important;
        }
        .all-day-event {
            width: 100%;
        }
        .all-day-event-title {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }
        /* Day total: the figure carries the emphasis and the unit trails it after a
           space, matching the blocks and water calendars. */
        .ad-total {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 4px;
            padding: 3px 0;
            white-space: nowrap;
        }
        .ad-total-value {
            font-size: 16px;
            font-weight: 600;
            line-height: 1.15;
            color: #241d19;
            font-variant-numeric: tabular-nums;
        }
        .ad-total-unit {
            font-size: 12px;
            font-weight: 400;
            color: #8a8177;
        }
        .ec-bucket {
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        .ec-bucket .ec-event-body {
            padding: 0 !important;
            height: 100% !important;
            width: 100% !important;
            display: block !important;
            align-items: stretch !important;
            place-content: stretch !important;
        }
        .ec-bucket-fill {
            height: 100%;
            min-width: 2px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 3px;
            box-sizing: border-box;
        }
        /* Bar length is the magnitude and shading reinforces it; the remainder of the row
           stays on the event's own shift-tinted track, so a short bucket still reads as
           "this bucket exists, on this shift". */
        .bale-bar-row {
            display: flex;
            align-items: center;
            height: 100%;
            width: 100%;
        }
        .bale-bar-fill {
            height: 100%;
            min-width: 2px;
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding-left: 6px;
            padding-right: 6px;
            box-sizing: border-box;
            border-radius: 4px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.2);
            transition: width 0.3s ease;
        }
        .bale-bar-text {
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
            pointer-events: none;
        }
        /* Captions that did not fit inside their bar sit on the track beside it, so they
           need a colour that works on the tint rather than on the fill. */
        .bale-bar-text-out {
            padding-left: 5px;
            color: #475569;
        }
        .bale-bar-over,
        .ec-bucket-over {
            border-right: 3px solid #0f172a;
        }
        .ec-bucket-label {
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            color: #fff;
            white-space: nowrap;
        }
    </style>
</x-app-layout>
