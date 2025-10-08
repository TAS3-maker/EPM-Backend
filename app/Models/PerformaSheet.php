<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformaSheet extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'data' , 'status'];

    protected $casts = [
        'data' => 'array', // Decode JSON automatically
    ];

    public function user()
{
    return $this->belongsTo(User::class, 'user_id', 'id');
}

public function project()
{
    return $this->belongsTo(Project::class, 'project_id', 'id');
}
}
