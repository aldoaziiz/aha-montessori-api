<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Billing extends Model
{
    protected $fillable = [
        'registration_id',
        'invoice_number',
        'invoice_token',
        'payment_status_id',
        'total_amount',
        'payment_receipt',
        'admin_note',
    ];

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }

    public function items()
    {
        return $this->hasMany(BillingItem::class);
    }

    public function paymentStatus()
    {
        return $this->belongsTo(PaymentStatus::class);
    }
}
