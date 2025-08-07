@component('mail::message')
# Welcome to TeamBoard! 🚀

Hi **{{ $userName }}**,

Thanks for joining TeamBoard! We're excited to have you on board.

To get started, please verify your email address with the code below:

@component('mail::panel')
<div style="text-align: center; padding: 30px;">
    <div style="color: #6B7280; font-size: 14px; margin-bottom: 10px;">
        YOUR VERIFICATION CODE
    </div>
    <div style="font-size: 36px; font-weight: bold; color: #1F2937; letter-spacing: 6px; font-family: monospace; background: #F3F4F6; padding: 15px 25px; border-radius: 8px; display: inline-block;">
        {{ $verificationCode }}
    </div>
</div>
@endcomponent

## Quick Setup Steps:
1. **Copy** the verification code above
2. **Return** to TeamBoard
3. **Paste** the code to verify your account
4. **Start collaborating!**

⏰ This code expires in **{{ $expiresIn }} minutes**.

Need a new code? Just click "Resend" on the verification page.

---

### What's waiting for you:
- ✅ **Create project boards** and organize tasks
- ✅ **Invite team members** and collaborate in real-time  
- ✅ **Track progress** with powerful analytics
- ✅ **Share files** securely with your team

Welcome to better teamwork!

Best regards,  
**The TeamBoard Team**

@component('mail::subcopy')
**Security tip:** Never share this code with anyone. TeamBoard will never ask for your verification code.

Didn't sign up for TeamBoard? You can safely ignore this email.
@endcomponent
@endcomponent