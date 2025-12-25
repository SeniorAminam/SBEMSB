<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Services/ForecastEngine.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-25
 * 
 * Purpose: Forecasts monthly cost and budget risk based on consumption
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Services;

use SmartBuilding\Database\DB;

class ForecastEngine
{
    public function getUnitMonthlyForecast(int $unitId): array
    {
        $prices = $this->getUnitPrices($unitId);
        $consumption = $this->getMonthConsumption($unitId);

        $costSoFar = 0.0;
        foreach (['water', 'electricity', 'gas'] as $m) {
            $costSoFar += (float)$consumption[$m] * (float)$prices[$m];
        }

        $dayOfMonth = max(1, (int)date('j'));
        $daysInMonth = max(1, (int)date('t'));
        $forecast = ($costSoFar / $dayOfMonth) * $daysInMonth;

        $budget = $this->getMonthlyBudget($unitId);

        $risk = 'low';
        if ($budget > 0) {
            $ratio = $forecast / $budget;
            if ($ratio >= 1.1) {
                $risk = 'high';
            } elseif ($ratio >= 0.9) {
                $risk = 'medium';
            }
        }

        return [
            'consumption' => $consumption,
            'prices' => $prices,
            'cost_so_far' => (float)round($costSoFar, 2),
            'forecast_month' => (float)round($forecast, 2),
            'budget' => (int)$budget,
            'risk' => $risk,
        ];
    }

    private function getMonthConsumption(int $unitId): array
    {
        $rows = DB::select(
            "SELECT metric_type, SUM(value) as total
             FROM consumption_readings
             WHERE unit_id = ?
               AND YEAR(timestamp) = YEAR(CURDATE())
               AND MONTH(timestamp) = MONTH(CURDATE())
             GROUP BY metric_type",
            [$unitId]
        );

        $result = [
            'water' => 0.0,
            'electricity' => 0.0,
            'gas' => 0.0,
        ];

        foreach ($rows as $r) {
            $type = (string)$r['metric_type'];
            if (!isset($result[$type])) {
                continue;
            }
            $result[$type] = (float)($r['total'] ?? 0);
        }

        return $result;
    }

    private function getUnitPrices(int $unitId): array
    {
        $limitRows = DB::select(
            "SELECT metric_type, price_per_unit
             FROM consumption_limits
             WHERE unit_id = ?
               AND CURDATE() BETWEEN period_start AND period_end",
            [$unitId]
        );

        $base = [
            'water' => (float)$this->getSetting('base_price_water', 1500),
            'electricity' => (float)$this->getSetting('base_price_electricity', 2500),
            'gas' => (float)$this->getSetting('base_price_gas', 2000),
        ];

        foreach ($limitRows as $r) {
            $type = (string)$r['metric_type'];
            $price = (float)($r['price_per_unit'] ?? 0);
            if ($price > 0 && isset($base[$type])) {
                $base[$type] = $price;
            }
        }

        return $base;
    }

    private function getMonthlyBudget(int $unitId): int
    {
        try {
            $rows = DB::select(
                "SELECT monthly_budget_toman FROM unit_twin_states WHERE unit_id = ? LIMIT 1",
                [$unitId]
            );

            $value = (int)($rows[0]['monthly_budget_toman'] ?? 1500000);
            return max(0, $value);
        } catch (\Throwable $e) {
            return 1500000;
        }
    }

    private function getSetting(string $key, $default = null)
    {
        $result = DB::select(
            "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1",
            [$key]
        );

        return $result[0]['setting_value'] ?? $default;
    }
}
