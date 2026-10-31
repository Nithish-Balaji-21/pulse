@component('mail::message')
# Device Health Alert Report

A new device health row was inserted with one or more alert conditions.

## Triggered Issues
@foreach ($issues as $issue)
- {{ $issue }}
@endforeach

## Current Row
- **ID:** {{ $row->id ?? 'N/A' }}
- **Client ID:** {{ $row->client_id ?? 'N/A' }}
- **Timestamp:** {{ $row->timestamp ?? 'N/A' }}
- **MFM Status:** {{ $row->mfm_status ?? 'N/A' }}
- **MFM Fail Streak:** {{ $row->mfm_fail_streak ?? 'N/A' }}
- **Queue Count:** {{ $row->queue_count ?? 'N/A' }}
- **Queue Status:** {{ $row->queue_status ?? 'N/A' }}
- **UART Status:** {{ $row->uart_status ?? 'N/A' }}
- **Machine Running:** {{ $row->machine_running ?? 'N/A' }}
- **Alert Triggered:** {{ $row->alert_triggered ?? 'N/A' }}

Thanks,<br>
CoiioT Team
@endcomponent
