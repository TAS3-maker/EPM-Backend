<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeePerformaSheet extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public $sheets;
    public $user;
    public $submitting_user_name;
    // public $submitting_user_employee_id;


    public function __construct($sheets, $user, $submitting_user_name)
    {
        $this->sheets = $sheets;
        $this->user = $user;
        $this->submitting_user_name = $submitting_user_name;
        // $this->submitting_user_employee_id = $submitting_user_employee_id;
    }

    public function build()
    {
        return $this->subject('DRS/')
                    ->view('emails.employeeperformasheet');
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
