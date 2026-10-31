@component('mail::message')
# 📊 {{ $title }}

@component('mail::panel')
    @foreach ($bodyLines as $line)
        <p style="font-size: 18px; font-weight: 600; margin: 5px 0;">
            {{ $line }}
        </p>
    @endforeach
@endcomponent

Thanks,<br>
CoiioT Team

@component('mail::subcopy')
Don't want this email? You choose which emails and app notifications you get under **Notification preferences** — in the web app, open the account menu (your initials, top right).
@isset($preferencesUrl)

[Open my notification settings]({{ $preferencesUrl }})
@endisset
@endcomponent
@endcomponent
