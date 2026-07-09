<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentStatus extends Model
{
    protected $table = 'payment_statuses';

    protected $fillable = ['name'];

    public function billings()
    {
        return $this->hasMany(Billing::class);
    }
}
