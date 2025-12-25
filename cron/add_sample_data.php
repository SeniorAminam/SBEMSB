<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: cron/add_sample_data.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Add sample data for testing and demonstration
 * Developed by Amin Davodian
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use SmartBuilding\Database\DB;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "🏗️  Adding Sample Data...\n\n";

try {
    // Check if data already exists
    $existingBuildings = DB::select("SELECT COUNT(*) as count FROM buildings");

    if ($existingBuildings[0]['count'] > 0) {
        echo "⚠️  Sample data already exists!\n";
        echo "Buildings: " . $existingBuildings[0]['count'] . "\n\n";

        $units = DB::select("SELECT COUNT(*) as count FROM units");
        echo "Units: " . $units[0]['count'] . "\n";

        $consumers = DB::select("SELECT COUNT(*) as count FROM users WHERE role = 'consumer'");
        echo "Consumers: " . $consumers[0]['count'] . "\n\n";

        echo "Do you want to reset and add fresh data? (This will DELETE ALL existing data)\n";
        echo "Type 'yes' to continue: ";
        $input = trim(fgets(STDIN));

        if (strtolower($input) !== 'yes') {
            echo "\n❌ Cancelled.\n";
            exit(0);
        }

        // Delete existing data
        echo "\n🗑️  Deleting existing data...\n";
        DB::execute("SET FOREIGN_KEY_CHECKS = 0");
        DB::execute("TRUNCATE TABLE consumption_readings");
        DB::execute("TRUNCATE TABLE consumption_limits");
        DB::execute("TRUNCATE TABLE energy_credits");
        DB::execute("TRUNCATE TABLE credit_transactions");
        DB::execute("TRUNCATE TABLE alerts");
        DB::execute("TRUNCATE TABLE monthly_invoices");
        DB::execute("TRUNCATE TABLE units");
        DB::execute("TRUNCATE TABLE buildings");
        DB::execute("DELETE FROM users WHERE role != 'admin'");
        DB::execute("SET FOREIGN_KEY_CHECKS = 1");
        echo "✓ Old data deleted\n\n";
    }

    // 1. Add Buildings
    echo "1️⃣  Adding buildings...\n";

    $buildings = [
        ['name' => 'ساختمان مرکزی', 'address' => 'تهران، خیابان ولیعصر، پلاک 123', 'floors' => 10],
        ['name' => 'برج آزادی', 'address' => 'تهران، میدان آزادی', 'floors' => 15],
        ['name' => 'مجتمع نیاوران', 'address' => 'تهران، نیاوران، خیابان شهید باهنر', 'floors' => 8],
    ];

    $buildingIds = [];
    foreach ($buildings as $building) {
        DB::execute(
            "INSERT INTO buildings (name, address, total_floors, is_active) VALUES (?, ?, ?, 1)",
            [$building['name'], $building['address'], $building['floors']]
        );
        $buildingIds[] = DB::lastInsertId();
        echo "   ✓ {$building['name']}\n";
    }

    echo "\n";

    // 2. Add Units
    echo "2️⃣  Adding units...\n";

    $unitCount = 0;
    $allUnitIds = [];

    foreach ($buildingIds as $idx => $buildingId) {
        $floors = $buildings[$idx]['floors'];

        for ($floor = 1; $floor <= $floors; $floor++) {
            // 2-4 units per floor
            $unitsPerFloor = rand(2, 4);

            for ($unit = 1; $unit <= $unitsPerFloor; $unit++) {
                $unitName = "{$floor}{$unit}";
                $area = rand(60, 150); // 60-150 m²
                $occupants = rand(1, 5);

                DB::execute(
                    "INSERT INTO units (building_id, floor_number, unit_name, area_m2, occupants_count, is_active) 
                     VALUES (?, ?, ?, ?, ?, 1)",
                    [$buildingId, $floor, $unitName, $area, $occupants]
                );

                $allUnitIds[] = DB::lastInsertId();
                $unitCount++;
            }
        }

        echo "   ✓ Building {$buildings[$idx]['name']}: Added units\n";
    }

    echo "   📊 Total units: {$unitCount}\n\n";

    // 3. Add Consumers (one per unit)
    echo "3️⃣  Adding consumer users...\n";

    $persianNames = [
        'محمد',
        'علی',
        'رضا',
        'حسین',
        'احمد',
        'مهدی',
        'حسن',
        'امیر',
        'فاطمه',
        'زهرا',
        'مریم',
        'سارا',
        'نرگس',
        'لیلا',
        'سمیرا',
        'نازنین'
    ];

    $familyNames = [
        'محمدی',
        'احمدی',
        'رضایی',
        'حسینی',
        'کریمی',
        'جعفری',
        'موسوی',
        'صادقی',
        'نوری',
        'عباسی',
        'داودیان'
    ];

    foreach ($allUnitIds as $unitId) {
        $firstName = $persianNames[array_rand($persianNames)];
        $lastName = $familyNames[array_rand($familyNames)];
        $fullName = $firstName . ' ' . $lastName;

        // Generate fake telegram_id (starting from 2000000000)
        $telegramId = 2000000000 + $unitId;

        DB::execute(
            "INSERT INTO users (telegram_id, first_name, role, unit_id, is_active) 
             VALUES (?, ?, 'consumer', ?, 1)",
            [$telegramId, $fullName, $unitId]
        );

        // Set consumption limits
        DB::execute(
            "INSERT INTO consumption_limits (unit_id, metric_type, monthly_limit, price_per_unit, period_start, period_end)
             VALUES 
             (?, 'water', 150, 1500, DATE_FORMAT(NOW(), '%Y-%m-01'), LAST_DAY(NOW())),
             (?, 'electricity', 500, 2500, DATE_FORMAT(NOW(), '%Y-%m-01'), LAST_DAY(NOW())),
             (?, 'gas', 100, 2000, DATE_FORMAT(NOW(), '%Y-%m-01'), LAST_DAY(NOW()))",
            [$unitId, $unitId, $unitId]
        );

        // Initialize energy credits
        DB::execute(
            "INSERT INTO energy_credits (unit_id, metric_type, balance) 
             VALUES 
             (?, 'water', 0),
             (?, 'electricity', 0),
             (?, 'gas', 0)",
            [$unitId, $unitId, $unitId]
        );
    }

    echo "   ✓ Added {$unitCount} consumer users\n";
    echo "   ✓ Set consumption limits for all units\n";
    echo "   ✓ Initialized energy credits\n\n";

    // 4. Generate some historical consumption data
    echo "4️⃣  Generating historical consumption data...\n";

    $daysBack = 7; // Last 7 days
    $readingsPerDay = 12; // Every 2 hours

    for ($day = $daysBack; $day >= 0; $day--) {
        $date = date('Y-m-d', strtotime("-{$day} days"));

        foreach ($allUnitIds as $unitId) {
            for ($reading = 0; $reading < $readingsPerDay; $reading++) {
                $hour = $reading * 2;
                $timestamp = "{$date} " . sprintf("%02d:00:00", $hour);

                // Generate realistic values based on time of day
                $multiplier = 1.0;
                if ($hour >= 6 && $hour <= 9) {
                    $multiplier = 1.5; // Morning peak
                } elseif ($hour >= 18 && $hour <= 22) {
                    $multiplier = 1.8; // Evening peak
                } elseif ($hour >= 0 && $hour <= 5) {
                    $multiplier = 0.3; // Night low
                }

                $waterValue = 5.0 * $multiplier * (rand(80, 120) / 100);
                $electricityValue = 2.5 * $multiplier * (rand(80, 120) / 100);
                $gasValue = 0.8 * $multiplier * (rand(80, 120) / 100);

                DB::execute(
                    "INSERT INTO consumption_readings (unit_id, metric_type, value, simulated, timestamp)
                     VALUES 
                     (?, 'water', ?, 1, ?),
                     (?, 'electricity', ?, 1, ?),
                     (?, 'gas', ?, 1, ?)",
                    [
                        $unitId,
                        round($waterValue, 3),
                        $timestamp,
                        $unitId,
                        round($electricityValue, 3),
                        $timestamp,
                        $unitId,
                        round($gasValue, 3),
                        $timestamp
                    ]
                );
            }
        }

        echo "   ✓ Day -$day: Generated readings\n";
    }

    echo "\n";

    // 5. Summary
    echo "================================================\n";
    echo "✅ Sample Data Added Successfully!\n";
    echo "================================================\n\n";

    $stats = [
        'buildings' => DB::select("SELECT COUNT(*) as c FROM buildings")[0]['c'],
        'units' => DB::select("SELECT COUNT(*) as c FROM units")[0]['c'],
        'consumers' => DB::select("SELECT COUNT(*) as c FROM users WHERE role = 'consumer'")[0]['c'],
        'readings' => DB::select("SELECT COUNT(*) as c FROM consumption_readings")[0]['c'],
    ];

    echo "📊 Database Statistics:\n";
    echo "   🏢 Buildings: {$stats['buildings']}\n";
    echo "   🏠 Units: {$stats['units']}\n";
    echo "   👥 Consumers: {$stats['consumers']}\n";
    echo "   📈 Consumption readings: {$stats['readings']}\n\n";

    echo "🎉 You can now:\n";
    echo "   1. Test the bot in Telegram\n";
    echo "   2. Run simulate_data.bat to continue generating data\n";
    echo "   3. View reports and manage the system\n\n";
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
