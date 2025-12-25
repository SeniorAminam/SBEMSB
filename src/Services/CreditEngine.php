<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Services/CreditEngine.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Energy Credit Management System - calculates and manages credits
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Services;

use SmartBuilding\Database\DB;

class CreditEngine
{
    /**
     * Calculate credits for all units for current month
     */
    public function calculateMonthlyCredits(): int
    {
        $units = DB::select("SELECT id FROM units WHERE is_active = 1");
        $count = 0;

        foreach ($units as $unit) {
            $this->calculateUnitCredits((int)$unit['id']);
            $count++;
        }

        return $count;
    }

    /**
     * Calculate credits for specific unit
     */
    public function calculateUnitCredits(int $unitId): void
    {
        $metrics = ['water', 'electricity', 'gas'];

        foreach ($metrics as $metric) {
            $consumption = $this->getMonthlyConsumption($unitId, $metric);
            $limit = $this->getMonthlyLimit($unitId, $metric);

            if ($limit === null) {
                continue; // No limit set
            }

            $creditBalance = $limit - $consumption;

            // Update or insert credit record
            $this->updateCreditBalance($unitId, $metric, $creditBalance);
        }
    }

    /**
     * Get monthly consumption for unit
     */
    private function getMonthlyConsumption(int $unitId, string $metric): float
    {
        $result = DB::select(
            "SELECT SUM(value) as total
             FROM consumption_readings
             WHERE unit_id = ? AND metric_type = ?
               AND YEAR(timestamp) = YEAR(CURDATE())
               AND MONTH(timestamp) = MONTH(CURDATE())",
            [$unitId, $metric]
        );

        return (float)($result[0]['total'] ?? 0);
    }

    /**
     * Get monthly limit for unit
     */
    private function getMonthlyLimit(int $unitId, string $metric): ?float
    {
        $result = DB::select(
            "SELECT monthly_limit
             FROM consumption_limits
             WHERE unit_id = ? AND metric_type = ?
               AND CURDATE() BETWEEN period_start AND period_end
             LIMIT 1",
            [$unitId, $metric]
        );

        return isset($result[0]) ? (float)$result[0]['monthly_limit'] : null;
    }

    /**
     * Update credit balance
     */
    private function updateCreditBalance(int $unitId, string $metric, float $balance): void
    {
        DB::execute(
            "INSERT INTO energy_credits (unit_id, metric_type, balance, last_calculated)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE 
                balance = VALUES(balance),
                last_calculated = VALUES(last_calculated)",
            [$unitId, $metric, $balance]
        );
    }

    /**
     * Get credit balance for unit
     */
    public function getCredits(int $unitId): array
    {
        $credits = DB::select(
            "SELECT metric_type, balance
             FROM energy_credits
             WHERE unit_id = ?",
            [$unitId]
        );

        $result = [
            'water' => 0,
            'electricity' => 0,
            'gas' => 0,
            'total_balance' => 0
        ];

        foreach ($credits as $credit) {
            $result[$credit['metric_type']] = (float)$credit['balance'];
            $result['total_balance'] += (float)$credit['balance'];
        }

        return $result;
    }

    /**
     * Get dynamic price for credit based on demand
     */
    public function getCreditPrice(string $metric): float
    {
        // Get base price from settings
        $basePriceKey = "base_price_{$metric}";
        $result = DB::select(
            "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1",
            [$basePriceKey]
        );

        $basePrice = (float)($result[0]['setting_value'] ?? 2000);

        // Calculate demand level
        $demandLevel = $this->calculateDemandLevel($metric);

        // Get multiplier from settings
        $multiplierResult = DB::select(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'demand_price_multiplier' LIMIT 1"
        );
        $multiplier = (float)($multiplierResult[0]['setting_value'] ?? 0.2);

        // Dynamic pricing
        $price = $basePrice * (1 + ($demandLevel * $multiplier));

        return round($price, 2);
    }

    /**
     * Calculate demand level (0-1 scale)
     */
    private function calculateDemandLevel(string $metric): float
    {
        // Count units with negative balance (buyers)
        $buyers = DB::select(
            "SELECT COUNT(*) as count
             FROM energy_credits
             WHERE metric_type = ? AND balance < 0",
            [$metric]
        );

        // Count units with positive balance (sellers)
        $sellers = DB::select(
            "SELECT COUNT(*) as count
             FROM energy_credits
             WHERE metric_type = ? AND balance > 0",
            [$metric]
        );

        $buyerCount = (int)($buyers[0]['count'] ?? 0);
        $sellerCount = (int)($sellers[0]['count'] ?? 1);

        // Demand level: ratio of buyers to sellers
        $demandRatio = $buyerCount / max($sellerCount, 1);

        // Normalize to 0-1 scale
        return min($demandRatio, 1.0);
    }

    /**
     * Create credit transaction
     */
    public function createTransaction(
        ?int $fromUnitId,
        int $toUnitId,
        string $metric,
        float $amount,
        string $type = 'manual_buy'
    ): int {
        $price = $this->getCreditPrice($metric);
        $totalPrice = $amount * $price;

        DB::execute(
            "INSERT INTO credit_transactions 
             (from_unit_id, to_unit_id, metric_type, amount, price_per_credit, total_price, transaction_type, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')",
            [$fromUnitId, $toUnitId, $metric, $amount, $price, $totalPrice, $type]
        );

        $transactionId = (int)DB::lastInsertId();

        // Update balances
        if ($fromUnitId !== null) {
            $this->adjustBalance($fromUnitId, $metric, -$amount);
        }
        $this->adjustBalance($toUnitId, $metric, $amount);

        return $transactionId;
    }

    /**
     * Adjust credit balance
     */
    private function adjustBalance(int $unitId, string $metric, float $adjustment): void
    {
        DB::execute(
            "INSERT INTO energy_credits (unit_id, metric_type, balance, last_calculated)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                balance = balance + VALUES(balance),
                last_calculated = VALUES(last_calculated),
                updated_at = NOW()",
            [$unitId, $metric, $adjustment]
        );
    }
}
