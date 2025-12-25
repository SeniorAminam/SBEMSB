<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Services/ConsumptionAnalyzer.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Analyzes consumption patterns and generates alerts
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Services;

use SmartBuilding\Database\DB;

class ConsumptionAnalyzer
{
    private float $alertThreshold;

    public function __construct()
    {
        $result = DB::select(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'alert_threshold_percent' LIMIT 1"
        );
        $this->alertThreshold = (float)($result[0]['setting_value'] ?? 20);
    }

    /**
     * Analyze consumption for all units and generate alerts
     */
    public function analyzeAll(): int
    {
        $units = DB::select("SELECT id FROM units WHERE is_active = 1");
        $alertCount = 0;

        foreach ($units as $unit) {
            $alertCount += $this->analyzeUnit((int)$unit['id']);
        }

        return $alertCount;
    }

    /**
     * Analyze single unit
     */
    public function analyzeUnit(int $unitId): int
    {
        $metrics = ['water', 'electricity', 'gas'];
        $alertCount = 0;

        foreach ($metrics as $metric) {
            // Check for over-consumption
            if ($this->checkOverConsumption($unitId, $metric)) {
                $this->createAlert($unitId, 'over_consumption', 'warning', $metric);
                $alertCount++;
            }

            // Check for possible leak
            if ($this->checkPossibleLeak($unitId, $metric)) {
                $this->createAlert($unitId, 'leak_suspected', 'critical', $metric);
                $alertCount++;
            }
        }

        // Check credit balance
        if ($this->checkLowCredit($unitId)) {
            $this->createAlert($unitId, 'low_credit', 'warning', 'general');
            $alertCount++;
        }

        return $alertCount;
    }

    /**
     * Check if consumption exceeds threshold
     */
    private function checkOverConsumption(int $unitId, string $metric): bool
    {
        // Get today's consumption
        $today = DB::select(
            "SELECT SUM(value) as total
             FROM consumption_readings
             WHERE unit_id = ? AND metric_type = ? AND DATE(timestamp) = CURDATE()",
            [$unitId, $metric]
        );

        // Get average of last 7 days
        $avgWeek = DB::select(
            "SELECT AVG(daily_total) as avg_total
             FROM (
                 SELECT DATE(timestamp) as date, SUM(value) as daily_total
                 FROM consumption_readings
                 WHERE unit_id = ? AND metric_type = ?
                   AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND DATE(timestamp) < CURDATE()
                 GROUP BY DATE(timestamp)
             ) as daily_consumption",
            [$unitId, $metric]
        );

        $todayTotal = (float)($today[0]['total'] ?? 0);
        $weekAvg = (float)($avgWeek[0]['avg_total'] ?? 0);

        if ($weekAvg == 0) {
            return false;
        }

        $percentIncrease = (($todayTotal - $weekAvg) / $weekAvg) * 100;

        return $percentIncrease > $this->alertThreshold;
    }

    /**
     * Check for possible leak (continuous increase)
     */
    private function checkPossibleLeak(int $unitId, string $metric): bool
    {
        // Get last 6 hours of readings
        $readings = DB::select(
            "SELECT value, timestamp
             FROM consumption_readings
             WHERE unit_id = ? AND metric_type = ?
               AND timestamp >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
             ORDER BY timestamp DESC
             LIMIT 12",
            [$unitId, $metric]
        );

        if (count($readings) < 6) {
            return false;
        }

        // Check if values are consistently increasing
        $increases = 0;
        for ($i = 0; $i < count($readings) - 1; $i++) {
            if ($readings[$i]['value'] > $readings[$i + 1]['value']) {
                $increases++;
            }
        }

        // If 80% of readings are increasing, possible leak
        return ($increases / (count($readings) - 1)) > 0.8;
    }

    /**
     * Check if unit has low credit balance
     */
    private function checkLowCredit(int $unitId): bool
    {
        $credits = DB::select(
            "SELECT SUM(balance) as total_balance
             FROM energy_credits
             WHERE unit_id = ?",
            [$unitId]
        );

        $totalBalance = (float)($credits[0]['total_balance'] ?? 0);

        return $totalBalance < -50; // Threshold for low credit warning
    }

    /**
     * Create alert
     */
    private function createAlert(int $unitId, string $type, string $severity, string $metric): void
    {
        $messages = [
            'over_consumption' => "مصرف {$this->getMetricName($metric)} شما بیش از حد معمول است",
            'leak_suspected' => "احتمال نشت {$this->getMetricName($metric)} وجود دارد",
            'low_credit' => "موجودی اعتبار شما منفی است"
        ];

        $titles = [
            'over_consumption' => "⚠️ هشدار بیش‌مصرف",
            'leak_suspected' => "🚨 احتمال نشت",
            'low_credit' => "💰 کمبود اعتبار"
        ];

        // Check if similar alert already exists today
        $existing = DB::select(
            "SELECT id FROM alerts
             WHERE unit_id = ? AND alert_type = ? AND DATE(created_at) = CURDATE()
             LIMIT 1",
            [$unitId, $type]
        );

        if (!empty($existing)) {
            return; // Don't create duplicate alert
        }

        DB::execute(
            "INSERT INTO alerts (unit_id, alert_type, severity, title, message)
             VALUES (?, ?, ?, ?, ?)",
            [
                $unitId,
                $type,
                $severity,
                $titles[$type] ?? 'هشدار',
                $messages[$type] ?? 'لطفاً مصرف خود را بررسی کنید'
            ]
        );
    }

    /**
     * Get Persian name for metric
     */
    private function getMetricName(string $metric): string
    {
        return match ($metric) {
            'water' => 'آب',
            'electricity' => 'برق',
            'gas' => 'گاز',
            default => $metric
        };
    }
}
