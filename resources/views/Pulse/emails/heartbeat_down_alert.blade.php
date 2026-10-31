@component('mail::message')
# Device Heartbeat Alert

A heartbeat was not received within the expected time window.

- **Client ID:** {{ $clientId }}
- **Current Status:** {{ $currentStatus }}
- **Last Seen:** {{ $lastSeen }}
- **Checked At:** {{ $checkedAt }}

Please verify device and network connectivity.

Thanks,<br>
CoiioT Team
@endcomponent
