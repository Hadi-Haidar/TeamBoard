@component('mail::message')
# Verify Your Email Address

Your verification code is:

@component('mail::panel')
{{ $code }}
@endcomponent

This code will expire in 10 minutes.

Thanks,<br>
{{ config('app.name') }}
@endcomponent