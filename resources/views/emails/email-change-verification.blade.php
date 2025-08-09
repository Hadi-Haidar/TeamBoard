@component('mail::message')
# Email Address Change Request 🔄

Hi **{{ $userName }}**,

You've requested to change your email address on TeamBoard.

**Previous email:** {{ $oldEmail }}  
**New email:** {{ $newEmail }}

To confirm this change, please verify your new email address with the code below:

@component('mail::panel')
<div style="text-align: center; padding: 30px;">
    <div style="color: #6B7280; font-size: 14px; margin-bottom: 10px;">
        EMAIL VERIFICATION CODE
    </div>
    <div style="font-size: 36px; font-weight: bold; color: #1F2937; letter-spacing: 6px; font-family: monospace; background: #F3F4F6; padding: 15px 25px; border-radius: 8px; display: inline-block;">
        {{ $verificationCode }}
    </div>
</div>
@endcomponent

## Verification Steps:
1. **Copy** the verification code above
2. **Return** to your TeamBoard profile settings
3. **Enter** the code to confirm your new email
4. **Continue** using TeamBoard with your updated email

⏰ This code expires in **{{ $expiresIn }} minutes**.

---

### Security Notice:
- This email was sent to your **new** email address
- Your account will continue using your **old** email until verified
- If you didn't request this change, please contact our support team immediately

Best regards,  
**The TeamBoard Team**

@component('mail::subcopy')
**Security tip:** Never share this code with anyone. TeamBoard will never ask for your verification code.

Didn't request this email change? Please secure your account and contact support.
@endcomponent
@endcomponent