<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class LeaveStatusUpdateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;    

    public $user;
    public $leave;
    public $managerName;
    public $managerRole;

    // Constructor me manager ka name aur role pass karo
    public function __construct($user, $leave, $managerName, $managerRole)
    {
        $this->user = $user;
        $this->leave = $leave;
        $this->managerName = $managerName;
        $this->managerRole = $managerRole;
    }

    public function build()
    {
        return $this->subject('Your Leave Status Updated')
                    ->view('emails.leave_status_update')
                    ->with([
                        'user' => $this->user,
                        'leave' => $this->leave,
                        'managerName' => $this->managerName,
                        'managerRole' => $this->managerRole,
                    ]);
    }
}