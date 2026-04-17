<?php

declare(strict_types=1);

namespace App\Handlers;

use Lumen\JsonRpc\Support\RequestContext;

/**
 * Order management handler.
 * @requiresAuth
 */
class Order
{
    public function __construct(
        private readonly RequestContext $context,
    ) {}

    /**
     * Create a new order.
     *
     * @param string $product Product name
     * @param int $quantity Quantity to order
     * @param float $price Unit price
     * @return array order data
     * @example-request {"jsonrpc": "2.0", "method": "order.create", "params": {"product": "Widget", "quantity": 5, "price": 9.99}, "id": 1}
     * @example-response {"id": 1001, "product": "Widget", "quantity": 5, "total": 49.95}
     */
    public function create(RequestContext $context, string $product, int $quantity, float $price = 0.0): array
    {
        return [
            'id' => random_int(1000, 9999),
            'product' => $product,
            'quantity' => $quantity,
            'total' => round($quantity * $price, 2),
        ];
    }

    /**
     * Get order by ID.
     *
     * @param int $id The order ID
     * @return array order data
     * @example-request {"jsonrpc": "2.0", "method": "order.get", "params": [1001], "id": 2}
     * @example-response {"id": 1001, "product": "Widget", "quantity": 5, "total": 49.95}
     */
    public function get(RequestContext $context, int $id): array
    {
        return [
            'id' => $id,
            'product' => 'Example Product',
            'quantity' => 1,
            'total' => 9.99,
        ];
    }

    /**
     * List orders with optional pagination.
     *
     * @param int $limit Maximum results
     * @param int $offset Results to skip
     * @return array order list
     * @example-request {"jsonrpc": "2.0", "method": "order.list", "params": {"limit": 20}, "id": 3}
     * @example-response {"orders": [], "total": 0}
     */
    public function list(RequestContext $context, int $limit = 20, int $offset = 0): array
    {
        return [
            'orders' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
