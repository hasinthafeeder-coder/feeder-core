<?php

namespace Feeder\Core\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    protected $guarded = [];

    protected $table = 'users';

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
