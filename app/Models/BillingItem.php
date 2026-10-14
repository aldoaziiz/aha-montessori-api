<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingItem extends Model
{
    protected $fillable = [
        'billing_id',
        'program_id',
        'description',
        'price',
        'quantity',
        'subtotal',
        'discount_percentage',
        'discount_amount',
    ];

    protected $casts = [
        'discount_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }
}
