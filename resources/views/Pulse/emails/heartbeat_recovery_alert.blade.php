@component('mail::message')
# Device Heartbeat Recovery

The device heartbeat is active again and communication has resumed.

- **Client ID:** {{ $clientId }}
- **Current Status:** {{ $currentStatus }}
- **Last Seen:** {{ $lastSeen }}
- **Checked At:** {{ $checkedAt }}

No further action is required unless the issue repeats.

Thanks,<br>
CoiioT Team
@endcomponent
