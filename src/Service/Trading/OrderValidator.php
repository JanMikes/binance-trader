<?php

declare(strict_types=1);

namespace App\Service\Trading;

use Psr\Log\LoggerInterface;

/**
 * Validates orders before placing them on exchange
 */
class OrderValidator
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Validate order parameters against exchange filters
     * @param array{tick_size: float, lot_size: float, min_notional: float} $filters
     * @return array<int, string> Array of validation error messages
     */
    public function validateOrder(
        float $price,
        float $quantity,
        array $filters,
        float $availableBalance
    ): array {
        $errors = [];

        // Validate tick size
        $tickSize = $filters['tick_size'];
        if ($tickSize > 0) {
            $remainder = fmod($price, $tickSize);
            if (abs($remainder) > 0.00000001 && abs($remainder - $tickSize) > 0.00000001) {
                $errors[] = "Price {$price} does not match tick size {$tickSize}";
            }
        }

        // Validate lot size
        $lotSize = $filters['lot_size'];
        if ($lotSize > 0) {
            $remainder = fmod($quantity, $lotSize);
            if (abs($remainder) > 0.00000001 && abs($remainder - $lotSize) > 0.00000001) {
                $errors[] = "Quantity {$quantity} does not match lot size {$lotSize}";
            }
        }

        // Validate min notional
        $minNotional = $filters['min_notional'];
        $notional = $price * $quantity;
        if ($notional < $minNotional) {
            $errors[] = "Notional {$notional} is below minimum {$minNotional}";
        }

        // Validate balance
        $cost = $price * $quantity;
        if ($cost > $availableBalance) {
            $errors[] = "Insufficient balance. Required: {$cost}, Available: {$availableBalance}";
        }

        if (count($errors) > 0) {
            $this->logger->warning('Order validation failed', [
                'price' => $price,
                'quantity' => $quantity,
                'errors' => $errors,
            ]);
        }

        return $errors;
    }

    /**
     * Round price to tick size
     */
    public function roundPrice(float $price, float $tickSize, bool $roundUp = false): float
    {
        if ($tickSize == 0) {
            return $price;
        }

        if ($roundUp) {
            return ceil($price / $tickSize) * $tickSize;
        }

        return floor($price / $tickSize) * $tickSize;
    }

    /**
     * Round quantity to lot size
     */
    public function roundQuantity(float $quantity, float $lotSize, bool $roundUp = false): float
    {
        if ($lotSize == 0) {
            return $quantity;
        }

        if ($roundUp) {
            return ceil($quantity / $lotSize) * $lotSize;
        }

        return floor($quantity / $lotSize) * $lotSize;
    }
}
