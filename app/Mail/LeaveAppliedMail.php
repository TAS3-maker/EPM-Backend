<?php

namespace App\Mail;

use App\Models\LeavePolicy;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;

class LeaveAppliedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $leave;
    public $leaveUser;

    public function __construct(LeavePolicy $leave, User $leaveUser)
    {
        $this->leave = $leave;
        $this->leaveUser = $leaveUser;
    }

    public function build()
    {
        return $this->from(config('mail.from.address'), $this->leaveUser->name)->subject(sprintf(
            'Leave Applied/%s/%s/%s',
            $this->leaveUser->employee_id,
            $this->leaveUser->name,
            Carbon::parse($this->leave->start_date)->format('d-m-Y')
        ))
            ->view('emails.leave_applied');
    }
}

