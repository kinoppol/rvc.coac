<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Ports the mockup's price/fee calculation exactly:
 *   fee  = fixed baht/unit, OR cost * percent / 100
 *   raw  = cost + fee
 *   price = raw rounded up per rounding_mode
 *   round = price - raw (the rounding remainder)
 */
class FeeCalculator
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function feeFor(float $cost): float
    {
        $mode = $this->settings->get('fee_mode') ?? 'fixed';
        if ($mode === 'percent') {
            $percent = (float) ($this->settings->get('fee_percent') ?? 10);
            return round($cost * $percent / 100, 4);
        }
        return (float) ($this->settings->get('fee_fixed') ?? 2);
    }

    /**
     * @return array{fee: float, raw: float, price: float, round: float}
     */
    public function priceFor(float $cost): array
    {
        $fee = $this->feeFor($cost);
        $raw = $cost + $fee;
        $roundingMode = $this->settings->get('rounding_mode') ?? 'whole_baht';

        $price = match ($roundingMode) {
            'quarter_baht' => ceil(($raw - 1e-9) * 4) / 4,
            'none' => round($raw, 2),
            default => ceil($raw - 1e-9), // whole_baht
        };

        return [
            'fee' => round($fee, 2),
            'raw' => round($raw, 2),
            'price' => round($price, 2),
            'round' => round($price - $raw, 2),
        ];
    }

    public function roundingBeneficiary(): string
    {
        return $this->settings->get('rounding_beneficiary') ?? 'store';
    }
}
