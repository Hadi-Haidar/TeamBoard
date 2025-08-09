<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class EmailChangeVerification extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $code;
    public $expiresIn;
    public $oldEmail;

    public function __construct(User $user, string $code, string $oldEmail = null)
    {
        $this->user = $user;
        $this->code = $code;
        $this->expiresIn = 10; // 10 minutes
        $this->oldEmail = $oldEmail ?? $user->getOriginal('email');
    }

    public function build()
    {
        return $this->markdown('emails.email-change-verification')
                    ->subject('TeamBoard - Verify Your New Email Address')
                    ->with([
                        'userName' => $this->user->name,
                        'verificationCode' => $this->code,
                        'expiresIn' => $this->expiresIn,
                        'newEmail' => $this->user->email,
                        'oldEmail' => $this->oldEmail
                    ]);
    }
}
