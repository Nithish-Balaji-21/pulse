<x-app-layout>
    <x-slot name="header">
        @include('pulse::iot-insights.fibre.fibre_header')
    </x-slot>

    <style>
        .ec-toolbar .ec-center {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 12px;
        }
        /* The strip takes the width left over beside the section title and centres
           itself in it, rather than hugging the right edge of the header. */
        #summary {
            flex: 1;
            justify-content: center;
            max-width: 100%;
        }

        /* ── Sticky stack ────────────────────────────────────────────────────────
           Three things pin as the page scrolls: the app's navigation at the top
           (50px tall), the calendar toolbar under it (iotinsights.css, top: 50px),
           and the day-name row under that. iotinsights.css pins the day-name row at
           a hard-coded 123px, i.e. it assumes a 73px toolbar — this page's toolbar
           carries the weight-band legend and is taller, and the shortfall showed as a
           strip of the grid scrolling through the gap between the two. The offset is
           measured instead (see syncStickyHeaderOffset), with 123px as the fallback.

           The all-day band is deliberately not pinned: the day cards are ~110px
           tall, and pinning them on top of a pinned toolbar and day-name row left
           almost no grid visible — which is what made a scrolled page look doubled. */
        #calendar .ec-header {
            top: var(--ec-header-top, 123px);
            z-index: 35;
        }
        #calendar .ec-all-day {
            position: relative !important;
            top: auto !important;
            z-index: 30;
        }
    </style>

    {{-- Weight bands, rendered here first and then moved into the calendar toolbar's
         centre by JS, between the date navigation and the granularity buttons. --}}
    <div id="ramp-legend" class="items-center gap-3 text-xs" style="display:none;">
        <span class="text-gray-500 font-medium">Weight Range:</span>
        <div class="flex items-center gap-1.5">
            <span class="legend-line legend-low"></span>
            <span class="font-semibold text-gray-700">&lt; {{ $alertThreshold }} kg</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="legend-line legend-acceptable"></span>
            <span class="font-semibold text-gray-700">{{ $alertThreshold }}–{{ $alertThreshold + 20 }} kg</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="legend-line legend-high"></span>
            <span class="font-semibold text-gray-700">&gt; {{ $alertThreshold + 20 }} kg</span>
        </div>
    </div>

    <div class="px-4 pb-6">
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 calendar-container">
            <div class="rounded-t-2xl border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white px-6 py-4">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Weekly Weight Analysis</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Real-time bales weighed tracking and monitoring</p>
                    </div>
                    {{-- Shift and day totals for the week in view, filled by updateSummary() --}}
                    <div id="summary" style="display:none;"></div>
                </div>
            </div>
            <div id="calendar" class="w-full"></div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var slotDuration = '00:30:00';
            // The granularity buttons pick the rendering: at 30m and coarser a bucket holds
            // enough weighings for a banded bar to say something; at 10m and finer the
            // individual weight marks read better.
            const DENSITY_MIN_BUCKET = 1800;
            const modeForBucket = (seconds) => seconds >= DENSITY_MIN_BUCKET ? 'density' : 'marks';
            let bucketSeconds = 1800;
            let renderMode = modeForBucket(bucketSeconds);
            let bandColors = @json(config('colors.weights'));
            const shiftColors = @json([
                config('colors.shift.1'),
                config('colors.shift.2'),
                config('colors.shift.3')
            ]);

            let currentStartDate = moment().startOf('week').format('YYYY-MM-DD');
            let currentEndDate = moment().endOf('week').format('YYYY-MM-DD');
            let today = '{{ $today }}';
            // The shift day in progress — before the shift anchor this is still yesterday,
            // so it is what the Live badge marks. Navigation still runs to the calendar date.
            let liveDay = '{{ $liveDay }}';
            const oneYearAgo = moment(today).subtract(1, 'year');
            const validRangeEnd = () => moment(today).add(1, 'day').format('YYYY-MM-DD');
            const fibreColor = @json(config('colors.fibre.total'));
            var activeFormat = 'shift_date';

            var slotMinTime = "{{ $shiftStartTime }}";
            function calculateSlotMaxTime(slotMinTime) {
                const [hours, minutes, seconds] = slotMinTime.split(':').map(Number);
                const maxHours = hours + 24;
                return `${maxHours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            }
            var slotMaxTime = calculateSlotMaxTime(slotMinTime);

            var calendar = EventCalendar.create(calendarEl, {
                view: 'timeGridWeek',
                firstDay: 1,
                allDaySlot: true,
                allDayContent: { html: '<span class="caps-label">Day</span>' },
                validRange: {
                    start: oneYearAgo.format('YYYY-MM-DD'),
                    end: validRangeEnd()
                },
                slotMinTime: slotMinTime,
                slotMaxTime: slotMaxTime,
                slotDuration: slotDuration,
                slotLabelInterval: 0,
                locale: 'en-IN',
                headerToolbar: {
                    start: 'prev, datePickerTitle, next, shiftDate, calendarDate',
                    center: '',
                    end: 'mins1,mins5,mins10,mins30,hr1,hr2'
                },
                customButtons: {
                    datePickerTitle: { text: '' },
                    mins1:  { text: '1m',  click: () => setSlotDuration('00:01:00', 'mins1') },
                    mins5:  { text: '5m',  click: () => setSlotDuration('00:05:00', 'mins5') },
                    mins10: { text: '10m', click: () => setSlotDuration('00:10:00', 'mins10') },
                    mins30: { text: '30m', click: () => setSlotDuration('00:30:00', 'mins30'), active: true },
                    hr1:    { text: '1h',  click: () => setSlotDuration('01:00:00', 'hr1') },
                    hr2:    { text: '2h',  click: () => setSlotDuration('02:00:00', 'hr2') },
                    calendarDate: {
                        text: 'Calendar Date',
                        click: () => { setActiveButton('calendarDate', 'shiftDate'); activeFormat = 'bale_date'; reloadEvents(); }
                    },
                    shiftDate: {
                        text: 'Shift Date',
                        click: () => { setActiveButton('shiftDate', 'calendarDate'); activeFormat = 'shift_date'; reloadEvents(); },
                        active: true
                    },
                },
                dayHeaderFormat: (date) => {
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
                editable: false,
                eventStartEditable: false,
                eventDurationEditable: false,
                eventSources: [
                    {
                        events: (fetchInfo, successCallback, failureCallback) => {
                            let startStr = moment(fetchInfo.startStr).format('YYYY-MM-DD');
                            let endStr = moment(fetchInfo.endStr).subtract(1, 'days').format('YYYY-MM-DD');

                            $.ajax({
                                url: '/{{ $teamId }}/iot-fibre-weight/fetch-events',
                                method: 'GET',
                                data: { start: startStr, end: endStr, format: activeFormat, mode: renderMode, bucket: bucketSeconds },
                                success: (response) => {
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
                                    if (response.density) {
                                        bandColors = response.density.colors;
                                    }
                                    successCallback(response.events);
                                    updateSummary(response.totals);
                                    // The library rebuilds its toolbar on a view change,
                                    // so the legend is re-seated after every fetch.
                                    injectLegendIntoToolbar();
                                },
                                error: () => { alert('Error fetching events!'); failureCallback(); }
                            });
                        }
                    }
                ],
                eventContent: (info) => {
                    if (info.event.allDay) {
                        return { html: `<div class="all-day-event"><span class="all-day-event-title">${info.event.title}</span></div>` };
                    }

                    // A bucket draws a bar whose length is how many bales it weighed and
                    // whose segments are how those split across the weight bands.
                    const props = info.event.extendedProps;
                    if (renderMode !== 'density' || !props.bales) {
                        return { html: '' };
                    }

                    const segments = ['low', 'normal', 'high']
                        .filter(band => props[band] > 0)
                        .map(band => `<i style="width:${(props[band] / props.bales * 100).toFixed(2)}%; background:${bandColors[band]};"></i>`)
                        .join('');

                    const over = props.clamped ? ' ec-wbucket-over' : '';
                    const count = formatToIndianNumber(props.bales);

                    // Wide bars caption themselves inside; a short one has no room, so its
                    // count goes in the empty remainder of the track instead of being
                    // pushed out of the row.
                    const inside = props.pct >= 25;
                    const label = inside
                        ? `<span class="ec-wbucket-label ec-wbucket-label-in">${count}</span>`
                        : `<span class="ec-wbucket-label ec-wbucket-label-out">${count}</span>`;

                    return {
                        html: `<span class="ec-wbucket-fill${over}" style="width:${props.pct}%;">`
                            + `${segments}${inside ? label : ''}</span>`
                            + `${inside ? '' : label}`
                    };
                },
                eventMouseEnter: function(info) {
                    // info.event - the event object
                    // info.el - the HTML element for the event
                    if (info.event.allDay) {
                        return;
                    }

                    const props = info.event.extendedProps;

                    // A density bar reports its bucket's mix; a mark is one weighing.
                    let caption;
                    if (props.bales) {
                        const window = `${moment(info.event.start).format('h:mm a')} – ${moment(info.event.end).format('h:mm a')}`;
                        caption = `${props.bales} bales · ${window}\n`
                            + `${props.low} under · ${props.normal} in range · ${props.high} over\n`
                            + `avg ${props.avg} kg`;
                    } else {
                        caption = `Weight: ${props.weight} kg`;
                    }

                    // Create tooltip element
                    const tooltip = document.createElement('div');
                    tooltip.className = 'event-tooltip';
                    tooltip.innerText = caption;

                    // Append tooltip to body
                    document.body.appendChild(tooltip);

                    // Position tooltip near mouse
                    function moveTooltip(e) {
                        tooltip.style.top = e.pageY + 10 + 'px';
                        tooltip.style.left = e.pageX + 10 + 'px';
                    }
                    moveTooltip(info.jsEvent);

                    // Update position on mouse move inside event
                    info.el.addEventListener('mousemove', moveTooltip);

                    // Store tooltip and move handler for removal
                    info.el._tooltip = tooltip;
                    info.el._moveHandler = moveTooltip;
                },
                eventMouseLeave: function(info) {
                    // Remove tooltip and mousemove listener
                    if (info.el._tooltip) {
                        info.el.removeEventListener('mousemove', info.el._moveHandler);
                        info.el._tooltip.remove();
                        delete info.el._tooltip;
                        delete info.el._moveHandler;
                    }
                },
                datesSet: (info) => {
                    const titleButton = document.querySelector('.ec-datePickerTitle');
                    if (titleButton) titleButton.textContent = info.view.title;
                    currentStartDate = moment(info.startStr).format('YYYY-MM-DD');
                    currentEndDate = moment(info.endStr).subtract(1, 'days').format('YYYY-MM-DD');
                },
                nowIndicator: false,
                selectable: false
            });

            $('.ec-toolbar .ec-datePickerTitle').daterangepicker({
                singleDatePicker: true,
                locale: {
                    firstDay: 1
                },
                showDropdowns: true,
                autoApply: true,
                minDate: oneYearAgo,
                maxDate: moment().add(1, 'days')
            }).on('apply.daterangepicker', (ev, picker) => {
                calendar.setOption('date', picker.startDate.toDate());
            });

            injectLegendIntoToolbar();

            function setActiveButton(active, inactive) {
                $(`.ec-${inactive}`).removeClass('ec-active');
                $(`.ec-${active}`).addClass('ec-active');
            }

            function reloadEvents() {
                if (activeFormat === 'shift_date') {
                    calendar.setOption('slotMinTime', '{{ $shiftStartTime }}');
                    calendar.setOption('slotMaxTime', calculateSlotMaxTime('{{ $shiftStartTime }}'));
                } else {
                    calendar.setOption('slotMinTime', '00:00:00');
                    calendar.setOption('slotMaxTime', '24:00:00');
                }
                calendar.refetchEvents();
            }

            // Update summary section dynamically — compact strip embedded in the
            // calendar toolbar, matching the pith IoT Insights summary style.
            function updateSummary(totals) {
                const summaryContainer = document.getElementById('summary');
                summaryContainer.innerHTML = '';
                summaryContainer.style.display = 'flex';
                summaryContainer.style.alignItems = 'center';

                const entries = Object.entries(totals || {});
                const showTotal = entries.length > 1;

                const strip = document.createElement('div');
                strip.style.cssText = `display:inline-flex; align-items:stretch; background:white; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); max-width:100%;`;

                const weightIconPath = 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3';

                // ── No shift data ─────────────────────────────────────────────────────────
                if (entries.length === 0) {
                    strip.innerHTML = `
                        <div style="display:flex; align-items:center; gap:10px; padding:6px 14px;">
                            <div style="width:28px; height:28px; border-radius:7px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <svg width="16" height="16" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path d="${weightIconPath}"/>
                                </svg>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#374151; font-weight:500; font-family:inherit;">Bales Weighed</div>
                                <div style="font-size:16px; font-weight:600; color:#d1d5db; line-height:1.2; font-family:inherit;">— <span style="font-size:12px; font-weight:400;">No shift data</span></div>
                            </div>
                        </div>`;
                    summaryContainer.appendChild(strip);

                    return;
                }

                // ── Shift cells ───────────────────────────────────────────────────────────
                let totalBalesWeighed = 0;
                let totalWeightTons = 0;

                entries.forEach(([shiftName, data], index) => {
                    totalBalesWeighed += data.total_bales_weighed;
                    totalWeightTons += data.total_weight / 1000;
                    const avgWeight = data.total_bales_weighed > 0
                        ? (data.total_weight / data.total_bales_weighed).toFixed(1)
                        : '0.0';

                    // Must be the shift palette, not the module colour: the all-day cards
                    // below key their rows off the same palette, and a strip cell in a
                    // different colour from the rows beneath it reads as a different shift.
                    const borderColor = shiftColors[index % shiftColors.length] || fibreColor;

                    if (index > 0) {
                        const sep = document.createElement('div');
                        sep.style.cssText = 'width:1px; background:#e5e7eb; flex-shrink:0;';
                        strip.appendChild(sep);
                    }

                    const cell = document.createElement('div');
                    cell.style.cssText = 'display:flex; align-items:center; gap:10px; padding:6px 14px; min-width:0; white-space:nowrap;';
                    cell.innerHTML = `
                        <div style="width:28px; height:28px; border-radius:7px; background:${borderColor}18; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="16" height="16" fill="none" stroke="${borderColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                <path d="${weightIconPath}"/>
                            </svg>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:12px; color:#374151; font-weight:500; font-family:inherit;">${shiftName}</div>
                            <div style="font-size:16px; font-weight:600; color:${borderColor}; line-height:1.2; font-family:inherit;">
                                <span class="counter-value">0</span> <span style="font-size:12px; color:#6b7280; font-weight:400;">bales</span>
                            </div>
                            <div style="font-size:12px; color:#6b7280; font-family:inherit;">${(data.total_weight / 1000).toFixed(2)} T &middot; ${avgWeight} kg avg</div>
                        </div>`;

                    strip.appendChild(cell);

                    setTimeout(() => {
                        const span = cell.querySelector('.counter-value');
                        if (span) animateValue(span, 0, data.total_bales_weighed, 800);
                    }, index * 100);
                });

                // ── Total cell (only when 2+ shifts) ─────────────────────────────────────
                if (showTotal) {
                    const averageKgTotal = totalBalesWeighed > 0
                        ? (totalWeightTons * 1000 / totalBalesWeighed).toFixed(1)
                        : '0.0';

                    const sep = document.createElement('div');
                    sep.style.cssText = 'width:1px; background:#e5e7eb; flex-shrink:0;';
                    strip.appendChild(sep);

                    const totalCell = document.createElement('div');
                    totalCell.style.cssText = 'display:flex; align-items:center; gap:10px; padding:6px 14px; background:#f9fafb; min-width:0; white-space:nowrap;';
                    totalCell.innerHTML = `
                        <div style="width:28px; height:28px; border-radius:7px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="16" height="16" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:12px; color:#374151; font-weight:500; font-family:inherit;">Total</div>
                            <div style="font-size:16px; font-weight:600; color:#111827; line-height:1.2; font-family:inherit;">
                                <span class="counter-value">0</span> <span style="font-size:12px; color:#6b7280; font-weight:400;">bales</span>
                            </div>
                            <div style="font-size:12px; color:#6b7280; font-family:inherit;">${totalWeightTons.toFixed(2)} T &middot; ${averageKgTotal} kg avg</div>
                        </div>`;

                    strip.appendChild(totalCell);

                    setTimeout(() => {
                        const span = totalCell.querySelector('.counter-value');
                        if (span) animateValue(span, 0, totalBalesWeighed, 1000);
                    }, entries.length * 100);
                }

                summaryContainer.appendChild(strip);
            }

            // The weight bands sit in the toolbar's centre, between the date navigation
            // and the granularity buttons, which is why they are moved there rather than
            // rendered in place: the toolbar is the library's own markup.
            function injectLegendIntoToolbar() {
                const toolbarCenter = calendarEl.querySelector('.ec-toolbar .ec-center');
                const legendEl = document.getElementById('ramp-legend');
                if (toolbarCenter && legendEl && !toolbarCenter.contains(legendEl)) {
                    toolbarCenter.innerHTML = '';
                    legendEl.style.display = 'flex';
                    toolbarCenter.appendChild(legendEl);
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



            function setSlotDuration(duration, active) {
                calendar.setOption('slotDuration', duration);
                $('.ec-mins1, .ec-mins5, .ec-mins10, .ec-mins30, .ec-hr1, .ec-hr2').removeClass('ec-active');
                $(`.ec-${active}`).addClass('ec-active');

                // The buttons pick the bucket width and with it the rendering, not just the
                // zoom, so the server has to re-aggregate.
                const [h, m, s] = duration.split(':').map(Number);
                const previousMode = renderMode;
                bucketSeconds = h * 3600 + m * 60 + s;
                renderMode = modeForBucket(bucketSeconds);

                // Marks don't depend on the bucket, so only re-fetch when the bars need
                // re-aggregating or the two renderings are swapping over.
                if (renderMode === 'density' || previousMode !== renderMode) {
                    calendar.refetchEvents();
                }
            }

            // Number animation function
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
        });
    </script>

    <style>
        .legend-low {
            background: {{ $colors['weights']['low'] }} !important;
        }
        .legend-acceptable {
            background: {{ $colors['weights']['normal'] }} !important;
        }
        .legend-high {
            background: {{ $colors['weights']['high'] }} !important;
        }
        .legend-line {
            display: inline-block;
            width: 30px;
            height: 6px;
            border-radius: 0;
        }

        /* Density buckets: the bar sits flush in its slot so a run of buckets reads as one
           column, with the bale count called out beside it in the unfilled remainder. */
        .ec-wbucket {
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        /* flex-direction is the load-bearing line here: the library sets .ec-event-body
           to `column` everywhere except the all-day and timeline views, so a count that
           did not fit inside its bar was laid out as the next item in that column —
           i.e. on the line below the bar, centred, which is what small buckets (1 or 2
           bales, the only ones that caption outside) looked like. */
        .ec-wbucket .ec-event-body {
            display: flex !important;
            flex-direction: row !important;
            align-items: stretch !important;
            place-content: flex-start !important;
            height: 100% !important;
            width: 100% !important;
            padding: 0 !important;
        }
        .ec-wbucket-fill {
            position: relative;
            display: flex;
            height: 100%;
            min-width: 2px;
            flex-shrink: 0;
        }
        .ec-wbucket-fill i {
            display: block;
            height: 100%;
        }
        /* Buckets past the 95th-percentile scale run off the end rather than pretending to
           be equal to it. */
        .ec-wbucket-over {
            border-right: 3px solid #374151;
        }
        .ec-wbucket-label {
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
            pointer-events: none;
        }
        .ec-wbucket-label-in {
            position: absolute;
            top: 50%;
            right: 4px;
            transform: translateY(-50%);
            color: #fff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45);
        }
        .ec-wbucket-label-out {
            align-self: center;
            padding-left: 4px;
            color: #4b5563;
        }

        /* ── All-day day card ────────────────────────────────────────────────────
           Built server-side in IotFibreWeightController::buildDayCard(); everything
           below is the styling for that markup. The card owns the full width of its
           column, which is what lets the counts share a left edge and the tonnages a
           right edge across all seven days.

           iotinsights.css is linked from the header include, which the layout renders
           ahead of this block, so the overrides of its all-day rules carry
           !important. */
        #calendar .ec-all-day .ec-day .ec-events {
            padding: 0 3px !important;
            margin: 0 !important;
        }
        #calendar .ec-all-day .ec-event {
            padding: 0;
            /* !important because the library writes the width inline on the article. */
            width: 100% !important;
        }
        #calendar .ec-all-day .ec-event-body {
            display: block;
            width: 100%;
            place-content: normal;
        }
        #calendar .ec-all-day .all-day-event,
        #calendar .ec-all-day .all-day-event-title {
            display: block;
            width: 100%;
        }
        /* Top-align the seven cards so a day that ran one shift shares a baseline with
           a day that ran three, instead of floating half a row lower. */
        .ec-all-day .ec-days {
            align-items: stretch !important;
        }
        .ec-all-day .ec-day {
            padding-bottom: 6px !important;
            align-content: start !important;
        }
        /* The card is a panel on the all-day band's grey, matching the pith Production
           and fibre Bales Produced cards so the IoT Insights pages read as one design. */
        .ec-all-day-custom-total {
            border-width: 1px !important;
            border-style: solid !important;
            border-color: #d5cec6 !important;
            background-color: #fff !important;
            margin: 0 !important;
            padding: 0 !important;
            font-weight: 400 !important;
        }

        /* ── Day card ────────────────────────────────────────────────────────────
           Built server-side in IotFibreWeightController::buildDayCard(); everything
           below is the styling for that markup, and it mirrors the Bales Produced card
           (.fb-*) so a reader moving between the two tabs reads the same shapes. */
        .fw-card {
            display: flex;
            flex-direction: column;
            gap: 3px;
            padding: 5px 5px;
            font-variant-numeric: tabular-nums;
        }
        .fw-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 5px;
            white-space: nowrap;
        }
        /* The headline keeps its height even on a day with nothing to put in it, so the
           shift lines below stay level across all seven columns. */
        .fw-row-head {
            min-height: 19px;
        }
        .fw-big {
            font-size: 16px;
            font-weight: 600;
            color: #241d19;
            line-height: 1.15;
            font-variant-numeric: tabular-nums;
        }
        .fw-unit {
            font-size: 12px;
            font-weight: 400;
            color: #74695f;
            margin-left: 4px;
        }
        .fw-chip {
            font-size: 12px;
            font-weight: 600;
            padding: 1px 5px;
            border-radius: 3px;
            background: #f2ede6;
            color: #74695f;
        }
        /* The day's tonnage is the quiet half of the headline row: a reading to be taken
           off the card, not something to be read down the week beside the counts. */
        .fw-total-tons {
            font-size: 12px;
            font-weight: 400;
            color: #857b71;
        }

        /* One shift per line: the row is the chart. The band's length is the shift's
           count on the week's scale, and a rule marks where it ends. */
        .fw-sline {
            position: relative;
            display: block;
            height: 20px;
            border-radius: 3px;
            background: #fbf9f6;
            overflow: hidden;
        }
        .fw-sline + .fw-sline {
            margin-top: 3px;
        }
        .fw-sline-empty {
            background: transparent;
            border: 1px dashed #eee8e0;
        }
        .fw-band {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            opacity: 0.28;
        }
        .fw-edge {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 2px;
            opacity: 0.95;
        }
        .fw-stext {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
            height: 100%;
            padding: 0 5px;
            white-space: nowrap;
        }
        /* The shift's own colour carries its identity — it is the only thing separating
           S1 from S2 at a glance, and it matches the palette the toolbar strip uses. */
        .fw-lbl { font-size: 12px; font-weight: 600; }
        /* The count is not set bold: it is already the darkest and largest thing on the
           line, and the dot then separates it from the tonnage without the two having to
           differ in weight as well. */
        .fw-nums { font-size: 12px; color: #857b71; font-variant-numeric: tabular-nums; }
        .fw-nums b { font-size: 12px; font-weight: 400; color: #241d19; }
        .fw-muted { color: #857b71; }
        /* Average weight sits on the machine's spec all week, so it is the faintest
           figure on the card: a check that nothing has drifted, not a third measure. The
           gap above it is what separates a rate from the counts it is derived from. */
        .fw-avg {
            margin-top: 5px;
            text-align: right;
            font-size: 12px;
            color: #857b71;
        }
    </style>

</x-app-layout>
