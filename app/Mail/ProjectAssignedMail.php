<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProjectAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $manager;
    public $project;
    public $assigner;

    public function __construct($manager, $project, $assigner)
    {
        $this->manager = $manager;
        $this->project = $project;
        $this->assigner = $assigner;
    }

    public function build()
    {
        return $this->subject('New Project Assigned: ' . $this->project->name)
                    ->view('emails.projects.assigned'); // custom HTML template
    }
}
