<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectActivityAndComment extends Model
{
    protected $table = 'project_activity_and_comments';

    protected $fillable = [
        'project_id',
        'user_id',
        'task_id',
        'type',
        'description',
        'attachments',
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
