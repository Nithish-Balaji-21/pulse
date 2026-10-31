@php
    $features = [
        ['Application and environment', 0.4, 0.5, 13.5, 6.5, 'Identifies the application and environment being viewed. Production and local activity must be interpreted separately.'],
        ['Dashboard', 0.5, 8.5, 13, 4.5, 'Summary of captured application activity. Use it as a starting point before opening a specific event type.'],
        ['Issues', 0.5, 13.5, 13, 4.5, 'Groups problems for investigation. The badge is an issue count, not the number of affected factories.'],
        ['Requests', 0.5, 23.5, 13, 4.5, 'Captured web and API calls. Open a request to investigate its response and execution time.'],
        ['Jobs', 0.5, 28.5, 13, 4.5, 'Captured background executions. Inspect what happened while a job ran.'],
        ['Commands', 0.5, 33.5, 13, 4.5, 'Captured command-line operations, including work outside web requests.'],
        ['Scheduled Tasks', 0.5, 38.5, 13, 4.5, 'Recorded scheduled executions. Missing records alone do not prove the scheduler stopped; check collection and the external heartbeat.'],
        ['Exceptions', 0.5, 43.5, 13, 4.5, 'Recorded errors and code context. Open the relevant occurrence to investigate its cause.'],
        ['Queries', 0.5, 48.5, 13, 4.5, 'Database activity captured by Nightwatch. This is the selected screen in the reference image.'],
        ['Notifications', 0.5, 53.5, 13, 4.5, 'Captured notification activity. A sending event does not prove a recipient received the message.'],
        ['Mail', 0.5, 58.5, 13, 4.5, 'Captured email-sending activity. Use it to investigate application sending, not inbox delivery.'],
        ['Cache', 0.5, 63.5, 13, 4.5, 'Captured cache operations. Helps investigate repeated lookups and cache behaviour.'],
        ['Outgoing Requests', 0.5, 68.5, 13, 4.5, 'Calls from the application to external services. Useful when a dependency is slow or fails.'],
        ['Time range', 80, 4.7, 16.5, 4.8, 'Choose the observation period. 1H means the last hour; 24H means the last day. Calendar controls allow other supported periods.'],
        ['Calls', 18, 15.5, 7, 7, 'Number of recorded query calls in the selected scope. Zero does not establish that the database performed no queries.'],
        ['Calls chart', 18, 24, 38, 29, 'Shows captured query activity over time. The axis uses UTC here; account for the timezone when matching an incident.'],
        ['Duration chart', 57.8, 15.5, 29.5, 37.5, 'Shows timing of captured queries. A dash or blank chart means no value is available in this view, not zero execution time.'],
        ['AVG', 88.2, 15.5, 3.6, 7, 'Average duration of measured query calls. A few very slow calls can be hidden by an average.'],
        ['P95', 92.3, 15.5, 3.5, 7, 'The duration at or below which 95% of measured observations fall. Helps understand slower calls; small samples are less reliable.'],
        ['Query results', 17.5, 59, 10, 5, 'Count shown for the query list under the current scope. It is not a count of all database activity.'],
        ['Search queries', 80, 59, 16.5, 5, 'Narrows the query results. Clear search and check the time range when expected records are missing.'],
        ['No queries found', 46, 79, 22.5, 6.5, 'No matching results are displayed. Check the environment, period, search, sampling, agent and ingestion status.'],
    ];
@endphp
<section aria-label="Nightwatch screenshot walkthrough">
<h2>Nightwatch</h2>
<p><a href="https://nightwatch.laravel.com/eu/environments/a22f9492-f3f1-4e7d-81cf-773cb5ab8545/queries?period=1h" target="_blank" rel="noopener noreferrer">Open the team's Nightwatch Queries page</a> (Nightwatch access required).</p>
<p>Click a number for a short explanation. Reference image only—not live data.</p>
<div style="overflow-x:auto"><div class="horizon-image-map">
<img src="/monitor/guide/images/nightwatch" alt="Nightwatch Queries reference showing navigation, query metrics, filters and a plan-limit ingestion warning">
@foreach($features as $index => $feature)
<button type="button" class="feature-box" aria-haspopup="dialog" data-feature-title="{{ $index + 1 }}. {{ $feature[0] }}" data-feature-description="{{ $feature[5] }}" aria-label="{{ $index + 1 }}. {{ $feature[0] }}" style="left:{{ $feature[1] }}%;top:{{ $feature[2] }}%;width:{{ $feature[3] }}%;height:{{ $feature[4] }}%"><span>{{ $index + 1 }}</span></button>
@endforeach
</div></div>
</section>
