@php
    $features = [
        ['Dashboard', 3.5, 15, 14, 5.8, 'The starting page for worker status, queue workload and summary figures.'],
        ['Monitoring', 3.5, 21, 14, 5.8, 'Find jobs associated with monitored tags. Tags must be supplied by the application.'],
        ['Metrics', 3.5, 27, 14, 5.8, 'Compare recorded runtime and throughput over time. Historical charts require metric snapshots.'],
        ['Batches', 3.5, 33, 14, 5.8, 'Check groups of jobs dispatched together. An empty page can mean batching is not used.'],
        ['Pending Jobs', 3.5, 39, 14, 5.8, 'Find jobs awaiting completion and inspect whether they are waiting or processing.'],
        ['Completed Jobs', 3.5, 45, 14, 5.8, 'Jobs that reported success. Confirm the expected business output separately.'],
        ['Silenced Jobs', 3.5, 51, 14, 5.8, 'Routine jobs kept out of the normal completed list to reduce noise. They are not stopped jobs.'],
        ['Failed Jobs', 3.5, 57, 14, 5.8, 'Open retained failures to read their errors. Check the date before treating one as a current incident.'],
        ['Jobs Per Minute', 20, 26, 17.5, 9, 'Measured processing rate. Zero can be normal when no work is expected.'],
        ['Jobs Past Hour', 39, 26, 17.5, 9, 'Recent job records in the displayed history window. This is not a count of successful factory reports.'],
        ['Failed Jobs Past 7 Days', 58.5, 26, 17.5, 9, '172 in this example is a historical failure count, not 172 jobs failing now or 172 factories.'],
        ['Status', 77.5, 26, 17.5, 9, 'Active means Horizon reports a running manager. It can be active while old failure records remain.'],
        ['Total Processes', 20, 41, 17.5, 9, 'Four means four reported workers, not four machines. Workers can be idle.'],
        ['Max Wait Time', 39, 41, 17.5, 9, 'Largest calculated queue wait estimate. A dash means no value is displayed, not a confirmed zero.'],
        ['Max Runtime', 58.5, 41, 17.5, 9, 'The queue ranked highest by Horizon\'s runtime metric. Notifications is a queue name, not a time value.'],
        ['Max Throughput', 77.5, 41, 17.5, 9, 'The queue ranked highest by the throughput metric. A leading queue is not automatically overloaded.'],
        ['Queue', 19.5, 66, 49.5, 31, 'Names the waiting line. Default handles general work; notifications handles messages; api-performance-alerts and compilation-alerts carry their respective alerts. Compilation alerts describe processing of pith, fibre, growbag, bale, block or water-meter data; they do not perform that processing.'],
        ['Jobs', 71.5, 66, 4.5, 31, 'Current workload reported in each queue. Zero does not prove every expected task was dispatched.'],
        ['Processes', 77.5, 66, 7, 31, 'Workers assigned to each queue. Here each row has one worker, giving four in total.'],
        ['Wait', 87, 66, 8.5, 31, 'Calculated waiting estimate. A few seconds is a short-wait label, not a guaranteed delivery time.'],
        ['Display control', 89.5, 3, 3, 6, 'Changes the dashboard display mode. It does not change job processing.'],
        ['Refresh', 93, 3, 3, 6, 'Requests fresh dashboard data. Refreshing does not retry jobs.'],
    ];
@endphp
<section class="horizon-walkthrough" aria-label="Horizon screenshot walkthrough">
<h2>Horizon</h2><p><a href="https://portal.coiiot.in/horizon/dashboard" target="_blank" rel="noopener noreferrer">Open Horizon</a></p>
<p>Click a number for a short explanation. Reference image only—not live data.</p>
<div style="overflow-x:auto"><div class="horizon-image-map">
<img src="/monitor/guide/images/horizon" alt="Horizon reference dashboard showing overview metrics, navigation and four workload queues">
@foreach($features as $index => $feature)
<button type="button" class="feature-box" aria-haspopup="dialog" data-feature-title="{{ $index + 1 }}. {{ $feature[0] }}" data-feature-description="{{ $feature[5] }}" aria-label="{{ $index + 1 }}. {{ $feature[0] }}" style="left:{{ $feature[1] }}%;top:{{ $feature[2] }}%;width:{{ $feature[3] }}%;height:{{ $feature[4] }}%"><span>{{ $index + 1 }}</span></button>
@endforeach
</div></div>

</section>
