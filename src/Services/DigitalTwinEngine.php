<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Services/DigitalTwinEngine.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-25
 * 
 * Purpose: Manages digital twin state (scenario/devices/eco/season) for units
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Services;

use SmartBuilding\Database\DB;

class DigitalTwinEngine
{
    public function getState(int $unitId): array
    {
        $default = $this->defaultState($unitId);

        try {
            $rows = DB::select(
                "SELECT * FROM unit_twin_states WHERE unit_id = ? LIMIT 1",
                [$unitId]
            );

            if (empty($rows)) {
                $this->ensureStateExists($unitId);
                return $default;
            }

            $s = $rows[0];

            return [
                'unit_id' => (int)$s['unit_id'],
                'scenario' => (string)$s['scenario'],
                'season' => (string)$s['season'],
                'eco_mode' => (bool)$s['eco_mode'],
                'lights_on' => (bool)$s['lights_on'],
                'ac_mode' => (string)$s['ac_mode'],
                'heating_temp' => (int)$s['heating_temp'],
                'water_heater_on' => (bool)$s['water_heater_on'],
                'cost_sensitivity' => (int)$s['cost_sensitivity'],
                'green_sensitivity' => (int)$s['green_sensitivity'],
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public function setScenario(int $unitId, string $scenario): void
    {
        $allowed = ['empty', 'family', 'party', 'night', 'travel'];
        if (!in_array($scenario, $allowed, true)) {
            return;
        }

        $this->ensureStateExists($unitId);

        try {
            DB::execute(
                "UPDATE unit_twin_states SET scenario = ?, updated_at = NOW() WHERE unit_id = ?",
                [$scenario, $unitId]
            );
        } catch (\Throwable $e) {
        }
    }

    public function setMonthlyBudget(int $unitId, int $budgetToman): void
    {
        $budgetToman = max(0, $budgetToman);

        $this->ensureStateExists($unitId);

        try {
            DB::execute(
                "UPDATE unit_twin_states SET monthly_budget_toman = ?, updated_at = NOW() WHERE unit_id = ?",
                [$budgetToman, $unitId]
            );
        } catch (\Throwable $e) {
        }
    }

    public function setSeason(int $unitId, string $season): void
    {
        $allowed = ['spring', 'summer', 'autumn', 'winter'];
        if (!in_array($season, $allowed, true)) {
            return;
        }

        $this->ensureStateExists($unitId);

        try {
            DB::execute(
                "UPDATE unit_twin_states SET season = ?, updated_at = NOW() WHERE unit_id = ?",
                [$season, $unitId]
            );
        } catch (\Throwable $e) {
        }
    }

    public function toggleEcoMode(int $unitId): void
    {
        $this->ensureStateExists($unitId);

        try {
            DB::execute(
                "UPDATE unit_twin_states SET eco_mode = NOT eco_mode, updated_at = NOW() WHERE unit_id = ?",
                [$unitId]
            );
        } catch (\Throwable $e) {
        }
    }

    public function toggleDevice(int $unitId, string $device): void
    {
        $allowed = ['lights_on', 'water_heater_on'];
        if (!in_array($device, $allowed, true)) {
            return;
        }

        $this->ensureStateExists($unitId);

        try {
            DB::execute(
                "UPDATE unit_twin_states SET {$device} = NOT {$device}, updated_at = NOW() WHERE unit_id = ?",
                [$unitId]
            );
        } catch (\Throwable $e) {
        }
    }

    public function setAcMode(int $unitId, string $mode): void
    {
        $allowed = ['off', 'low', 'medium', 'high'];
        if (!in_array($mode, $allowed, true)) {
            return;
        }

        $this->ensureStateExists($unitId);

        try {
            DB::execute(
                "UPDATE unit_twin_states SET ac_mode = ?, updated_at = NOW() WHERE unit_id = ?",
                [$mode, $unitId]
            );
        } catch (\Throwable $e) {
        }
    }

    public function adjustHeatingTemp(int $unitId, int $delta): void
    {
        $this->ensureStateExists($unitId);

        try {
            $rows = DB::select("SELECT heating_temp FROM unit_twin_states WHERE unit_id = ? LIMIT 1", [$unitId]);
            $current = (int)($rows[0]['heating_temp'] ?? 22);
            $next = $current + $delta;
            $next = max(16, min(28, $next));

            DB::execute(
                "UPDATE unit_twin_states SET heating_temp = ?, updated_at = NOW() WHERE unit_id = ?",
                [$next, $unitId]
            );
        } catch (\Throwable $e) {
        }
    }

    public function setSensitivities(int $unitId, int $cost, int $green): void
    {
        $cost = max(0, min(100, $cost));
        $green = max(0, min(100, $green));

        $this->ensureStateExists($unitId);

        try {
            DB::execute(
                "UPDATE unit_twin_states SET cost_sensitivity = ?, green_sensitivity = ?, updated_at = NOW() WHERE unit_id = ?",
                [$cost, $green, $unitId]
            );
        } catch (\Throwable $e) {
        }
    }

    private function ensureStateExists(int $unitId): void
    {
        try {
            DB::execute(
                "INSERT INTO unit_twin_states (unit_id) VALUES (?) ON DUPLICATE KEY UPDATE unit_id = unit_id",
                [$unitId]
            );
        } catch (\Throwable $e) {
        }
    }

    private function defaultState(int $unitId): array
    {
        return [
            'unit_id' => $unitId,
            'scenario' => 'family',
            'season' => 'spring',
            'eco_mode' => false,
            'lights_on' => true,
            'ac_mode' => 'off',
            'heating_temp' => 22,
            'water_heater_on' => true,
            'cost_sensitivity' => 50,
            'green_sensitivity' => 50,
        ];
    }
}
