@php
    $tabs = [
        'overview' => 'Overview',
        'queue' => 'Queue monitor',
        'horizon' => 'Horizon',
        'nightwatch' => 'Nightwatch',
        'healthchecks' => 'Healthchecks.io',
    ];
    $selected = request()->query('tab', 'overview');
    $selected = is_string($selected) && isset($tabs[$selected]) ? $selected : 'overview';
    $sections = preg_split('/(?=^## )/m', file_get_contents(resource_path('markdown/monitoring-guide.md')));
    $content = array_fill_keys(array_keys($tabs), '');
    foreach ($sections as $section) {
        $destination = match (true) {
            str_starts_with($section, '## Horizon:') => 'horizon',
            str_starts_with($section, '## Nightwatch:') => 'nightwatch',
            str_starts_with($section, '## Healthchecks.io:') => 'healthchecks',
            default => 'overview',
        };
        // The shared page heading is rendered outside the selected tab.
        $content[$destination] .= preg_replace('/\A# Monitoring guide for the team\s*/', '', $section)."\n\n";
    }
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800">DevOps Tools</h1>
    </x-slot>

    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
        <style>
            .monitoring-guide{color:#4b443f;font-size:16px;line-height:1.65}
            .monitoring-guide a{color:#83211b}
            .monitoring-guide img{display:block;max-width:100%;height:auto;border:1px solid #e5e0da;border-radius:8px;margin:20px 0}
            .monitoring-guide .guide-content h1{font-size:28px;font-weight:600;margin:24px 0 12px}
            .monitoring-guide .guide-content h2{font-size:22px;font-weight:600;margin-top:48px;padding-top:16px;border-top:1px solid #e5e0da}
            .monitoring-guide .guide-content h3{font-size:18px;font-weight:600;margin-top:30px}
            .monitoring-guide .guide-content p,.monitoring-guide .guide-content ul,.monitoring-guide .guide-content ol{margin:12px 0}
            .monitoring-guide .guide-content ul{list-style:disc;padding-left:24px}
            .monitoring-guide .guide-content ol{list-style:decimal;padding-left:24px}
            .monitoring-guide table{width:100%;border-collapse:collapse;font-size:14px;display:block;overflow:auto}
            .monitoring-guide th,.monitoring-guide td{text-align:left;padding:12px;border:1px solid #e5e0da;vertical-align:top}
            .monitoring-guide th{background:#f5f0ea}
            .monitoring-guide code{background:#f5f0ea;padding:2px 4px;overflow-wrap:anywhere}
            .monitoring-guide .guide-tabs{display:flex;flex-wrap:wrap;gap:8px;padding:12px 0 8px}
            .monitoring-guide .guide-tabs a{padding:8px 14px;border:1px solid #ddd5cc;border-radius:8px;text-decoration:none;white-space:nowrap}
            .monitoring-guide .guide-tabs a[aria-current="page"]{background:#83211b;color:white;border-color:#83211b}
            .monitoring-guide .guide-tabs a:focus-visible{outline:3px solid #d99b28;outline-offset:3px}
            .monitoring-guide .guide-content>:first-child{margin-top:12px;border-top:0;padding-top:0}
            .monitoring-guide .subtitle{color:#6b625b;margin:0}
            .monitoring-guide .print-section{display:none}
            .horizon-image-map{position:relative;min-width:900px}.horizon-image-map img{width:100%;margin:0;border:0;border-radius:0}.feature-box{position:absolute;box-sizing:border-box;border:2px solid #83211b;border-radius:4px}.feature-box span{position:absolute;top:-10px;left:0;background:#83211b;color:white;border-radius:4px;padding:0 5px;font:bold 12px/20px system-ui}.feature-box:hover,.feature-box:focus-visible{background:#83211b18;outline:3px solid #d99b28;z-index:2}.feature-explanations{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:22px}.feature-explanation{border:1px solid #ddd5cc;border-radius:8px;padding:16px;scroll-margin-top:20px}.feature-explanation h3{margin:0 0 6px;font-size:17px}.feature-explanation p{margin:0}.feature-explanation:target{background:#fff5de;border-color:#83211b}
            @media(max-width:700px){.feature-explanations{grid-template-columns:1fr}}
            @media print{.monitoring-guide .guide-content{display:none}.monitoring-guide .print-section{display:block}.monitoring-guide .subtitle,.monitoring-guide .guide-tabs{display:none}.horizon-image-map{min-width:0}.feature-explanation{break-inside:avoid}}
        </style>

        <section class="monitoring-guide bg-white rounded-lg shadow p-4 sm:p-5">
            <p class="subtitle">Choose a tool. Click a numbered feature to learn what it means.</p>
            <nav class="guide-tabs" aria-label="Guide topics">
                @foreach($tabs as $key => $label)
                    <a href="{{ route('superadmin.monitoring', ['team' => request()->route('team'), 'tab' => $key]) }}" @if($selected === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <article class="guide-content" aria-label="{{ $tabs[$selected] }}">
                @if($selected === 'queue')
                    <h2>Queue monitor</h2>
                    <p>Track failures, runtime and memory.</p>
                    <p><a href="{{ url(config('queue-monitor.ui.route_prefix', 'queue-monitor')) }}">Open queue monitor</a></p>
                @elseif($selected === 'horizon')
                    @include('monitoring.horizon-walkthrough')
                @elseif($selected === 'nightwatch')
                    @include('monitoring.nightwatch-walkthrough')
                @else
                    {!! \Illuminate\Support\Str::markdown($content[$selected], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                @endif
            </article>
            <div class="print-section">
                @include('monitoring.horizon-walkthrough')
                @include('monitoring.nightwatch-walkthrough')
                @foreach(collect($content)->except(['queue', 'horizon', 'nightwatch']) as $section)
                    {!! \Illuminate\Support\Str::markdown($section, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                @endforeach
            </div>
        </section>
    </div>

<div id="feature-popover" role="dialog" aria-labelledby="feature-popover-title" aria-describedby="feature-popover-description" tabindex="-1" hidden>
    <button type="button" class="feature-popover-close" aria-label="Close explanation">Close ×</button>
    <h2 id="feature-popover-title"></h2>
    <p id="feature-popover-description"></p>
</div>
<style>
    button.feature-box{background:transparent;padding:0;cursor:pointer}
    button.feature-box[aria-expanded="true"]{background:#83211b18;outline:3px solid #d99b28;z-index:2}
    #feature-popover{position:absolute;z-index:50;width:min(360px,calc(100vw - 24px));padding:18px;background:#fff;border:1px solid #ddd5cc;border-radius:12px;color:#4b443f;box-shadow:0 12px 40px #0003}
    #feature-popover[hidden]{display:none}
    #feature-popover h2{clear:both;border:0;margin:0 0 8px;padding:0;font-size:18px;font-weight:600}
    #feature-popover p{margin:0;line-height:1.6;font-size:15px}
    .feature-popover-close{float:right;margin:-4px -4px 4px 8px;background:#f5f0ea;border:1px solid #ddd5cc;border-radius:6px;padding:4px 10px;color:#83211b;cursor:pointer;font:inherit;font-size:13px}
    .feature-popover-close:focus-visible{outline:3px solid #d99b28;outline-offset:2px}
    .feature-popups-enabled .guide-content .feature-explanations{display:none}
    @media print{#feature-popover{display:none!important}}
</style>
<script>
(() => {
    const popover = document.getElementById('feature-popover');
    document.body.appendChild(popover);
    document.documentElement.classList.add('feature-popups-enabled');
    const title = document.getElementById('feature-popover-title');
    const description = document.getElementById('feature-popover-description');
    let trigger = null;

    const close = (restoreFocus = false) => {
        popover.hidden = true;
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus) trigger.focus({preventScroll: true});
        }
        trigger = null;
    };

    const place = (x, y) => {
        const gap = 14, margin = 12;
        const width = popover.offsetWidth, height = popover.offsetHeight;
        const left = Math.max(window.scrollX + margin, Math.min(x + gap, window.scrollX + document.documentElement.clientWidth - width - margin));
        const fitsBelow = y + gap + height <= window.scrollY + window.innerHeight - margin;
        const top = fitsBelow ? y + gap : Math.max(window.scrollY + margin, y - gap - height);
        popover.style.left = left + 'px';
        popover.style.top = top + 'px';
    };

    document.querySelectorAll('.guide-content .feature-box').forEach(box => {
        box.setAttribute('aria-haspopup', 'dialog');
        box.setAttribute('aria-expanded', 'false');
        box.addEventListener('click', event => {
            event.preventDefault();
            if (trigger && trigger !== box) trigger.setAttribute('aria-expanded', 'false');
            trigger = box;
            box.setAttribute('aria-expanded', 'true');
            title.textContent = box.dataset.featureTitle;
            description.textContent = box.dataset.featureDescription;
            popover.hidden = false;
            const keyboard = event.detail === 0;
            const rect = box.getBoundingClientRect();
            place(
                (keyboard ? rect.left + rect.width / 2 : event.clientX) + window.scrollX,
                (keyboard ? rect.bottom : event.clientY) + window.scrollY
            );
            if (keyboard) popover.focus({preventScroll: true});
        });
    });

    popover.querySelector('.feature-popover-close').addEventListener('click', () => close(true));
    document.addEventListener('click', event => {
        if (!popover.hidden && !popover.contains(event.target) && !event.target.closest('.feature-box')) close();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !popover.hidden) close(true);
    });
    window.addEventListener('resize', () => close());
})();
</script>
</x-app-layout>
