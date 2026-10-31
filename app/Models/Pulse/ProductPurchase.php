<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;

class ProductPurchase extends Model
{
    protected $table = 'pulse_product_purchase';

    protected $fillable = [
        'team_id',
        'item_type',
        'name',
        'description',
        'hsn_code',
        'base_unit_id',
        'purchase_unit_id',
        'selling_unit_id',
        'purchase_price',
        'selling_price',
        'gst_percent',
        'is_active',
    ];

    public function baseUnit()
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function purchaseUnit()
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function sellingUnit()
    {
        return $this->belongsTo(Unit::class, 'selling_unit_id');
    }
}
