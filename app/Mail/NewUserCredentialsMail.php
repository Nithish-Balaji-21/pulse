<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewUserCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public $password;

    public $teamName;

    public function __construct($user, $password, $teamName)
    {
        $this->user = $user;
        $this->password = $password;
        $this->teamName = $teamName;
    }

    public function build()
    {
        return $this->subject('Your New Account Login Credentials')
            ->markdown('emails.newuser_credentials')
            ->with([
                'user' => $this->user,
                'password' => $this->password,
                'teamName' => $this->teamName,
            ]);
    }
}
