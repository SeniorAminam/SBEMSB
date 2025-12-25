<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Models/User.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: User model for database interactions
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Models;

use SmartBuilding\Database\DB;

class User
{
    /**
     * Find user by Telegram ID
     */
    public static function findByTelegramId(int $telegramId): ?array
    {
        $result = DB::select(
            "SELECT * FROM users WHERE telegram_id = ? LIMIT 1",
            [$telegramId]
        );

        return $result[0] ?? null;
    }

    /**
     * Create new user
     */
    public static function create(array $data): int
    {
        DB::execute(
            "INSERT INTO users (telegram_id, username, first_name, role, building_id, unit_id) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $data['telegram_id'],
                $data['username'] ?? null,
                $data['first_name'] ?? null,
                $data['role'] ?? 'consumer',
                $data['building_id'] ?? null,
                $data['unit_id'] ?? null
            ]
        );

        return (int)DB::lastInsertId();
    }

    /**
     * Update user role and assignments
     */
    public static function update(int $userId, array $data): bool
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if (in_array($key, ['role', 'building_id', 'unit_id', 'is_active'])) {
                $fields[] = "{$key} = ?";
                $values[] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $userId;

        return DB::execute(
            "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?",
            $values
        );
    }

    /**
     * Get all users by role
     */
    public static function getByRole(string $role): array
    {
        return DB::select(
            "SELECT * FROM users WHERE role = ? AND is_active = 1",
            [$role]
        );
    }

    /**
     * Get users by building
     */
    public static function getByBuilding(int $buildingId): array
    {
        return DB::select(
            "SELECT u.*, un.unit_name, un.floor_number 
             FROM users u
             LEFT JOIN units un ON u.unit_id = un.id
             WHERE u.building_id = ? AND u.is_active = 1
             ORDER BY un.floor_number, un.unit_name",
            [$buildingId]
        );
    }
}
