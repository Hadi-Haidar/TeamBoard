<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
//use Illuminate\Mail\Mailables\Content;
//use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
class ResetPassword extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;
    public $resetUrl;
    public $expiresIn;

    public function __construct(User $user, string $resetUrl)
    {
        $this->user = $user;
        $this->resetUrl = $resetUrl;
        $this->expiresIn = config('auth.passwords.users.expire', 60);
    }

    public function build()
    {
        return $this->markdown('emails.reset-password')
                    ->subject('TeamBoard - Reset Your Password')
                    ->with([
                        'userName' => $this->user->name,
                        'resetUrl' => $this->resetUrl,
                        'expiresIn' => $this->expiresIn
                    ]);
    }

}
