<?php

namespace App\Services;

use App\Models\ProjectActivityAndComment;
use Illuminate\Support\Facades\Auth;

class ActivityService
{
    public static function log(array $data)
    {
        return ProjectActivityAndComment::create([
            'project_id'  => $data['project_id']?? null,
            'client_id'  => $data['client_id']?? null,
            'user_id'     => $data['user_id'] ?? Auth::id(),
            'task_id'     => $data['task_id'] ?? null,
            'type'        => $data['type'],
            'description' => $data['description'] ?? null,
            'attachments' => $data['attachments'] ?? null,
        ]);
    }
}
