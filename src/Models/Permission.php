<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use SoftDeletes;

    protected $guarded = [];
    protected $table = 'permissions';

    public function portal(): BelongsTo
    {
        return $this->belongsTo(Portal::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_permissions'
        )->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_permissions'
        )->withPivot('allowed')->withTimestamps();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
