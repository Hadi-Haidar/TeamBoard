<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\BoardMember;

class BoardInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invitation;
    public $boardTitle;
    public $inviterName;
    public $recipientName;
    public $invitationUrl;
    public $expiresIn;

    public function __construct(BoardMember $invitation)
    {
        $this->invitation = $invitation;
        $this->boardTitle = $invitation->board->title;
        $this->inviterName = $invitation->inviter->name;
        $this->recipientName = $invitation->user?->name ?? 'there';
        $this->invitationUrl = $invitation->invitation_url;
        $this->expiresIn = 7; // 7 days
    }

    public function build()
    {
        return $this->markdown('emails.board-invitation')
                    ->subject("You're invited to join '{$this->boardTitle}' on TeamBoard")
                    ->with([
                        'boardTitle' => $this->boardTitle,
                        'inviterName' => $this->inviterName,
                        'recipientName' => $this->recipientName,
                        'role' => $this->invitation->role_display,
                        'invitationUrl' => $this->invitationUrl,
                        'invitationToken' => $this->invitation->invitation_token,
                        'expiresIn' => $this->expiresIn,
                        'isExistingUser' => $this->invitation->user !== null,
                    ]);
    }
}
