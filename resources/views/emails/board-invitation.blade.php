@component('mail::message')
# You're Invited to Join a Board! 🎉

Hi **{{ $recipientName }}**,

**{{ $inviterName }}** has invited you to collaborate on the **{{ $boardTitle }}** board on TeamBoard.

@component('mail::panel')
<div style="text-align: center; padding: 20px;">
    <div style="color: #6B7280; font-size: 14px; margin-bottom: 10px;">
        BOARD INVITATION
    </div>
    <div style="font-size: 24px; font-weight: bold; color: #1F2937; margin-bottom: 10px;">
        {{ $boardTitle }}
    </div>
    <div style="color: #6B7280; font-size: 16px;">
        Role: **{{ $role }}**
    </div>
</div>
@endcomponent

## Ready to collaborate?

@if($isExistingUser)
You already have a TeamBoard account, so you can accept this invitation right away!
@else
Don't have a TeamBoard account yet? No worries! When you accept this invitation, you'll be able to create your account and join the board immediately.
@endif

@component('mail::button', ['url' => $invitationUrl, 'color' => 'primary'])
Accept Invitation
@endcomponent

### What you can do as a {{ $role }}:
@if($role === 'Member')
- ✅ **View and edit** all board content
- ✅ **Create and manage** tasks and lists  
- ✅ **Add comments** and attachments
- ✅ **Collaborate** with team members in real-time
@else
- ✅ **View** all board content
- ✅ **Add comments** to tasks
- ✅ **Follow progress** and updates
- ✅ **Stay informed** about project developments
@endif

⏰ This invitation expires in **{{ $expiresIn }} days**.

---

### Your invitation details:
- **Board:** {{ $boardTitle }}
- **Invited by:** {{ $inviterName }}
- **Role:** {{ $role }}
- **Invitation Code:** `{{ $invitationToken }}`

If you're having trouble with the button above, you can copy and paste this link into your browser:
{{ $invitationUrl }}

Best regards,  
**The TeamBoard Team**

@component('mail::subcopy')
**Security note:** This invitation is personal and should not be shared. If you didn't expect this invitation or don't know {{ $inviterName }}, you can safely ignore this email.

Need help? Contact us at support@teamboard.com
@endcomponent
@endcomponent
