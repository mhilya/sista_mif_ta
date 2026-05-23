<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalRawData extends Model
{
    protected $guarded = ['id'];
    
    protected $casts = [
        'raw_data' => 'array',
    ];
}

