{{-- resources/views/emails/auth/reset-password.blade.php --}}
@component('mail::message')
# Hello {{ $userName }}! 👋

You're receiving this email because you requested a password reset for your **TeamBoard** account.

@component('mail::button', ['url' => $resetUrl, 'color' => 'primary'])
🔐 Reset My Password
@endcomponent

**Important Security Information:**
- This reset link expires in **{{ $expiresIn }} minutes**
- If you didn't request this reset, please ignore this email
- Your password won't change until you click the link above

@component('mail::panel')
**Security Tip:** Always verify the URL starts with your TeamBoard domain before entering your new password.
@endcomponent

Need help? Contact our support team.

Best regards,<br>
The **TeamBoard** Team

@component('mail::subcopy')
If you're having trouble clicking the "Reset My Password" button, copy and paste this URL into your web browser:<br>
{{ $resetUrl }}
@endcomponent
@endcomponent