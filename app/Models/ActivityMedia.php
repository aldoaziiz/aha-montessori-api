<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityMedia extends Model
{
    protected $fillable = [
        'activity_id',
        'media_type',
        'mime_type',
        'file_name',
        'file_path',
        'file_size',
        'sort_order',
    ];

    public function activity()
    {
        return $this->belongsTo(
            Activity::class
        );
    }
}
