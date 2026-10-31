<x-app-layout>
    @include('pulse::iot-insights.pith.header')

    <div id="summary" style="display:none;"></div>

    <div class="px-4 pb-6 mt-4">
        {{-- No overflow-hidden on this card: it makes the card a clipping context, which
             kills `position: sticky` on the calendar's toolbar and day-name row inside
             it. The bottom bar rounds its own corners instead. --}}
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 calendar-container">
            {{-- Top Header Bar --}}
            <div class="rounded-t-2xl border-b border-gray-100 bg-gradient-to-r from-gray-50/80 via-white to-gray-50/80 px-6 py-4">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <span>Weekly Water Consumed</span>
                        </h2>
                        <p class="text-sm text-gray-500 mt-0.5">Real time water usage monitoring</p>
                    </div>

                    {{-- Top Right Controls --}}
                    <div class="flex items-center gap-4 text-sm">
                        <div id="density-legend" class="hidden items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-50 border border-gray-200">
                            <span id="density-track" class="inline-block rounded-sm" style="width:40px; height:10px;">
                                <span id="density-bar" class="block rounded-sm" style="width:70%; height:10px;"></span>
                            </span>
                            <span id="density-scale" class="text-xs text-gray-500"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Main Calendar Element --}}
            <div id="calendar" class="w-full"></div>

            {{-- Bottom Legend Bar (Matching Screenshot 2) --}}
            <div class="flex items-center justify-between px-6 py-3 border-t border-gray-100 rounded-b-2xl bg-gradient-to-r from-gray-50/50 via-white to-gray-50/50 text-xs text-gray-500 font-medium">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"/>
                    </svg>
                    <span>Values are in Litres (L)</span>
                </div>
                <div class="flex items-center gap-2">
                    <span>Low Usage</span>
                    <div class="flex items-center gap-1">
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#eff6ff; border:1px solid #dbeafe;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#dbeafe;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#93c5fd;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#60a5fa;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#3b82f6;"></span>
                        <span class="w-3.5 h-3.5 rounded-xs" style="background:#1d4ed8;"></span>
                    </div>
                    <span>High Usage</span>
                </div>
            </div>
        </div>
    </div>

    <script src="@assetVersion('js/utils.js')"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const calendarEl = document.getElementById('calendar');
            let today = '{{ $today }}';
            let liveDay = '{{ $liveDay ?? $today }}';
            const oneYearAgo = moment(today).subtract(1, 'year');
            const validRangeEnd = () => moment(today).add(1, 'day').format('YYYY-MM-DD');

            const slotMinTime = '00:00:00';
            const slotMaxTime = calculateSlotMaxTime(slotMinTime, 24);
            let slotDuration = '00:30:00';

            const DENSITY_MIN_BUCKET = 300;
            const modeForBucket = (seconds) => seconds >= DENSITY_MIN_BUCKET ? 'density' : 'marks';
            let bucketSeconds = 1800;
            let renderMode = modeForBucket(bucketSeconds);
            let densityFill = '{{ config('colors.water.total') }}';

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

            function updateSummary(data = {}) {
                const summaryContainer = document.getElementById('summary');
                if (!summaryContainer) return;
                summaryContainer.innerHTML = '';
                summaryContainer.style.display = 'flex';
                summaryContainer.style.alignItems = 'center';

                const entries = Object.entries(data);
                const waterColor = '#2563eb';

                const strip = document.createElement('div');
                strip.style.cssText = `display:inline-flex; align-items:center; background:white; border:1px solid #f1f5f9; border-radius:14px; padding:8px 18px; box-shadow:0 1px 4px rgba(0,0,0,0.04); max-width:100%; gap:14px;`;

                if (entries.length === 0) {
                    strip.innerHTML = `
                        <div style="width:36px; height:36px; border-radius:10px; background:#eff6ff; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="20" height="20" fill="#2563eb" viewBox="0 0 24 24">
                                <path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:12px; color:#64748b; font-weight:500;">Total Litres</div>
                            <div style="font-size:18px; font-weight:600; color:#0f172a; line-height:1.2;">
                                0 <span style="font-size:12px; color:#64748b; font-weight:400;">L</span>
                            </div>
                        </div>`;
                    summaryContainer.appendChild(strip);
                    injectSummaryIntoToolbar();
                    return;
                }

                entries.forEach(([label, value], index) => {
                    const totalVal = value || 0;
                    strip.innerHTML = `
                        <div style="width:36px; height:36px; border-radius:10px; background:#eff6ff; border:1px solid #dbeafe; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="20" height="20" fill="#2563eb" viewBox="0 0 24 24">
                                <path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"/>
                            </svg>
                        </div>
                        <div style="display:flex; flex-direction:column;">
                            <div style="font-size:12px; color:#64748b; font-weight:600; font-family:inherit;">Total Litres</div>
                            <div style="display:flex; align-items:baseline; gap:6px;">
                                <div style="font-size:18px; font-weight:600; color:#0f172a; line-height:1.1; font-family:inherit; font-variant-numeric:tabular-nums;">
                                    <span class="counter-value">0</span>
                                    <span style="font-size:12px; color:#64748b; font-weight:400;"> L</span>
                                </div>
                            </div>
                        </div>`;

                    setTimeout(() => {
                        const span = strip.querySelector('.counter-value');
                        if (span) animateWaterValue(span, 0, totalVal, 800);
                    }, 50);
                });

                summaryContainer.appendChild(strip);
                injectSummaryIntoToolbar();
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

            function animateWaterValue(element, start, end, duration) {
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
                document.getElementById('density-bar').style.background = density.fill;
                document.getElementById('density-track').style.background = density.track;
                document.getElementById('density-scale').textContent =
                    `full bar = ${formatToIndianNumber(density.scale)} L / ${describeBucket(density.bucketSeconds)}`
                    + (density.peak > density.scale ? ` · peak ${formatToIndianNumber(density.peak)}` : '');
            }

            function describeBucket(seconds) {
                return seconds >= 3600 ? `${seconds / 3600}h` : `${seconds / 60}m`;
            }

            const calendar = EventCalendar.create(calendarEl, {
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
                    mins1:  { text: '1m', click: function() { setSlotDuration('00:01:00', 'mins1'); } },
                    mins5:  { text: '5m', click: function() { setSlotDuration('00:05:00', 'mins5'); } },
                    mins15: { text: '15m', click: function() { setSlotDuration('00:15:00', 'mins15'); } },
                    mins30: { text: '30m', click: function() { setSlotDuration('00:30:00', 'mins30'); }, active: true },
                    hr1:    { text: '1h', click: function() { setSlotDuration('01:00:00', 'hr1'); } },
                    hr2:    { text: '2h', click: function() { setSlotDuration('02:00:00', 'hr2'); } },
                },
                eventSources: [{
                    events: function (fetchInfo, successCallback, failureCallback) {
                        const startStr = moment(fetchInfo.startStr).format('YYYY-MM-DD');
                        const endStr = moment(fetchInfo.endStr).subtract(1, 'day').format('YYYY-MM-DD');

                        $.ajax({
                            url: '/{{ $teamId }}/iot-water-consume/fetch-events',
                            method: 'GET',
                            data: {
                                start: startStr,
                                end: endStr,
                                mode: renderMode,
                                bucket: bucketSeconds,
                                field_id: '{{ $fieldId }}'
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
                        return {
                            html: `<div class="all-day-chip-container">${info.event.title}</div>`
                        };
                    }

                    const props = info.event.extendedProps;
                    if (renderMode !== 'density' || !props.litres) {
                        return { html: '' };
                    }

                    const pct = props.pct || 0;

                    // 6 Tier Color Intensity Scale matching Screenshot 2
                    let fillBg = '#eff6ff';
                    let textColor = '#1e40af';

                    if (pct > 80) {
                        fillBg = '#1d4ed8'; // deep blue
                        textColor = '#ffffff';
                    } else if (pct > 60) {
                        fillBg = '#2563eb';
                        textColor = '#ffffff';
                    } else if (pct > 40) {
                        fillBg = '#3b82f6';
                        textColor = '#ffffff';
                    } else if (pct > 25) {
                        fillBg = '#60a5fa';
                        textColor = '#1e3a8a';
                    } else if (pct > 10) {
                        fillBg = '#93c5fd';
                        textColor = '#1e3a8a';
                    } else {
                        fillBg = '#dbeafe';
                        textColor = '#1e40af';
                    }

                    const label = `${formatToIndianNumber(props.litres)} L`;

                    return {
                        html: `<div class="water-bar-fill" style="width:100%; background:${fillBg}; color:${textColor};">
                                 <span class="water-bar-text">${label}</span>
                               </div>`
                    };
                },
                eventMouseEnter: function(info) {
                    if (info.event.allDay) {
                        return;
                    }
                    removeAllTooltips();

                    const props = info.event.extendedProps || {};
                    const litres = props.litres;

                    let caption;
                    if (litres !== undefined && litres !== null && litres > 0) {
                        const isDensity = renderMode === 'density';
                        let timeSpan;
                        if (isDensity) {
                            const startTime = moment(info.event.start);
                            const endTime = info.event.end ? moment(info.event.end) : moment(info.event.start).add(bucketSeconds, 'seconds');
                            timeSpan = `${startTime.format('h:mm a')} – ${endTime.format('h:mm a')}`;
                        } else {
                            timeSpan = moment(info.event.start).format('h:mm:ss a');
                        }
                        caption = `${formatToIndianNumber(litres)} L · ${timeSpan}`;
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
        .ec-event-water {
            background-color: {{ $waterColors['total'] }} !important;
            margin: 0px 2px !important;
            border-radius: 4px !important;
        }
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
        .all-day-chip-container {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 4px 0;
        }
        /* The day total reads as a figure on the all-day band's own grey rather than
           as a filled chip: the count carries the emphasis, and the unit trails it
           quietly so the week scans as numbers first. */
        .ad-total {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 4px;
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
        .ad-total-reset {
            font-size: 12px;
            color: #ef4444;
        }
        .ec-bucket {
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 !important;
            background: transparent !important;
        }
        .ec-bucket .ec-event-body {
            padding: 0 !important;
            height: 100% !important;
            width: 100% !important;
            display: block !important;
            align-items: stretch !important;
            place-content: stretch !important;
        }
        .water-bar-fill {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding-left: 8px;
            padding-right: 8px;
            box-sizing: border-box;
            border-radius: 4px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.2);
            transition: width 0.3s ease;
        }
        .water-bar-text {
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
        }
        .event-tooltip {
            position: absolute;
            z-index: 9999;
            background: rgba(15, 23, 42, 0.92);
            backdrop-filter: blur(8px);
            color: #ffffff;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.15);
            pointer-events: none;
        }
    </style>
</x-app-layout>
