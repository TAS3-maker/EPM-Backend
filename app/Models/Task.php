<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; // ✅ Correct namespace
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'project_manager_id',
        'title',
        'description',
        'hours',
        'deadline',
        'status'
    ];

    // ✅ Task belongs to a Project
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    // ✅ Task is assigned to a Project Manager
    public function projectManager()
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }
}
