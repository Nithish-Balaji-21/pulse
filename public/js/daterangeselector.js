/*
 * Dashboard period selector — the preset bar plus its calendar.
 *
 * Markup: resources/views/Pulse/dashboard/date-range-selector.blade.php
 * Styling: public/css/date-range-selector.css
 *
 * Each dashboard using it defines `fetchDashboardData(start, end, type)`.
 */

// --- Range ---

// Default: Yesterday
let selectedRangeEnd = moment().subtract(1, 'days');
let selectedRangeStart = moment().subtract(1, 'days');

/*
 * The label stays purely factual dates — the lit pill already names the period,
 * so repeating "Yesterday" beside it would only take up room. A single day
 * prints once rather than as a range of itself, which also keeps the bar narrow
 * enough to share a row with the page title on a 1366px screen.
 */
function updateRangeUI(start, end) {
    $('#selected-range').text(
        start.isSame(end, 'day')
            ? start.format('MMM D, YYYY')
            : `${start.format('MMM D, YYYY')} – ${end.format('MMM D, YYYY')}`
    );

    toggleYesterdayOnly(start, end);
    toggleYesterdaySummaryMsg(start, end);
}

/*
 * "Rerun Yesterday Data" acts on yesterday whatever is on screen, so it is
 * offered only while yesterday is what you are looking at. Marked in the markup
 * rather than by id, because each dashboard words the control differently.
 */
function toggleYesterdayOnly(start, end) {
    const yesterday = moment().subtract(1, 'days').startOf('day');
    const isYesterday = start.isSame(yesterday, 'day') && end.isSame(yesterday, 'day');

    $('.pulse-yesterday-only').toggleClass('hidden', ! isYesterday).toggle(isYesterday);
}

/*
 * The "wait until X to get yesterday's summary" notice only makes sense while
 * yesterday's data is still what today's summary would show — i.e. the
 * selected range still extends up to today. Server-side it's already gated on
 * the current time being before the shift buffer; this gates it on the range too.
 */
function toggleYesterdaySummaryMsg(start, end) {
    const today = moment().startOf('day');
    const extendsToToday = end.isSame(today, 'day');

    $('#yesterdaySummaryMsg').toggleClass('hidden', ! extendsToToday);
}

function getFYRange(offsetYears = 0) {
    const now = moment();
    const currentFYStartYear = now.month() >= 3 ? now.year() : now.year() - 1;
    const startYear = currentFYStartYear + offsetYears;
    const endYear = startYear + 1;
    return [
        moment(`${startYear}-04-01`, 'YYYY-MM-DD').startOf('day'),
        moment(`${endYear}-03-31`, 'YYYY-MM-DD').endOf('day')
    ];
}

/*
 * Preset bar (pulse::dashboard.date-range-selector).
 *
 * The named presets resolve to a [start, end] pair each time they are read, so
 * a dashboard left open overnight still reports the right window. Each bar
 * renders only the subset its dashboard asks for; "Custom" has no range of its
 * own — it opens the calendar and stays lit for whatever the user picks that no
 * rendered preset already describes.
 *
 * Year here means the financial year (Apr–Mar), the same one getFYRange() and
 * the calendar's own "This Year" shortcut have always used.
 */
const rangePresets = {
    today: () => [moment().startOf('day'), moment().startOf('day')],
    yesterday: () => [moment().subtract(1, 'days').startOf('day'), moment().subtract(1, 'days').startOf('day')],
    '7d': () => [moment().subtract(6, 'days').startOf('day'), moment().startOf('day')],
    '1m': () => [moment().subtract(1, 'month').add(1, 'day').startOf('day'), moment().startOf('day')],
    ytd: () => [getFYRange(0)[0], moment().startOf('day')],
    this_month: () => [moment().startOf('month'), moment().endOf('month')],
    last_month: () => [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
    this_year: () => getFYRange(0),
    last_year: () => getFYRange(-1),
};

/*
 * The bucketing hint sent to the dashboard endpoints. A multi-day span is always
 * a plain range, bucketed server-side by how long it is. A single day is
 * whatever the bar declares as its day type: the fibre and growbag dashboards
 * ask for 'day', which is what gets them the hourly, per-shift view their old
 * Day tab showed — however that day was chosen, preset or calendar.
 */
let selectedRangeType = 'range';

function rangeTypeFor(start, end) {
    if (! start.isSame(end, 'day')) {
        return 'range';
    }

    return $('.pulse-range-bar').data('range-day-type') || 'range';
}

// Light up the preset the current range matches, falling back to Custom.
function updateRangePresetUI(start, end) {
    let matched = null;

    $('.pulse-range-preset').each(function () {
        const preset = $(this).data('range-preset');

        if (matched || ! rangePresets[preset]) {
            return;
        }

        const [presetStart, presetEnd] = rangePresets[preset]();

        if (start.isSame(presetStart, 'day') && end.isSame(presetEnd, 'day')) {
            matched = preset;
        }
    });

    $('.pulse-range-preset').each(function () {
        const isActive = $(this).data('range-preset') === (matched || 'custom');
        $(this).toggleClass('is-active', isActive).attr('aria-pressed', isActive);
    });
}

function applyRange(start, end, presetLabel) {
    const type = rangeTypeFor(start, end);

    selectedRangeStart = start;
    selectedRangeEnd = end;
    selectedRangeType = type;

    const picker = $('#reportrange').data('daterangepicker');
    if (picker) {
        picker.setStartDate(start);
        picker.setEndDate(end);
    }

    updateRangeUI(start, end);
    updateRangePresetUI(start, end);

    /*
     * Announced so a dashboard can react to the period without the shared
     * selector having to know about it — the fibre export button, for one, only
     * applies to a single day.
     */
    $(document).trigger('pulse:range-changed', [{
        start: start,
        end: end,
        type: type,
        isSingleDay: start.isSame(end, 'day'),
    }]);

    if (typeof fetchDashboardData === 'function') {
        fetchDashboardData(start, end, type);
    }

    if (reportsRangeApplyHandler) {
        const label = presetLabelFor(start, end, presetLabel);
        reportsRangeApplyHandler(
            start.format('YYYY-MM-DD'),
            end.format('YYYY-MM-DD'),
            label
        );
    }
}

const presetDisplayLabels = {
    today: 'Today',
    yesterday: 'Yesterday',
    '7d': '7D',
    '1m': '1M',
    ytd: 'YTD',
    this_month: 'This Month',
    last_month: 'Last Month',
    this_year: 'This Year',
    last_year: 'Last Year',
};

let reportsRangeApplyHandler = null;

function presetLabelFor(start, end, calendarLabel) {
    if (calendarLabel && calendarLabel !== 'Custom') {
        return calendarLabel;
    }

    for (const [key, fn] of Object.entries(rangePresets)) {
        const [presetStart, presetEnd] = fn();
        if (start.isSame(presetStart, 'day') && end.isSame(presetEnd, 'day')) {
            return presetDisplayLabels[key] || 'Custom';
        }
    }

    return calendarLabel || 'Custom';
}

function wireRangePresetClicks() {
    $('.pulse-range-preset').off('click.pulseRange').on('click.pulseRange', function () {
        const preset = $(this).data('range-preset');

        if (preset === 'custom') {
            $('#reportrange').trigger('click');
            return;
        }

        const [start, end] = rangePresets[preset]();
        applyRange(start, end, presetDisplayLabels[preset] || preset);
    });
}

// Preset bar + calendar. Replaces initRangePicker() where that markup is used.
function initRangeSelector() {
    reportsRangeApplyHandler = null;
    initRangePicker();
    wireRangePresetClicks();

    // Load on the bar's declared default, so the lit pill and the first fetch agree.
    const initial = $('.pulse-range-bar').data('range-default') || 'yesterday';
    $(`.pulse-range-preset[data-range-preset="${initial}"]`).trigger('click');
}

/*
 * Reports pages — same bar/calendar as the dashboard, but applies through Livewire
 * instead of fetchDashboardData(). Initial dates come from session, not the default preset.
 */
function initReportsRangeSelector({ startDate, endDate, onApply }) {
    reportsRangeApplyHandler = onApply;
    selectedRangeStart = moment(startDate).startOf('day');
    selectedRangeEnd = moment(endDate).startOf('day');

    initRangePicker();
    wireRangePresetClicks();

    updateRangeUI(selectedRangeStart, selectedRangeEnd);
    updateRangePresetUI(selectedRangeStart, selectedRangeEnd);
}

/*
 * The calendar behind "Custom". It keeps its own shortcut menu — a superset of
 * the pills, including the weekly ranges no bar offers.
 */
function initRangePicker() {
    const el = $('#reportrange');
    if (el.data('daterangepicker')) {
        el.data('daterangepicker').remove();
    }

    el.daterangepicker({
        startDate: selectedRangeStart,
        endDate: selectedRangeEnd,
        ranges: {
            'Today': [moment(), moment()],
            'This Week': [moment().startOf('isoWeek'), moment().endOf('isoWeek')],
            'This Month': [moment().startOf('month'), moment().endOf('month')],
            'This Year': getFYRange(0),
            'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            'Last Week': [moment().subtract(1, 'week').startOf('isoWeek'), moment().subtract(1, 'week').endOf('isoWeek')],
            'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
            'Last Year': getFYRange(-1),
        },
        showDropdowns: true,
        alwaysShowCalendars: true,
        linkedCalendars: false,
        autoUpdateInput: false,
        // The bar sits at the top right of the page, so the drop opens leftward to stay on screen.
        opens: 'left',
        locale: {
            format: 'DD/MM/YYYY',
            applyLabel: 'Apply',
            cancelLabel: 'Cancel',
            customRangeLabel: 'Custom'
        }
    }, function(start, end, label) {
        applyRange(start, end, label);
    });
}
