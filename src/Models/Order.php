<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\OrderAssignmentState;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'order_number',
        'source',
        'status',
        'market_id',
        'currency_id',
        'market_code_snapshot',
        'currency_code_snapshot',
        'reseller_id',
        'reseller_company_id',
        'supplier_id',
        'customer_id',
        'cca_id',
        'available_in_pool',
        'customer_name_snapshot',
        'primary_phone_snapshot',
        'secondary_phone_snapshot',
        'primary_phone_country_id',
        'secondary_phone_country_id',
        'items_subtotal',
        'discount_amount',
        'courier_fee_amount',
        'customer_payable_amount',
        'total_weight',
        'reseller_commission_amount',
        'supplier_commission_amount',
        'company_commission_amount',
        'agent_commission_amount',
        'after_hours',
        'after_hours_warning_shown',
        'after_hours_penalty_amount',
        'duplicate_warning_shown',
        'duplicate_warning_overridden',
        'duplicate_reference_order_id',
        'duplicate_order_penalty_amount',
        'return_penalty_amount',
        'cancelled_at',
        'reactivated_at',
        'operations_hidden_at',
        'confirmed_at',
        'discount_locked_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => OrderSource::class,
            'status' => OrderStatus::class,
            'available_in_pool' => 'boolean',
            'items_subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'courier_fee_amount' => 'decimal:2',
            'customer_payable_amount' => 'decimal:2',
            'total_weight' => 'decimal:3',
            'reseller_commission_amount' => 'decimal:2',
            'supplier_commission_amount' => 'decimal:2',
            'company_commission_amount' => 'decimal:2',
            'agent_commission_amount' => 'decimal:2',
            'after_hours' => 'boolean',
            'after_hours_warning_shown' => 'boolean',
            'after_hours_penalty_amount' => 'decimal:2',
            'duplicate_warning_shown' => 'boolean',
            'duplicate_warning_overridden' => 'boolean',
            'duplicate_order_penalty_amount' => 'decimal:2',
            'return_penalty_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'reactivated_at' => 'datetime',
            'operations_hidden_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'discount_locked_at' => 'datetime',
        ];
    }

    public function isDiscountLocked(): bool
    {
        return $this->discount_locked_at !== null
            || $this->confirmed_at !== null
            || $this->status === OrderStatus::CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::CANCELLED;
    }

    public function assignmentState(): OrderAssignmentState
    {
        return OrderAssignmentState::fromOrder(
            $this->cca_id !== null ? (int) $this->cca_id : null,
            (bool) $this->available_in_pool,
        );
    }

    public function isInOrderPool(): bool
    {
        return $this->assignmentState() === OrderAssignmentState::POOL;
    }

    public function scopeAssignmentState(Builder $query, OrderAssignmentState|string $state): Builder
    {
        $state = $state instanceof OrderAssignmentState
            ? $state
            : OrderAssignmentState::from($state);

        return match ($state) {
            OrderAssignmentState::ASSIGNED => $query->whereNotNull('cca_id'),
            OrderAssignmentState::UNASSIGNED => $query
                ->whereNull('cca_id')
                ->where('available_in_pool', false),
            OrderAssignmentState::POOL => $query
                ->whereNull('cca_id')
                ->where('available_in_pool', true),
        };
    }

    /**
     * Operational hide deadline for cancelled orders (approximately 14 days).
     */
    public function isOperationsHidden(): bool
    {
        if ($this->operations_hidden_at !== null && $this->operations_hidden_at->lte(now())) {
            return true;
        }

        if ($this->isCancelled()
            && $this->cancelled_at !== null
            && $this->cancelled_at->copy()->addDays(14)->lte(now())
        ) {
            return true;
        }

        return false;
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }

            if ($order->status === null) {
                $order->status = OrderStatus::PENDING;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function resellerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'reseller_company_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function cca(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cca_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function primaryPhoneCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'primary_phone_country_id');
    }

    public function secondaryPhoneCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'secondary_phone_country_id');
    }

    public function duplicateReferenceOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_reference_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function address(): HasOne
    {
        return $this->hasOne(OrderAddress::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function ccaAssignments(): HasMany
    {
        return $this->hasMany(OrderCcaAssignment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(OrderComment::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasBookedShipment(): bool
    {
        return $this->shipment !== null && $this->shipment->isLocked();
    }

    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->where('status', '!=', OrderStatus::CANCELLED->value);
    }
}
