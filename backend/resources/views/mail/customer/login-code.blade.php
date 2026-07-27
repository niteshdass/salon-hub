@component('mail::message')
# Your login code

Use this code to sign in. It expires in 10 minutes.

@component('mail::panel')
# {{ $code }}
@endcomponent

If you didn't request this, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
