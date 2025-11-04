<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProjectAssignedMails extends Mailable
{
    use Queueable, SerializesModels;

    public $project;
    public $employee;

    public function __construct(Project $project, User $employee)
    {
        $this->project = $project;
        $this->employee = $employee;
    }

    public function build()
    {
        return $this->subject('You have been assigned to a project')
                    ->view('emails.project_assigned');
    }
}
