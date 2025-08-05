@component('mail::message')
# Reset Your Password

You are receiving this email because we received a password reset request for your account.

@component('mail::button', ['url' => $resetUrl])
Reset Password
@endcomponent

This password reset link will expire in {{ config('auth.passwords.users.expire', 60) }} minutes.

If you did not request a password reset, no further action is required.

Thanks,<br>
{{ config('app.name') }}

@component('mail::subcopy')
If you're having trouble clicking the "Reset Password" button, copy and paste the URL below into your web browser: {{ $resetUrl }}
@endcomponent
@endcomponent