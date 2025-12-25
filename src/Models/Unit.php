<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Models/Unit.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Unit/Floor model for managing building units
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Models;

use SmartBuilding\Database\DB;

class Unit
{
    /**
     * Get unit by ID with owner info
     */
    public static function find(int $unitId): ?array
    {
        $result = DB::select(
            "SELECT u.*, usr.telegram_id, usr.first_name as owner_name
             FROM units u
             LEFT JOIN users usr ON u.owner_id = usr.id
             WHERE u.id = ? LIMIT 1",
            [$unitId]
        );

        return $result[0] ?? null;
    }

    /**
     * Get all units in a building
     */
    public static function getByBuilding(int $buildingId): array
    {
        return DB::select(
            "SELECT u.*, usr.first_name as owner_name
             FROM units u
             LEFT JOIN users usr ON u.owner_id = usr.id
             WHERE u.building_id = ? AND u.is_active = 1
             ORDER BY u.floor_number, u.unit_name",
            [$buildingId]
        );
    }

    /**
     * Create new unit
     */
    public static function create(array $data): int
    {
        DB::execute(
            "INSERT INTO units (building_id, floor_number, unit_name, area_m2, occupants_count, owner_id)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $data['building_id'],
                $data['floor_number'],
                $data['unit_name'],
                $data['area_m2'] ?? 0,
                $data['occupants_count'] ?? 1,
                $data['owner_id'] ?? null
            ]
        );

        return (int)DB::lastInsertId();
    }

    /**
     * Get current consumption for a unit
     */
    public static function getCurrentConsumption(int $unitId, string $period = 'today'): array
    {
        $dateCondition = match ($period) {
            'today' => "DATE(timestamp) = CURDATE()",
            'week' => "timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(timestamp) = CURDATE()"
        };

        $result = DB::select(
            "SELECT 
                metric_type,
                SUM(value) as total_consumption,
                AVG(value) as avg_consumption,
                MAX(value) as peak_consumption
             FROM consumption_readings
             WHERE unit_id = ? AND {$dateCondition}
             GROUP BY metric_type",
            [$unitId]
        );

        $consumption = [
            'water' => 0,
            'electricity' => 0,
            'gas' => 0
        ];

        foreach ($result as $row) {
            $consumption[$row['metric_type']] = (float)$row['total_consumption'];
        }

        return $consumption;
    }
}
