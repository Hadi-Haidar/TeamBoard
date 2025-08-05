<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
//use Illuminate\Mail\Mailables\Content;
//use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPassword extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    /**
     * Create a new message instance.
     */
    public $resetUrl;
    public function __construct(string $resetUrl)
    {
        $this->resetUrl = $resetUrl;
    }
    
    public function build()
    {
        return $this->markdown('emails.reset-password')
                    ->subject('Reset Password Notification');
    }

}
