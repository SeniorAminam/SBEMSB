<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: cron/simulate_data.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Cron job for simulating IoT data every 5 minutes
 * Developed by Amin Davodian
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use SmartBuilding\Services\DataSimulator;
use SmartBuilding\Services\ConsumptionAnalyzer;
use SmartBuilding\Services\CreditEngine;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "Starting data simulation and analysis...\n";
echo date('Y-m-d H:i:s') . "\n\n";

try {
    // 1. Generate consumption data
    echo "1. Generating consumption data...\n";
    $simulator = new DataSimulator();
    $unitsProcessed = $simulator->generateConsumptionData();
    echo "   ✓ Generated data for {$unitsProcessed} units\n\n";

    // 2. Analyze consumption (every run)
    echo "2. Analyzing consumption patterns...\n";
    $analyzer = new ConsumptionAnalyzer();
    $alertsCreated = $analyzer->analyzeAll();
    echo "   ✓ Created {$alertsCreated} alerts\n\n";

    // 3. Calculate credits (once per day at midnight)
    $currentHour = (int)date('G');
    if ($currentHour === 0) {
        echo "3. Calculating daily credits...\n";
        $creditEngine = new CreditEngine();
        $creditsCalculated = $creditEngine->calculateMonthlyCredits();
        echo "   ✓ Calculated credits for {$creditsCalculated} units\n\n";
    }

    echo "✅ Simulation completed successfully\n";
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    error_log("Cron Error: " . $e->getMessage());
    exit(1);
}
