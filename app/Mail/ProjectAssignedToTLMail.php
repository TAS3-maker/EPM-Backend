<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProjectAssignedToTLMail extends Mailable
{
    use Queueable, SerializesModels;

    public $tl;
    public $project;
    public $assigner;

    public function __construct($tl, $project, $assigner)
    {
        $this->tl = $tl;
        $this->project = $project;
        $this->assigner = $assigner;
    }

    public function build()
    {
        return $this->subject('New Project Assigned: ' . $this->project->name)
                    ->view('emails.projects.assigned_tl'); // custom HTML template
    }
}
