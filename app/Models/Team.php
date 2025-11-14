<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ['name','department_id'];

    public function users()
    {
        return $this->hasMany(User::class, 'team_id');
    }
     // public function users()
    // {
    //     return $this->hasMany(User::class, 'team_id');
    // }
    // public function users()
    // {
    //     return $this->hasMany(User::class)
    //         ->whereJsonContains('team_id', $this->id);
    // }
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

}
