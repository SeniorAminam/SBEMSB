<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Services/CarbonEngine.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-25
 * 
 * Purpose: Calculates carbon footprint (CO2e) from consumption readings
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Services;

use SmartBuilding\Database\DB;

class CarbonEngine
{
    public function getFactors(): array
    {
        return [
            'electricity' => (float)$this->getSetting('carbon_factor_electricity', 0.7),
            'gas' => (float)$this->getSetting('carbon_factor_gas', 2.0),
            'water' => (float)$this->getSetting('carbon_factor_water', 0.0003),
        ];
    }

    public function getDailyTargetKg(): float
    {
        return (float)$this->getSetting('carbon_daily_target_kg', 10);
    }
    public function getUnitCarbonBreakdown(int $unitId, string $period = 'today'): array
    {
        $condition = $this->periodCondition($period, 'timestamp');
        $rows = DB::select(
            "SELECT metric_type, SUM(value) as total
             FROM consumption_readings
             WHERE unit_id = ? AND {$condition}
             GROUP BY metric_type",
            [$unitId]
        );

        $consumption = [
            'water' => 0.0,
            'electricity' => 0.0,
            'gas' => 0.0,
        ];

        foreach ($rows as $r) {
            $type = (string)$r['metric_type'];
            if (!array_key_exists($type, $consumption)) {
                continue;
            }
            $consumption[$type] = (float)($r['total'] ?? 0);
        }

        return $this->consumptionToCarbon($consumption);
    }
    public function getBuildingCarbonBreakdown(int $buildingId, string $period = 'today'): array
    {
        $condition = $this->periodCondition($period, 'cr.timestamp');
        $rows = DB::select(
            "SELECT cr.metric_type, SUM(cr.value) as total
             FROM consumption_readings cr
             JOIN units u ON cr.unit_id = u.id
             WHERE u.building_id = ? AND u.is_active = 1 AND {$condition}
             GROUP BY cr.metric_type",
            [$buildingId]
        );

        $consumption = [
            'water' => 0.0,
            'electricity' => 0.0,
            'gas' => 0.0,
        ];

        foreach ($rows as $r) {
            $type = (string)$r['metric_type'];
            if (!array_key_exists($type, $consumption)) {
                continue;
            }
            $consumption[$type] = (float)($r['total'] ?? 0);
        }

        return $this->consumptionToCarbon($consumption);
    }
    public function forecastUnitMonthCarbonKg(int $unitId): float
    {
        $rows = DB::select(
            "SELECT DATE(timestamp) as d, metric_type, SUM(value) as total
             FROM consumption_readings
             WHERE unit_id = ?
               AND YEAR(timestamp) = YEAR(CURDATE())
               AND MONTH(timestamp) = MONTH(CURDATE())
             GROUP BY DATE(timestamp), metric_type",
            [$unitId]
        );

        if (empty($rows)) {
            return 0.0;
        }

        $daily = [];
        foreach ($rows as $r) {
            $day = (string)$r['d'];
            $type = (string)$r['metric_type'];
            $total = (float)($r['total'] ?? 0);

            if (!isset($daily[$day])) {
                $daily[$day] = ['water' => 0.0, 'electricity' => 0.0, 'gas' => 0.0];
            }
            if (isset($daily[$day][$type])) {
                $daily[$day][$type] = $total;
            }
        }

        $sumKg = 0.0;
        foreach ($daily as $dayConsumption) {
            $carbon = $this->consumptionToCarbon($dayConsumption);
            $sumKg += (float)($carbon['total_kg'] ?? 0);
        }

        $daysSoFar = count($daily);
        if ($daysSoFar === 0) {
            return 0.0;
        }

        $avgPerDay = $sumKg / $daysSoFar;
        $daysInMonth = (int)date('t');

        return (float)round($avgPerDay * $daysInMonth, 2);
    }

    private function consumptionToCarbon(array $consumption): array
    {
        $factors = $this->getFactors();

        $kgWater = ((float)($consumption['water'] ?? 0)) * (float)$factors['water'];
        $kgElectricity = ((float)($consumption['electricity'] ?? 0)) * (float)$factors['electricity'];
        $kgGas = ((float)($consumption['gas'] ?? 0)) * (float)$factors['gas'];

        $total = $kgWater + $kgElectricity + $kgGas;

        return [
            'water_kg' => (float)round($kgWater, 3),
            'electricity_kg' => (float)round($kgElectricity, 3),
            'gas_kg' => (float)round($kgGas, 3),
            'total_kg' => (float)round($total, 3),
        ];
    }

    private function periodCondition(string $period, string $column): string
    {
        return match ($period) {
            'today' => "DATE({$column}) = CURDATE()",
            'week' => "{$column} >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "{$column} >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE({$column}) = CURDATE()",
        };
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
