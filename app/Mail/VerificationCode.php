<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
//use Illuminate\Mail\Mailables\Content;
//use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
class VerificationCode extends Mailable
{
    
    use Queueable, SerializesModels;

    public $user;
    public $code;
    public $expiresIn;

    public function __construct(User $user, string $code)
    {
        $this->user = $user;
        $this->code = $code;
        $this->expiresIn = 10; // 10 minutes
    }

    public function build()
    {
        return $this->markdown('emails.verification-code')
                    ->subject('TeamBoard - Verify Your Email Address')
                    ->with([
                        'userName' => $this->user->name,
                        'verificationCode' => $this->code,
                        'expiresIn' => $this->expiresIn
                    ]);
    }
}
