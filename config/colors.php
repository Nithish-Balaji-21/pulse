<?php

return [
    'pith' => [
        'total' => env('COLOR_PRIMARY'),
    ],

    'fibre' => [
        'total' => env('COLOR_SECONDARY'),
    ],

    /*
     * Growbags module — the fallback colour on the IoT Insights density bars, for a bucket
     * the server could not place on a shift. COLOR_PRIMARY, matching the bags series on the
     * growbag dashboard and the "Bags" legend chip above the calendar.
     */
    'growbags' => [
        'total' => env('COLOR_PRIMARY'),
    ],

    /*
     * Water module — the IoT Insights "Water Consumed" calendar: the
     * all-day litre pills and the hourly reading marks.
     *
     * This shares the blue family the Water Consumed chart on the dashboard
     * already uses (see `water_consumption_charts` below) so the same metric
     * reads the same in both places. COLOR_7 is a generic salmon series slot
     * reused by attendance/expense charts and carries no water meaning — it is
     * what made the calendar render pink beside a blue dashboard chart.
     *
     * COLOR_6_MAIN is the darker of the two water blues, deliberately: the
     * all-day pill prints its litre total in white, which needs the contrast.
     */
    'water' => [
        'total' => env('COLOR_6_MAIN'),
    ],

    'shift' => [
        1 => env('COLOR_PRIMARY'),
        2 => env('COLOR_SECONDARY'),
        3 => env('COLOR_TERTIARY'),
    ],

    'iot_totals' => [
        'total' => env('COLOR_10'),
    ],

    'pith_base' => [
        'expected' => env('COLOR_SECONDARY'),
        'actual' => env('COLOR_PRIMARY'),
        'closing_stock' => env('COLOR_TERTIARY'),
    ],

    'pith_machine_colors' => [
        env('COLOR_PRIMARY'),
        env('COLOR_SECONDARY'),
        env('COLOR_TERTIARY'),
        env('COLOR_7'),
        env('COLOR_10'),
        env('COLOR_QUATERNARY'),
    ],

    /*
     * Machine power states — the industrial convention, not the brand palette.
     *
     * These read as a severity ramp so an operator can judge a factory at a
     * glance without consulting a legend:
     *
     *   efficient  running well            green   (healthy)
     *   idle       underloaded             amber   (attention)
     *   overload   over-current            red     (fault — the only red here)
     *   off        stopped, still powered  grey    (a state, not a fault)
     *   shutdown   de-energised, no mains  slate   (dark = dead, per ISA-101)
     *   missing    no telemetry            violet  (unknown, distinct from any
     *                                              real machine condition)
     *
     * They are deliberately literal rather than env() palette slots
     * (COLOR_PRIMARY etc.), because those slots are generic
     * chart-series colours reused across modules and carry no state meaning —
     * routing states through them is what made "efficient" render maroon and
     * "overload" render orange.
     */
    'states' => [
        'shutdown' => env('COLOR_STATE_SHUTDOWN', '#334155'),
        'off' => env('COLOR_STATE_OFF', '#9ca3af'),
        'idle' => env('COLOR_STATE_IDLE', '#f59e0b'),
        'efficient' => env('COLOR_STATE_EFFICIENT', '#16a34a'),
        'overload' => env('COLOR_STATE_OVERLOAD', '#dc2626'),
        'missing' => env('COLOR_STATE_MISSING', '#7c3aed'),
        'labels' => env('COLOR_9'),

        'group_on' => env('COLOR_STATE_GROUP_ON', '#16a34a'),
        'group_off' => env('COLOR_STATE_GROUP_OFF', '#9ca3af'),
        'group_missing' => env('COLOR_STATE_GROUP_MISSING', '#000000'),
    ],

    'pith_load_charts' => [
        'expected' => env('COLOR_SECONDARY'),
        'actual' => env('COLOR_PRIMARY'),
        'eb_units' => env('COLOR_TERTIARY'),
        'eb_stroke' => env('COLOR_8'),
    ],

    'minibales_charts' => [
        'expected' => env('COLOR_SECONDARY'),
        'actual' => env('COLOR_PRIMARY'),
        'closing_stock' => env('COLOR_TERTIARY'),
        'closing_stock_stroke' => env('COLOR_8'),
        'all_button' => env('COLOR_11'),
        'machines' => [
            env('COLOR_PRIMARY'),
            env('COLOR_SECONDARY'),
            env('COLOR_TERTIARY'),
            env('COLOR_7'),
            env('COLOR_10'),
            env('COLOR_QUATERNARY'),
        ],
        'machine_default' => env('COLOR_PRIMARY'),
    ],

    'pith_blocks_charts' => [
        'actual' => env('COLOR_PRIMARY'),
        'stroke' => env('COLOR_8'),
    ],

    'water_consumption_charts' => [
        'base' => env('COLOR_6_VARIANT'),
        'end' => env('COLOR_7'),
        'stroke' => env('COLOR_8'),
        'husk' => env('COLOR_6_MAIN'),
    ],

    'tank_level_charts' => [
        'base' => env('COLOR_SECONDARY'),
        'end' => env('COLOR_SECONDARY'),
        'stroke' => env('COLOR_9'),
    ],

    'attendance_charts' => [
        'salary' => env('COLOR_7'),
        'data_labels' => env('COLOR_QUINARY'),
        'title' => env('COLOR_9'),
    ],

    'expense_charts' => [
        'slices' => [
            env('COLOR_PRIMARY'),
            env('COLOR_SECONDARY'),
            env('COLOR_7'),
            env('COLOR_10'),
        ],
        'title' => env('COLOR_9'),
    ],

    'pith_inventory' => [
        'offloaded' => [
            'bg' => '#fee2e2',  // red-100
            'main_color' => '#dc2626',   // red-600
        ],
        'taken' => [
            'bg' => '#fed7aa',  // orange-200
            'main_color' => '#ea580c',  // orange-600
        ],
        'in_stock' => [
            'bg' => '#fce7f3',  // pink-100
            'main_color' => '#ec4899',  // pink-500
        ],
        'progress' => [
            'taken' => '#ea580c',    // orange-600
            'remaining' => '#dc2626',    // red-600
        ],
        'hover' => '#eff6ff',            // blue-50
        'widget_bg' => '#ffffff',        // white
    ],

    'attendance' => [
        'staff_border' => env('COLOR_PRIMARY'),
        'hours_border' => env('COLOR_SECONDARY'),
        'salary_border' => env('COLOR_7'),
        'widget_bg' => env('COLOR_8'),
    ],

    'background' => [
        'screen' => env('COLOR_4'),
        'datepicker' => env('COLOR_8'),
        'navigation_bar' => env('COLOR_NAVBAR', '#0f172a'),
        'sidebar_bg' => env('COLOR_SIDEBAR_BG', '#fdfcfa'),
    ],

    /*
     * UI chrome — the reds that carry the app's shell and its content surfaces.
     *
     * These are per-surface roles, not a light-to-dark ramp of one colour. Each
     * surface keeps its own red so that moving from the shell into a production
     * screen reads as moving between two areas of one app, rather than as the
     * same element changing shade:
     *
     *   shell     sidebar, top bar, factory switcher, IoT calendar chrome
     *   content   production lists, focus rings, filter popover, KPI accents
     *   reports   the Reports module accent, and the attendance "Add" chip
     *   inward    receive / add-to-stock — also the end stop of the page-header
     *             gradient, and the same value as --pulse-inward in standard.css
     *   accent    shift 2, the "expected" series, the warm half of every gradient
     *   ink       inactive sidebar text; its alpha variants tint the hover fills
     *
     * `shell` and `accent` resolve through the palette slots that already carry
     * them. The rest are literal for the same reason `states` and `weights` are:
     * they name a surface, and a generic chart-series slot would not survive
     * someone re-picking that slot for a chart.
     *
     * Only two surfaces have a pressed state, because only two are clickable as
     * a whole. `hover_muted` is for ghost text ("Clear all"), never for a fill.
     */
    'chrome' => [
        'shell' => env('COLOR_PRIMARY'),
        'content' => env('COLOR_CHROME_CONTENT', '#861e1a'),
        'content_hover' => env('COLOR_CHROME_CONTENT_HOVER', '#6a1a15'),
        'reports' => env('COLOR_CHROME_REPORTS', '#7a221e'),
        'reports_hover' => env('COLOR_CHROME_REPORTS_HOVER', '#5c1916'),
        'inward' => env('COLOR_CHROME_INWARD', '#a82824'),
        'accent' => env('COLOR_SECONDARY'),
        'ink' => env('COLOR_CHROME_INK', '#4b443f'),
        'hover_muted' => env('COLOR_CHROME_HOVER_MUTED', '#8b2e2e'),
    ],

    /*
     * Warm neutral ramp — the advanced-filters popover, and nothing else so far.
     *
     * The popover floats above a page that is already grey on white, so a second
     * grey surface would have read as part of that page instead of above it. The
     * cream separates the two without spending a hue on it, and `selected` is the
     * one place a filter chip picks up warmth from `chrome.content`.
     */
    'surface_warm' => [
        'border' => '#e9e3d4',
        'border_strong' => '#dcd4c0',
        'rail' => '#fcfaf4',
        'bar' => '#fdfbf6',
        'selected' => '#fbe9dd',
        'selected_border' => '#f0c7a8',
        'hover' => '#f7f4ec',
    ],

    /*
     * Text and border greys — the effective values of the Tailwind utilities, with
     * 400-700 darkened by ~5 points of lightness. The override itself lives in
     * resources/css/app.css; these are its resolved sRGB values, kept here so that
     * consumers outside the stylesheet (the mobile app, exports) get the same ink.
     *
     * 50-300 are stock Tailwind. Those steps are backgrounds and hairlines, where
     * the default is already light enough — darkening them would close the gap
     * against the surface they sit on and turn a divider into a border.
     */
    'grey' => [
        '50' => '#f9fafb',
        '100' => '#f3f4f6',
        '200' => '#e5e7eb',
        '300' => '#d1d5dc',
        '400' => '#8b93a0',
        '500' => '#5c6373',
        '600' => '#3d4958',
        '700' => '#2b3647',
        '800' => '#1e2939',
        '900' => '#101828',
    ],

    /*
     * Fibre bale-weight quality bands — the IoT good/average/bad ramp.
     * Bands themselves are business logic and live in
     * IotFibreWeightController::getWeightColor(); only the colours are here.
     * Heavier bales are better, so `high` is the healthy end.
     */
    /*
     * Weight bands on the IoT Insights "Bales Weighed" calendar.
     *
     * One colour on the page, and it means one thing: the bale came out under weight.
     * The two in-spec bands are neutral slate — the same ramp the "Bales Produced" bars
     * use — so a normal week reads as grey and the exception is the only thing with a
     * hue on it. Three saturated hues spent the page's colour budget on the ordinary
     * case and left the failure no louder than the rest.
     *
     * Slate also keeps clear of the shift palette above, which is entirely warm
     * red-orange (#83211b / #ce7716 / #a7490b) and appears on the same page — an earlier
     * red/amber pair here read as a third and fourth shift. The alert is a dark mustard
     * rather than a bright yellow for that reason as much as for tone: it has to sit
     * below the shift oranges in both brightness and saturation to stay a warning
     * instead of reading as another shift, and white captions have to stay legible on it.
     *
     * The two neutrals are mid and dark, never pale: in the marks view a weighing is a
     * 4px line on white (.ec-event-height), which a #cbd5e1-class grey would lose.
     */
    'weights' => [
        'low' => '#a16207', // yellow-700 — below alert threshold (the exception)
        'normal' => '#94a3b8', // slate-400  — within 20kg of threshold
        'high' => '#475569', // slate-600  — comfortably above; darker reads as heavier
    ],

    /*
     * Bales chart — two things have to be readable at once: which shift a bar belongs to,
     * and whether it counts bales produced or bales weighed.
     *
     * Hue carries the shift, lightness carries the metric. Each `weighed` value is its shift's
     * colour lifted to 72% HSL lightness, so the rule is the same in every shift and in the
     * hourly view: the darker bar is produced, the lighter one beside it is weighed.
     *
     * The previous pairing routed `weighed` through the generic COLOR_QUATERNARY/QUINARY series
     * slots, which land in the same warm range as the shift colours themselves — shift 2's
     * produced (#ce7716) and weighed (#d27b00) came out ΔE 5.7 apart with a lightness gap of 1,
     * i.e. indistinguishable. The pairs below are ΔE 32 apart at worst.
     *
     * These are literal rather than env() slots for the same reason `states` and `weights` are:
     * they encode a relationship between two colours, not a free choice of palette slot. If a
     * shift colour above changes, re-derive its partner rather than picking a new slot.
     */
    'fibre_charts' => [
        'bales' => [
            'hourly_produced' => env('COLOR_PRIMARY'),
            'hourly_weighed' => '#e78e89',   // COLOR_PRIMARY lifted
            'produced_shift1' => env('COLOR_PRIMARY'),
            'produced_shift2' => env('COLOR_SECONDARY'),
            'produced_shift3' => env('COLOR_TERTIARY'),
            'weighed_shift1' => '#e78e89',   // COLOR_PRIMARY   #83211b lifted
            'weighed_shift2' => '#f1bb7e',   // COLOR_SECONDARY #ce7716 lifted
            'weighed_shift3' => '#f6ab79',   // COLOR_TERTIARY  #a7490b lifted
        ],
        'weights' => [
            // Opaque: COLOR_QUATERNARY carries an alpha channel (#d27b00ae) and rendered
            // translucent over the card, which muddied the bar against the gridlines.
            'total' => '#e78e89',
            'shift1' => env('COLOR_PRIMARY'),
            'shift2' => env('COLOR_SECONDARY'),
            'average' => env('COLOR_11'),
        ],
        'eb' => [
            'shift1' => env('COLOR_PRIMARY'),
            'shift2' => env('COLOR_SECONDARY'),
        ],
    ],

    'growbags_charts' => [
        'bags' => [
            'shift1' => env('COLOR_PRIMARY'),
            'shift2' => env('COLOR_SECONDARY'),
        ],
        'eb' => [
            'shift1' => env('COLOR_PRIMARY'),
            'shift2' => env('COLOR_SECONDARY'),
        ],
        /*
         * One colour per machine, taken in order, by both the "Growbags by Machines" lines
         * and the checkbox chips above them. The first three carry this factory's three
         * machines and are drawn from different families — brick, orange, blue — because
         * neighbouring lines are told apart by hue alone at this stroke width: the brick and
         * the burnt orange that sat first and third read as one colour. The rest stay on as
         * fallbacks for a factory running more machines than this one.
         */
        'machines' => [
            env('COLOR_PRIMARY'),
            env('COLOR_SECONDARY'),
            env('COLOR_6_MAIN'),
            env('COLOR_7'),
            env('COLOR_10'),
            env('COLOR_TERTIARY'),
            env('COLOR_QUATERNARY'),
        ],
    ],
];
