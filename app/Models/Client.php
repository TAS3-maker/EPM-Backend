<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

       protected $fillable = [
        'client_type',
        'name',
        'contact_detail',
        'client_email',
        'client_number',
        'hire_on_id',
        'company_name',
        'company_address',
        'project_type',
        'communication'
    ];

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

	/*public function projects()
    {
        return $this->hasMany(Project::class, 'client_id');
    }*/
}
