@component('mail::message')
# Hello {{ $user->name }},

Your profile has been updated.

@foreach($updatedFields as $field => $value)
- **{{ $field === 'password' ? 'New Password' : ucfirst($field) }}**: {{ $value }}
@endforeach

Thanks,<br>
CoiioT Team
@endcomponent
