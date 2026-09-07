<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Shipment extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'order_id',
        'supplier_id',
        'courier_id',
        'courier_service_id',
        'courier_city_id',
        'supplier_courier_account_id',
        'tracking_number',
        'weight_snapshot',
        'courier_fee_snapshot',
        'currency_id',
        'pricing_rule_snapshot',
        'status',
        'booked_at',
        'booked_by',
        'external_booking_ref',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'weight_snapshot' => 'decimal:3',
            'courier_fee_snapshot' => 'decimal:2',
            'pricing_rule_snapshot' => 'array',
            'booked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Shipment $shipment): void {
            if (empty($shipment->uuid)) {
                $shipment->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(CourierService::class, 'courier_service_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(CourierCity::class, 'courier_city_id');
    }

    public function supplierCourierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierCourierAccount::class, 'supplier_courier_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function bookedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }

    public function isLocked(): bool
    {
        return $this->status === ShipmentStatus::BOOKED
            || $this->status === ShipmentStatus::IN_TRANSIT
            || $this->status === ShipmentStatus::DELIVERED
            || ($this->tracking_number !== null && $this->tracking_number !== '');
    }
}
