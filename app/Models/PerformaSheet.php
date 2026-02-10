<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformaSheet extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'data', 'status', 'approve_rejected_by'];

    protected $casts = [
        'data' => 'array', 
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    public function approved_by()
    {
        return $this->belongsTo(User::class, 'approved_by', 'id');
    }
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }
}
