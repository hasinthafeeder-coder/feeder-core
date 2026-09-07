<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderCommentContextType;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderComment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Append-only order comments. Comments are never updated or deleted.
 */
class OrderCommentService
{
    public function __construct(
        private readonly OrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * @param  int|null  $actorCompanyId  When provided, enforces reseller-company boundary.
     */
    public function add(
        Order $order,
        string $body,
        OrderCommentContextType|string $contextType = OrderCommentContextType::ORDER,
        ?int $authorUserId = null,
        ?int $authorCompanyId = null,
        ?string $contextRef = null,
        ?int $actorCompanyId = null,
    ): OrderComment {
        $contextType = $contextType instanceof OrderCommentContextType
            ? $contextType
            : OrderCommentContextType::from($contextType);

        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => ['Comment body is required.'],
            ]);
        }

        return DB::transaction(function () use (
            $order,
            $body,
            $contextType,
            $authorUserId,
            $authorCompanyId,
            $contextRef,
            $actorCompanyId,
        ) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            return OrderComment::query()->create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'author_user_id' => $authorUserId,
                'author_company_id' => $authorCompanyId ?? $order->reseller_company_id,
                'context_type' => $contextType,
                'context_ref' => $contextRef,
                'body' => $body,
            ]);
        });
    }
}
