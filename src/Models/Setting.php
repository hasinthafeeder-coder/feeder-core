<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Setting extends Model
{
    use SoftDeletes;

    protected $table = 'settings';

    protected $guarded = [];

    protected $casts = [
        'value' => 'string',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
