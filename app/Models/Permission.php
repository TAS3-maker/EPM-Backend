<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;
    public $timestamps = true;
    protected $fillable = [
        'user_id',
        'dashboard',
        'permission',
        'employee_management',
        'roles',
        'department',
        'team',
        'clients',
        'projects',
        'assigned_projects_inside_projects_assigned',
        'unassigned_projects_inside_projects_assigned',
        'performance_sheets',
        'pending_sheets_inside_performance_sheets',
        'manage_sheets_inside_performance_sheets',
        'unfilled_sheets_inside_performance_sheets',
        'manage_leaves',
        'activity_tags',
        'leaves',
        'teams',
        'leave_management',
        'project_management',
        'assigned_projects_inside_project_management',
        'unassigned_projects_inside_project_management',
        'performance_sheet',
        'performance_history',
        'projects_assigned'
    ];

    // Relationship to User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
