<x-app-layout>
    <x-slot name="header">
        @include('pulse::iot-insights.growbags.growbags_header')
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
           carries the ramp legend and is taller, and the shortfall shows as a strip
           of the grid scrolling through the gap between the two. The offset is
           measured instead (see syncStickyHeaderOffset), with 123px as the fallback.

           The all-day band is deliberately not pinned: the day cards are ~110px
           tall, and pinning them on top of a pinned toolbar and day-name row leaves
           almost no grid visible. */
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

    {{-- Production ramp for the density bars, rendered here first and then moved into
         the calendar toolbar's centre by JS, between the date navigation and the
         granularity buttons. --}}
    <div id="ramp-legend" class="items-center gap-2 text-xs text-gray-500 font-medium" style="display:none;">
        <span>Low Production</span>
        <div class="flex items-center gap-1">
            <span class="w-3.5 h-3.5 rounded-xs" style="background:#e2e8f0;"></span>
            <span class="w-3.5 h-3.5 rounded-xs" style="background:#cbd5e1;"></span>
            <span class="w-3.5 h-3.5 rounded-xs" style="background:#94a3b8;"></span>
            <span class="w-3.5 h-3.5 rounded-xs" style="background:#64748b;"></span>
        </div>
        <span>High Production</span>
    </div>

    <div class="px-4 pb-6">
        {{-- No overflow-hidden on this card: it makes the card a clipping context, which
             kills `position: sticky` on the calendar's day-name row inside it. The bottom
             bar rounds its own corners instead. --}}
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 calendar-container">
            <div class="rounded-t-2xl border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white px-6 py-4">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Weekly Production Schedule</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Real-time growbag production tracking</p>
                    </div>
                    {{-- Shift and day totals for the week in view, filled by updateSummary() --}}
                    <div id="summary" style="display:none;"></div>
                </div>
            </div>
            <div id="calendar" class="w-full"></div>

            {{-- Bottom Legend Bar --}}
            <div class="flex items-center px-6 py-3 border-t border-gray-100 rounded-b-2xl bg-gradient-to-r from-gray-50/50 via-white to-gray-50/50 text-xs text-gray-500 font-medium">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span>Values are in Bags</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var calendarEl   = document.getElementById('calendar');
            var slotDuration = '00:10:00';

            // The granularity buttons pick the rendering: at 5m and coarser a bucket holds
            // enough punches for a bar to say something, so the density bars are shown; at 1m
            // the buckets thin out and the individual punch marks read better.
            const DENSITY_MIN_BUCKET = 300;
            const modeForBucket = (seconds) => seconds >= DENSITY_MIN_BUCKET ? 'density' : 'marks';
            let bucketSeconds = 600;
            let renderMode = modeForBucket(bucketSeconds);

            // Only reached by a bucket the server could not place on a shift; every other
            // bar carries the colour of the shift it was counted under.
            const densityFill = '{{ config('colors.growbags.total') }}';
            const shiftColors = @json([
                config('colors.shift.1'),
                config('colors.shift.2'),
                config('colors.shift.3')
            ]);

            let currentStartDate = moment().startOf('week').format('YYYY-MM-DD');
            let currentEndDate   = moment().endOf('week').format('YYYY-MM-DD');
            let today          = '{{ $today }}';
            // The shift day in progress — before the shift anchor this is still yesterday,
            // so it is what the Live badge marks. Navigation still runs to the calendar date.
            let liveDay        = '{{ $liveDay }}';
            const oneYearAgo     = moment(today).subtract(1, 'year');
            const validRangeEnd  = () => moment(today).add(1, 'day').format('YYYY-MM-DD');
            var activeFormat     = 'shift_date';

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
                    mins1:  { text: '1m',  click: function () { setSlotDuration('00:01:00', 'mins1'); } },
                    mins5:  { text: '5m',  click: function () { setSlotDuration('00:05:00', 'mins5'); } },
                    mins10: { text: '10m', click: function () { setSlotDuration('00:10:00', 'mins10'); }, active: true },
                    mins30: { text: '30m', click: function () { setSlotDuration('00:30:00', 'mins30'); } },
                    hr1:    { text: '1h',  click: function () { setSlotDuration('01:00:00', 'hr1'); } },
                    hr2:    { text: '2h',  click: function () { setSlotDuration('02:00:00', 'hr2'); } },
                    calendarDate: {
                        text: 'Calendar Date',
                        click: function () {
                            setActiveButton('calendarDate', 'shiftDate');
                            activeFormat = 'growbag_date';
                            reloadEvents();
                        }
                    },
                    shiftDate: {
                        text: 'Shift Date',
                        click: function () {
                            setActiveButton('shiftDate', 'calendarDate');
                            activeFormat = 'shift_date';
                            reloadEvents();
                        },
                        active: true
                    },
                },
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
                editable: false,
                eventStartEditable: false,
                eventDurationEditable: false,
                eventSources: [{
                    events: function (fetchInfo, successCallback, failureCallback) {
                        let startStr = moment(fetchInfo.startStr).format('YYYY-MM-DD');
                        let endStr   = moment(fetchInfo.endStr).subtract(1, 'days').format('YYYY-MM-DD');

                        $.ajax({
                            url: '/{{ $teamId }}/iot-growbags/fetch-events',
                            method: 'GET',
                            data: {
                                start:   startStr,
                                end:     endStr,
                                format:  activeFormat,
                                machine: {{ $selectedMachineId ?? 'null' }},
                                mode:    renderMode,
                                bucket:  bucketSeconds
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
                                successCallback(response.events);
                                updateSummary(response.totals, response.colors);
                                // The library rebuilds its toolbar on a view change,
                                // so the legend is re-seated after every fetch.
                                injectLegendIntoToolbar();
                            },
                            error: function () {
                                console.error('Failed to fetch growbags events');
                                failureCallback();
                            }
                        });
                    }
                }],
                eventContent: function (info) {
                    if (info.event.allDay) {
                        return { html: `<div class="all-day-event">
                            <span class="all-day-event-title">${info.event.title}</span>
                        </div>` };
                    }

                    // A bucket draws a bar across its slot, shaded by its share of the range
                    // peak, and captions itself once the slot can hold a numeral.
                    const props = info.event.extendedProps;
                    if (renderMode !== 'density' || !props.bags) {
                        return { html: '' };
                    }

                    const pct = props.pct || 0;

                    // Neutral slate, deliberately: the shift palette is entirely warm
                    // red-orange (#83211b / #ce7716 / #a7490b) and both appear on this page
                    // at once — shift colours on the all-day chips and the summary pills,
                    // this ramp on the bars beneath them. An earlier ramp's top step was
                    // #ce7716 itself, i.e. shift 2's colour byte for byte, so a busy bucket
                    // read as a shift marker. Same reasoning as `weights` in config/colors.php.
                    //
                    // Four steps on quartiles: the palest step was barely a mark on the grid
                    // and the darkest was the only one needing reversed text, so dropping
                    // both ends leaves four that are told apart at a glance.
                    let fillBg = '#e2e8f0';
                    let textColor = '#334155';

                    if (pct > 75) {
                        fillBg = '#64748b';
                        textColor = '#ffffff';
                    } else if (pct > 50) {
                        fillBg = '#94a3b8';
                        textColor = '#1e293b';
                    } else if (pct > 25) {
                        fillBg = '#cbd5e1';
                        textColor = '#1e293b';
                    } else {
                        fillBg = '#e2e8f0';
                        textColor = '#334155';
                    }

                    const label = bucketSeconds >= 300
                        ? `${formatToIndianNumber(props.bags)}`
                        : '';

                    // Every bar captions itself from its own left edge, however short it
                    // is: the count then always begins where the bar begins, so the
                    // numbers line up down the column instead of stepping right on the
                    // quiet buckets. A count wider than its bar simply runs on over the
                    // track, which is why the fill does not clip.
                    const over = props.clamped ? ' bag-bar-over' : '';

                    return {
                        html: `<div class="bag-bar-row">`
                            + `<div class="bag-bar-fill${over}" style="width:${pct}%; background:${fillBg}; color:${textColor};">`
                            + `${label ? `<span class="bag-bar-text">${label}</span>` : ''}`
                            + `</div>`
                            + `</div>`
                    };
                },
                eventMouseEnter: function (info) {
                    if (info.event.allDay) {
                        return;
                    }
                    removeAllTooltips();

                    const props = info.event.extendedProps || {};
                    const bags = props.bags;

                    let caption;
                    if (bags !== undefined && bags !== null && bags > 0) {
                        const isDensity = renderMode === 'density';
                        let timeSpan;
                        if (isDensity) {
                            const startTime = moment(info.event.start);
                            const endTime = info.event.end ? moment(info.event.end) : moment(info.event.start).add(bucketSeconds, 'seconds');
                            timeSpan = `${startTime.format('h:mm a')} – ${endTime.format('h:mm a')}`;
                        } else {
                            timeSpan = moment(info.event.start).format('h:mm:ss a');
                        }
                        caption = `${bags} bag${bags > 1 ? 's' : ''} · ${timeSpan}`;
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
                eventMouseLeave: function (info) {
                    removeAllTooltips();
                    if (info.el && info.el._moveHandler) {
                        info.el.removeEventListener('mousemove', info.el._moveHandler);
                        delete info.el._tooltip;
                        delete info.el._moveHandler;
                    }
                },
                datesSet: function (info) {
                    removeAllTooltips();

                    // Keep currentStartDate/End in sync so $.ajax sends correct params
                    currentStartDate = moment(info.startStr).format('YYYY-MM-DD');
                    currentEndDate   = moment(info.endStr).subtract(1, 'days').format('YYYY-MM-DD');

                    const titleEl = document.querySelector('.ec-datePickerTitle');
                    if (titleEl) titleEl.textContent = info.view.title;
                },
                nowIndicator: false,
                selectable: false
            });

            // Date picker — same as fibre module
            $('.ec-toolbar .ec-datePickerTitle').daterangepicker({
                singleDatePicker: true,
                locale: { firstDay: 1 },
                showDropdowns: true,
                autoApply: true,
                minDate: oneYearAgo,
                maxDate: moment().add(1, 'days')
            }).on('apply.daterangepicker', function (ev, picker) {
                calendar.setOption('date', picker.startDate.toDate());
            });

            injectLegendIntoToolbar();

            function reloadEvents() {
                // Reset slot times when toggling between shift/calendar date mode
                if (activeFormat === 'shift_date') {
                    calendar.setOption('slotMinTime', slotMinTime);
                    calendar.setOption('slotMaxTime', calculateSlotMaxTime(slotMinTime));
                } else {
                    calendar.setOption('slotMinTime', '00:00:00');
                    calendar.setOption('slotMaxTime', '24:00:00');
                }
                calendar.refetchEvents();
            }

            // Function to set slot duration and reload calendar
            function setSlotDuration(duration, activeButton) {
                removeAllTooltips();
                calendar.setOption('slotDuration', duration);
                $('.ec-mins1, .ec-mins5, .ec-mins10, .ec-mins30, .ec-hr1, .ec-hr2').removeClass('ec-active');
                $(`.ec-${activeButton}`).addClass('ec-active');

                // The buttons pick the bucket width and with it the rendering, not just the
                // zoom, so the server has to re-aggregate.
                const [h, m, s] = duration.split(':').map(Number);
                bucketSeconds = h * 3600 + m * 60 + s;
                renderMode = modeForBucket(bucketSeconds);

                calendar.refetchEvents();
            }

            function setActiveButton(activeButton, inactiveButton) {
                $(`.ec-${inactiveButton}`).removeClass('ec-active');
                $(`.ec-${activeButton}`).addClass('ec-active');
            }

            // Update summary section dynamically — compact strip in the Weekly Production
            // Schedule header, matching the pith IoT Insights summary style.
            function updateSummary(totals, colors) {
                const summaryContainer = document.getElementById('summary');
                if (!summaryContainer) return;
                summaryContainer.innerHTML = '';
                summaryContainer.style.display = 'flex';
                summaryContainer.style.alignItems = 'center';

                const paletteColors = Object.values(colors?.shifts ?? {});
                const palette = paletteColors.length ? paletteColors : shiftColors;
                const entries = Object.entries(totals || {});
                const showTotal = entries.length > 1;

                const strip = document.createElement('div');
                strip.style.cssText = `display:inline-flex; align-items:stretch; background:white; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); max-width:100%;`;

                const bagIconPath = 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10';

                // ── No shift data ─────────────────────────────────────────────────────────
                if (entries.length === 0) {
                    strip.innerHTML = `
                        <div style="display:flex; align-items:center; gap:10px; padding:6px 14px;">
                            <div style="width:28px; height:28px; border-radius:7px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <svg width="16" height="16" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path d="${bagIconPath}"/>
                                </svg>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#374151; font-weight:500; font-family:inherit;">Growbag Production</div>
                                <div style="font-size:16px; font-weight:600; color:#d1d5db; line-height:1.2; font-family:inherit;">— <span style="font-size:12px; font-weight:400;">No shift data</span></div>
                            </div>
                        </div>`;
                    summaryContainer.appendChild(strip);

                    return;
                }

                // ── Shift cells ───────────────────────────────────────────────────────────
                let totalBags = 0;
                let totalEb   = 0;

                entries.forEach(([shiftName, data], index) => {
                    totalBags += data.total_bags;
                    totalEb   += data.total_eb_price;

                    const borderColor = palette[index % palette.length] ?? densityFill;

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
                                <path d="${bagIconPath}"/>
                            </svg>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:12px; color:#374151; font-weight:500; font-family:inherit;">${shiftName}</div>
                            <div style="font-size:16px; font-weight:600; color:${borderColor}; line-height:1.2; font-family:inherit;">
                                <span class="counter-value">0</span> <span style="font-size:12px; color:#6b7280; font-weight:400;">bags</span>
                            </div>
                            ${data.total_eb_price ? `<div style="display:flex; align-items:center; gap:3px; font-size:12px; color:#6b7280; font-family:inherit;"><img src="{{ asset('images/line-icon.png') }}" class="w-2.5" alt="">${formatToIndianRupees(data.total_eb_price)}</div>` : ''}
                        </div>`;

                    strip.appendChild(cell);

                    setTimeout(() => {
                        const span = cell.querySelector('.counter-value');
                        if (span) animateValue(span, 0, data.total_bags, 800);
                    }, index * 100);
                });

                // ── Total cell (only when 2+ shifts) ─────────────────────────────────────
                if (showTotal) {
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
                                <span class="counter-value">0</span> <span style="font-size:12px; color:#6b7280; font-weight:400;">bags</span>
                            </div>
                            ${totalEb ? `<div style="display:flex; align-items:center; gap:3px; font-size:12px; color:#6b7280; font-family:inherit;"><img src="{{ asset('images/line-icon.png') }}" class="w-2.5" alt="">${formatToIndianRupees(totalEb)}</div>` : ''}
                        </div>`;

                    strip.appendChild(totalCell);

                    setTimeout(() => {
                        const span = totalCell.querySelector('.counter-value');
                        if (span) animateValue(span, 0, totalBags, 1000);
                    }, entries.length * 100);
                }

                summaryContainer.appendChild(strip);
            }

            // The ramp legend sits in the toolbar's centre, between the date navigation
            // and the granularity buttons, which is why it is moved there rather than
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

            function removeAllTooltips() {
                document.querySelectorAll('.event-tooltip').forEach(el => el.remove());
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
        /* ── All-day day card ────────────────────────────────────────────────────
           Built server-side in IotGrowbagsController::buildDayCard(); everything below
           is the styling for that markup. The card owns the full width of its
           column, which is what lets the counts share a left edge and the costs a
           right edge across all seven days.

           iotinsights.css is linked from the header include, which the layout
           renders ahead of this block, so the overrides of its all-day rules carry
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
        /* Top-align the seven cards so a day that ran one shift shares a baseline
           with a day that ran three, instead of floating half a row lower. */
        .ec-all-day .ec-days {
            align-items: stretch !important;
        }
        .ec-all-day .ec-day {
            padding-bottom: 6px !important;
            align-content: start !important;
        }
        /* The card is a panel on the all-day band's grey, matching the pith Production
           card so the two IoT Insights pages read as one design. */
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
           Built server-side in IotGrowbagsController::buildDayCard(); everything below is
           the styling for that markup, and it mirrors the pith card's (.pd-*) so a
           reader moving between the two modules reads the same shapes. Numbers are the
           primary carrier and the bands are the supporting cue, which is why a band
           sits behind its row's text at low opacity rather than the text sitting inside
           a saturated fill. */
        .gb-card {
            display: flex;
            flex-direction: column;
            gap: 3px;
            padding: 5px 5px;
            font-variant-numeric: tabular-nums;
        }
        .gb-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 5px;
            white-space: nowrap;
        }
        /* The headline keeps its height even on a day with nothing to put in it, so the
           shift lines below stay level across all seven columns. */
        .gb-row-head {
            min-height: 19px;
        }
        .gb-big {
            font-size: 16px;
            font-weight: 600;
            color: #241d19;
            line-height: 1.15;
            font-variant-numeric: tabular-nums;
        }
        .gb-unit {
            font-size: 12px;
            font-weight: 400;
            color: #74695f;
            margin-left: 4px;
        }
        .gb-chip {
            font-size: 12px;
            font-weight: 600;
            padding: 1px 5px;
            border-radius: 3px;
            background: #f2ede6;
            color: #74695f;
        }
        /* The day's energy figure is the quiet half of the headline row: a reading to be
           taken off the card, not something to be read down the week beside the counts,
           so it is pitched well under the total it sits next to. */
        .gb-total-cost {
            font-size: 12px;
            font-weight: 400;
            color: #857b71;
        }

        /* One shift per line: the row is the chart. Bags have no estimate to compare
           against, so the line carries a single band — its length is the shift's count
           on the week's scale — and a rule where that band ends. */
        .gb-sline {
            position: relative;
            display: block;
            height: 20px;
            border-radius: 3px;
            background: #fbf9f6;
            overflow: hidden;
        }
        .gb-sline + .gb-sline {
            margin-top: 3px;
        }
        .gb-sline-empty {
            background: transparent;
            border: 1px dashed #eee8e0;
        }
        .gb-band {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            opacity: 0.28;
        }
        .gb-edge {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 2px;
            opacity: 0.95;
        }
        .gb-stext {
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
        .gb-lbl { font-size: 12px; font-weight: 600; }
        /* The count is not set bold: it is already the darkest and largest thing on the
           line, and the dot then separates it from the energy figure without the two
           having to differ in weight as well. */
        .gb-nums { font-size: 12px; color: #857b71; font-variant-numeric: tabular-nums; }
        .gb-nums b { font-size: 12px; font-weight: 400; color: #241d19; }
        .gb-muted { color: #857b71; }
        /* Cost per bag is a rate, not a quantity — the gap above it is what separates
           it from the counts it is derived from. */
        .gb-eff {
            margin-top: 5px;
            text-align: right;
            font-size: 12px;
            color: #857b71;
        }

        /* Density blocks fill their slot edge to edge so a run of buckets reads as one
           column whose shading varies, rather than as a stack of separate pills. */
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
        /* Bar length is the magnitude and shading reinforces it; the remainder of the row
           stays on the event's own shift-tinted track, so a short bucket still reads as
           "this bucket exists, on this shift". */
        .bag-bar-row {
            display: flex;
            align-items: center;
            height: 100%;
            width: 100%;
        }
        .bag-bar-fill {
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
        .bag-bar-text {
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
            pointer-events: none;
        }
        /* Buckets past the 95th-percentile scale run off the end rather than pretending
           to be equal to it. */
        .bag-bar-over {
            border-right: 3px solid #0f172a;
        }
    </style>
</x-app-layout>
