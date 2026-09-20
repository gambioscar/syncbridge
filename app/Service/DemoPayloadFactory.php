<?php

declare(strict_types=1);

namespace SyncBridge\Service;

use SyncBridge\Support\Uuid;

final class DemoPayloadFactory
{
    private const FIRST_NAMES = ['Anna', 'Luca', 'Sofia', 'Marco', 'Giulia', 'Paolo'];
    private const LAST_NAMES = ['Rossi', 'Bianchi', 'Conti', 'Romano', 'Costa', 'Gallo'];
    private const PRODUCTS = [
        ['sku' => 'DESK-LAMP-01', 'name' => 'Lampada da scrivania', 'price' => 79.90],
        ['sku' => 'OFFICE-CHAIR-02', 'name' => 'Sedia ergonomica', 'price' => 249.00],
        ['sku' => 'USB-HUB-07', 'name' => 'Hub USB-C', 'price' => 54.50],
        ['sku' => 'KEYBOARD-04', 'name' => 'Tastiera meccanica', 'price' => 119.00],
    ];

    /** @return array<string, mixed> */
    public function make(string $type, string $scenario): array
    {
        if (!in_array($scenario, ['success', 'temporary_failure', 'permanent_failure'], true)) {
            throw new \InvalidArgumentException('Unsupported demo scenario.');
        }

        return match ($type) {
            'customer.updated' => $this->customer($scenario),
            'order.created' => $this->order($scenario),
            default => throw new \InvalidArgumentException('Unsupported demo event type.'),
        };
    }

    /** @return array<string, mixed> */
    private function customer(string $scenario): array
    {
        $firstName = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
        $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
        $externalId = 'CUS-' . random_int(10000, 99999);

        return [
            'event_id' => Uuid::v4(),
            'event_type' => 'customer.updated',
            'occurred_at' => gmdate('c'),
            'scenario' => $scenario,
            'data' => [
                'customer_id' => $externalId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => strtolower($firstName . '.' . $lastName) . '@demo.example',
                'country' => 'IT',
                'marketing_consent' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function order(string $scenario): array
    {
        $product = self::PRODUCTS[array_rand(self::PRODUCTS)];
        $quantity = random_int(1, 3);
        $subtotal = round($product['price'] * $quantity, 2);
        $tax = round($subtotal * 0.22, 2);

        return [
            'event_id' => Uuid::v4(),
            'event_type' => 'order.created',
            'occurred_at' => gmdate('c'),
            'scenario' => $scenario,
            'data' => [
                'order_id' => 'ORD-' . gmdate('Ymd') . '-' . random_int(1000, 9999),
                'customer_id' => 'CUS-' . random_int(10000, 99999),
                'currency' => 'EUR',
                'items' => [[
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'quantity' => $quantity,
                    'unit_price' => $product['price'],
                ]],
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => round($subtotal + $tax, 2),
            ],
        ];
    }
}

