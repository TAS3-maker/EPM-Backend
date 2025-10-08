<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TagsActivity extends Model {
    use HasFactory;

    protected $table = 'tagsactivity'; // Ensure correct table name
    protected $fillable = ['name'];
}