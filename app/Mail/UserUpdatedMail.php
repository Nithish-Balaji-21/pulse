<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

class UserUpdatedMail extends Mailable
{
    use Queueable;

    public $user;

    public $updatedFields;

    public function __construct($user, $updatedFields)
    {
        $this->user = $user;
        $this->updatedFields = $updatedFields;
    }

    public function build()
    {
        return $this->subject('Your profile has been updated')
            ->markdown('emails.user_updated')
            ->with([
                'user' => $this->user,
                'updatedFields' => $this->updatedFields,
            ]);
    }
}
