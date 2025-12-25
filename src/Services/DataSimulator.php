<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Services/DataSimulator.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: IoT Data Simulation Engine for generating realistic consumption data
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Services;

use SmartBuilding\Database\DB;

class DataSimulator
{
    private float $variance;

    public function __construct()
    {
        $this->variance = (float)$this->getSetting('simulation_variance', 15);
    }

    /**
     * Generate consumption data for all active units
     */
    public function generateConsumptionData(): int
    {
        $units = DB::select("SELECT id FROM units WHERE is_active = 1");
        $count = 0;

        foreach ($units as $unit) {
            $this->generateForUnit((int)$unit['id']);
            $count++;
        }

        return $count;
    }

    /**
     * Generate data for specific unit
     */
    private function generateForUnit(int $unitId): void
    {
        $metrics = ['water', 'electricity', 'gas'];
        $currentHour = (int)date('H');

        $state = $this->getTwinState($unitId);
        $occupants = $this->getOccupantsCount($unitId);

        $scenario = (string)($state['scenario'] ?? 'family');
        $season = (string)($state['season'] ?? 'spring');
        $ecoMode = (bool)($state['eco_mode'] ?? false);
        $lightsOn = (bool)($state['lights_on'] ?? true);
        $acMode = (string)($state['ac_mode'] ?? 'off');
        $heatingTemp = (int)($state['heating_temp'] ?? 22);
        $waterHeaterOn = (bool)($state['water_heater_on'] ?? true);
        $costSensitivity = (int)($state['cost_sensitivity'] ?? 50);
        $greenSensitivity = (int)($state['green_sensitivity'] ?? 50);

        $ecoReduction = 0.0;
        if ($ecoMode) {
            $ecoReduction = 0.10 + ($costSensitivity / 800) + ($greenSensitivity / 900);
            $ecoReduction = min(0.35, max(0.10, $ecoReduction));
        }

        $spikeChance = match ($scenario) {
            'party' => 5,
            'empty', 'travel' => 25,
            default => 12,
        };

        foreach ($metrics as $metric) {
            $baseValue = $this->getBaseValue($metric, $currentHour);
            $value = $baseValue;

            $value *= $this->scenarioMultiplier($metric, $scenario);
            $value *= $this->seasonMultiplier($metric, $season);
            $value *= $this->occupantsMultiplier($metric, $occupants);
            $value *= $this->devicesMultiplier($metric, $lightsOn, $acMode, $heatingTemp, $waterHeaterOn, $season);

            if ($ecoMode) {
                $value *= (1 - $ecoReduction);
            }

            $variance = $value * ($this->variance / 100);
            $value = $value + (mt_rand(-100, 100) / 100) * $variance;
            $value = max(0, $value);

            if (mt_rand(1, $spikeChance) === 1) {
                $value *= (1.2 + (mt_rand(0, 60) / 100));
            }

            $this->saveReading($unitId, $metric, $value);
        }
    }

    public function simulateUnitNow(int $unitId): void
    {
        $this->generateForUnit($unitId);
    }

    public function simulateBuildingNow(int $buildingId): int
    {
        $units = DB::select(
            "SELECT id FROM units WHERE building_id = ? AND is_active = 1",
            [$buildingId]
        );
        $count = 0;
        foreach ($units as $u) {
            $this->generateForUnit((int)$u['id']);
            $count++;
        }
        return $count;
    }

    /**
     * Get base consumption value based on metric and time
     */
    private function getBaseValue(string $metric, int $hour): float
    {
        $baseValues = [
            'water' => 5.0,        // 5 liters per 5 minutes
            'electricity' => 2.5,   // 2.5 kWh per 5 minutes
            'gas' => 0.8           // 0.8 m³ per 5 minutes
        ];

        $multiplier = 1.0;

        // Time-based patterns
        if ($hour >= 6 && $hour <= 9) {
            $multiplier = 1.5; // Morning peak
        } elseif ($hour >= 18 && $hour <= 22) {
            $multiplier = 1.8; // Evening peak
        } elseif ($hour >= 0 && $hour <= 5) {
            $multiplier = 0.3; // Night low
        }

        return $baseValues[$metric] * $multiplier;
    }

    private function getTwinState(int $unitId): array
    {
        try {
            $rows = DB::select(
                "SELECT scenario, season, eco_mode, lights_on, ac_mode, heating_temp, water_heater_on, cost_sensitivity, green_sensitivity
                 FROM unit_twin_states
                 WHERE unit_id = ?
                 LIMIT 1",
                [$unitId]
            );

            return $rows[0] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getOccupantsCount(int $unitId): int
    {
        try {
            $rows = DB::select("SELECT occupants_count FROM units WHERE id = ? LIMIT 1", [$unitId]);
            $count = (int)($rows[0]['occupants_count'] ?? 1);
            return max(0, min(10, $count));
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private function scenarioMultiplier(string $metric, string $scenario): float
    {
        $map = match ($scenario) {
            'empty' => ['water' => 0.15, 'electricity' => 0.25, 'gas' => 0.20],
            'travel' => ['water' => 0.10, 'electricity' => 0.20, 'gas' => 0.25],
            'party' => ['water' => 1.80, 'electricity' => 2.20, 'gas' => 1.30],
            'night' => ['water' => 0.40, 'electricity' => 0.55, 'gas' => 1.00],
            default => ['water' => 1.00, 'electricity' => 1.00, 'gas' => 1.00],
        };

        return (float)($map[$metric] ?? 1.0);
    }

    private function seasonMultiplier(string $metric, string $season): float
    {
        $map = match ($season) {
            'winter' => ['water' => 1.00, 'electricity' => 1.05, 'gas' => 1.80],
            'summer' => ['water' => 1.10, 'electricity' => 1.35, 'gas' => 0.70],
            'autumn' => ['water' => 1.00, 'electricity' => 1.00, 'gas' => 1.20],
            default => ['water' => 1.00, 'electricity' => 1.00, 'gas' => 1.00],
        };

        return (float)($map[$metric] ?? 1.0);
    }

    private function occupantsMultiplier(string $metric, int $occupants): float
    {
        $occupants = max(0, $occupants);
        if ($occupants <= 1) {
            return 1.0;
        }

        $perExtra = match ($metric) {
            'water' => 0.22,
            'electricity' => 0.18,
            'gas' => 0.12,
            default => 0.15,
        };

        return 1.0 + (($occupants - 1) * $perExtra);
    }

    private function devicesMultiplier(
        string $metric,
        bool $lightsOn,
        string $acMode,
        int $heatingTemp,
        bool $waterHeaterOn,
        string $season
    ): float {
        $mul = 1.0;

        if ($metric === 'electricity') {
            if ($lightsOn) {
                $mul *= 1.08;
            }

            $acAdd = match ($acMode) {
                'low' => 0.10,
                'medium' => 0.22,
                'high' => 0.40,
                default => 0.0,
            };
            $seasonFactor = match ($season) {
                'summer' => 1.0,
                'winter' => 0.25,
                default => 0.55,
            };

            $mul *= (1.0 + ($acAdd * $seasonFactor));
        }

        if ($metric === 'gas') {
            $heatingTemp = max(16, min(28, $heatingTemp));
            $heatStrength = max(0, $heatingTemp - 18) * 0.06;
            $seasonFactor = match ($season) {
                'winter' => 1.1,
                'autumn' => 0.8,
                'summer' => 0.2,
                default => 0.5,
            };
            $mul *= (1.0 + ($heatStrength * $seasonFactor));

            if ($waterHeaterOn) {
                $mul *= 1.06;
            }
        }

        if ($metric === 'water') {
            if ($waterHeaterOn) {
                $mul *= 1.04;
            }
        }

        return $mul;
    }

    /**
     * Save reading to database
     */
    private function saveReading(int $unitId, string $metric, float $value): void
    {
        DB::execute(
            "INSERT INTO consumption_readings (unit_id, metric_type, value, simulated, timestamp)
             VALUES (?, ?, ?, 1, NOW())",
            [$unitId, $metric, round($value, 3)]
        );
    }

    /**
     * Get system setting
     */
    private function getSetting(string $key, $default = null)
    {
        $result = DB::select(
            "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1",
            [$key]
        );

        return $result[0]['setting_value'] ?? $default;
    }
}
