@component('mail::message')
# Welcome to {{ $teamName }}!

An account has been created for you on **{{ $teamName }}**.

Your login credentials are:
- **Name:** {{ $user->name }}
- **Email:** {{ $user->email }}
- **Mobile:** {{ $user->mobile }}
- **Password:** {{ $password }}

Thanks,<br>
CoiioT Team
@endcomponent