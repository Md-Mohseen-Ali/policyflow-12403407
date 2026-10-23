<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculator
{
    private const PLAN_RATES = [
        'BASIC' => 12,
        'PLUS' => 20,
        'PREMIUM' => 30,
    ];

    private const COVERAGE_MULTIPLIERS = [
        100000 => 100,
        300000 => 125,
        500000 => 150,
    ];

    /**
     * Calculate the premium and return an immutable pricing snapshot.
     *
     * Multipliers are stored as integer hundredths:
     * 110 = 1.10, 125 = 1.25, 180 = 1.80.
     */
    public function calculate(
        string $dateOfBirth,
        string $tripStart,
        string $tripEnd,
        string $destination,
        string $planCode,
        int $sumInsured
    ): array {
        $planCode = strtoupper($planCode);
        $destination = strtoupper($destination);

        if (!isset(self::PLAN_RATES[$planCode])) {
            throw new InvalidArgumentException('Unsupported plan code.');
        }

        if (!in_array($destination, ['DOMESTIC', 'INTERNATIONAL'], true)) {
            throw new InvalidArgumentException('Unsupported destination.');
        }

        if (!isset(self::COVERAGE_MULTIPLIERS[$sumInsured])) {
            throw new InvalidArgumentException('Unsupported sum insured.');
        }

        $birthDate = Carbon::parse($dateOfBirth)->startOfDay();
        $startDate = Carbon::parse($tripStart)->startOfDay();
        $endDate = Carbon::parse($tripEnd)->startOfDay();

        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException(
                'Trip end must be on or after trip start.'
            );
        }

        if ($birthDate->gt($startDate)) {
            throw new InvalidArgumentException(
                'Date of birth cannot be after trip start.'
            );
        }

        // Age is calculated on the trip start date, not today's date.
        $age = $birthDate->diffInYears($startDate);

        $ageMultiplier = match (true) {
            $age <= 17 => 110,
            $age <= 60 => 100,
            $age <= 70 => 150,
            default => 200,
        };

        $destinationMultiplier = $destination === 'INTERNATIONAL'
            ? 180
            : 100;

        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sumInsured];

        // Both the start date and end date are covered.
        $tripDays = (int) $startDate->diffInDays($endDate) + 1;

        /*
         * Calculate in integer paise to avoid intermediate floating-point
         * rounding. Each multiplier is expressed in hundredths.
         *
         * Premium in paise =
         * rate * days * age * destination * coverage / 10,000.
         */
        $numerator = self::PLAN_RATES[$planCode]
            * $tripDays
            * $ageMultiplier
            * $destinationMultiplier
            * $coverageMultiplier;

        // Round half-up to the nearest paise.
        $premiumPaise = intdiv($numerator + 5000, 10000);

        $premiumAmount = intdiv($premiumPaise, 100)
            . '.'
            . str_pad((string) ($premiumPaise % 100), 2, '0', STR_PAD_LEFT);

        return [
            'trip_days' => $tripDays,
            'premium_amount' => $premiumAmount,
            'age_multiplier' => number_format($ageMultiplier / 100, 2, '.', ''),
            'destination_multiplier' => number_format(
                $destinationMultiplier / 100,
                2,
                '.',
                ''
            ),
            'coverage_multiplier' => number_format(
                $coverageMultiplier / 100,
                2,
                '.',
                ''
            ),
        ];
    }
}
