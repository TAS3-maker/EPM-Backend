<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Accessory extends Model
{
    protected $fillable = [
        'category_id', 'brand_name', 'vendor_name', 'purchase_date',
        'purchase_amount', 'stock_quantity', 'warranty_months','condition','notes', 'images',
    ];

    public function category()
    {
        return $this->belongsTo(AccessoryCategory::class, 'category_id');
    }

    public function assigns()
    {
        return $this->hasMany(AccessoryAssign::class);
    }
}
