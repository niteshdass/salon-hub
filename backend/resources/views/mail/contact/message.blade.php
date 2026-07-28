@component('mail::message')
# New contact message

**From:** {{ $senderName }} ({{ $senderEmail }})

@component('mail::panel')
{{ $body }}
@endcomponent

Reply directly to this email to respond to {{ $senderName }}.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
