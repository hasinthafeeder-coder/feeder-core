<?php

namespace Feeder\Core\Support;

/**
 * Structured skip report for supplier settlement generation candidates.
 *
 * Persisted generation-exception tables are not yet available; this DTO is the
 * operational review payload returned from generation and written to logs.
 */
final class SupplierSettlementGenerationExceptionReport
{
    public const REASON_INVALID_UNIT_COST_SNAPSHOT = 'INVALID_UNIT_COST_SNAPSHOT';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $reasonDetail,
        public readonly ?int $batchId,
        public readonly ?int $orderId,
        public readonly ?int $orderItemId,
        public readonly ?string $orderNumber,
        public readonly array $context = [],
    ) {}

    /**
     * @return array{
     *     reason_code: string,
     *     reason_detail: string,
     *     batch_id: int|null,
     *     order_id: int|null,
     *     order_item_id: int|null,
     *     order_number: string|null,
     *     context: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'reason_code' => $this->reasonCode,
            'reason_detail' => $this->reasonDetail,
            'batch_id' => $this->batchId,
            'order_id' => $this->orderId,
            'order_item_id' => $this->orderItemId,
            'order_number' => $this->orderNumber,
            'context' => $this->context,
        ];
    }
}
