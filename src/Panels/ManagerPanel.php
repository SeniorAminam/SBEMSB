<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Panels/ManagerPanel.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Building manager panel for managing units and consumption
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Panels;

use SmartBuilding\Utils\Telegram;
use SmartBuilding\Database\DB;
use SmartBuilding\Models\Unit;
use SmartBuilding\Services\CarbonEngine;
use SmartBuilding\Services\DataSimulator;
use SmartBuilding\Services\ConsumptionAnalyzer;

class ManagerPanel
{
    private Telegram $telegram;
    private int $chatId;
    private int $buildingId;
    private ?int $contextMessageId;

    public function __construct(Telegram $telegram, int $chatId, int $buildingId, ?int $contextMessageId = null)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        $this->buildingId = $buildingId;
        $this->contextMessageId = $contextMessageId;
    }

    private function respond(string $text, ?array $replyMarkup = null, bool $forceSend = false): void
    {
        if (!$forceSend && $this->contextMessageId !== null) {
            $this->telegram->editMessage($this->chatId, $this->contextMessageId, $text, $replyMarkup);
            return;
        }

        $this->telegram->sendMessage($this->chatId, $text, $replyMarkup);
    }

    /**
     * Show main manager menu
     */
    public function showMainMenu(): void
    {
        // Get building info
        $building = DB::select("SELECT * FROM buildings WHERE id = ? LIMIT 1", [$this->buildingId]);

        if (empty($building)) {
            $this->respond("ساختمان یافت نشد!");
            return;
        }

        $buildingName = $building[0]['name'];

        $unitsCount = DB::select(
            "SELECT COUNT(*) as c FROM units WHERE building_id = ? AND is_active = 1",
            [$this->buildingId]
        );
        $unitsTotal = (int)($unitsCount[0]['c'] ?? 0);

        // Get unread alerts count
        $alertsCount = DB::select(
            "SELECT COUNT(*) as count
             FROM alerts a
             JOIN units u ON a.unit_id = u.id
             WHERE u.building_id = ? AND a.is_read = 0",
            [$this->buildingId]
        );

        $alertBadge = $alertsCount[0]['count'] > 0 ? " ({$alertsCount[0]['count']})" : "";
        $unreadAlerts = (int)($alertsCount[0]['count'] ?? 0);

        // Persistent keyboard buttons (main navigation)
        $keyboard = Telegram::replyKeyboard([
            [
                Telegram::keyboardButton('واحدها 🏠'),
                Telegram::keyboardButton('مصرف لحظه‌ای 📊')
            ],
            [
                Telegram::keyboardButton('هشدارها ⚠️'),
                Telegram::keyboardButton('کربن 🌍')
            ],
            [
                Telegram::keyboardButton('اعتبارات 💰'),
                Telegram::keyboardButton('شبیه‌سازی 🧪')
            ],
            [
                Telegram::keyboardButton('منوی اصلی 🏠')
            ],
            [
                Telegram::keyboardButton('راهنما 📚'),
                Telegram::keyboardButton('شناسه من 🆔')
            ]
        ]);

        $text = "🏢 <b>داشبورد مدیریت ساختمان</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "📍 ساختمان: <b>{$buildingName}</b>\n";
        $text .= "🏠 واحدهای فعال: <b>{$unitsTotal}</b>\n";
        $text .= "🔔 هشدارهای خوانده‌نشده: <b>{$unreadAlerts}</b>{$alertBadge}\n";
        $text .= Telegram::hr() . "\n";
        $text .= "یک بخش را انتخاب کنید:";

        // Quick action inline buttons (glass buttons)
        $inlineButtons = [
            [Telegram::inlineButton('واحدها 🏠', 'mgr_units'), Telegram::inlineButton('مصرف امروز 📊', 'mgr_live_consumption')],
            [Telegram::inlineButton('کربن 🌍', 'mgr_carbon'), Telegram::inlineButton('اعتبارات 💳', 'mgr_credits')],
            [Telegram::inlineButton('شبیه‌سازی ساختمان 🧪', 'mgr_sim_now')],
            [Telegram::inlineButton('محاسبه مجدد اعتبارات 🔄', 'mgr_recalculate_credits')],
        ];

        if ($unreadAlerts > 0) {
            array_unshift($inlineButtons, [Telegram::inlineButton("هشدارها{$alertBadge} (اقدام) 🚨", 'mgr_alerts')]);
        }

        $inlineKeyboard = Telegram::inlineKeyboard($inlineButtons);

        if ($this->contextMessageId !== null) {
            $this->respond($text, $inlineKeyboard);
            return;
        }

        $this->respond($text, $keyboard, true);
        $this->respond("⚡ <b>عملیات سریع</b>\n" . Telegram::hr(), $inlineKeyboard, true);
    }

    public function simulateNow(): void
    {
        $sim = new DataSimulator();
        $unitsProcessed = $sim->simulateBuildingNow($this->buildingId);

        $alertsCreated = 0;
        try {
            $analyzer = new ConsumptionAnalyzer();
            $unitIds = DB::select("SELECT id FROM units WHERE building_id = ? AND is_active = 1", [$this->buildingId]);
            foreach ($unitIds as $u) {
                $alertsCreated += $analyzer->analyzeUnit((int)$u['id']);
            }
        } catch (\Throwable $e) {
        }

        $text = "🧪 ✅ <b>شبیه‌سازی ساختمان انجام شد</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "🏠 واحدهای پردازش‌شده: <b>{$unitsProcessed}</b>\n";
        if ($alertsCreated > 0) {
            $text .= "🚨 هشدارهای جدید: <b>{$alertsCreated}</b>\n";
        }

        $this->respond(
            $text,
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('مصرف امروز 📊', 'mgr_live_consumption'), Telegram::inlineButton('کربن 🌍', 'mgr_carbon')],
                [Telegram::inlineButton('هشدارها 🚨', 'mgr_alerts'), Telegram::inlineButton('واحدها 🏠', 'mgr_units')],
                [Telegram::inlineButton('بازگشت 🔙', 'mgr_home')]
            ])
        );
    }

    public function showBuildingCarbon(string $period = 'today'): void
    {
        $engine = new CarbonEngine();
        $buildingCarbon = $engine->getBuildingCarbonBreakdown($this->buildingId, $period);

        $title = match ($period) {
            'week' => '🌍 کربن ساختمان (۷ روز اخیر)',
            'month' => '🌍 کربن ساختمان (۳۰ روز اخیر)',
            default => '🌍 کربن ساختمان امروز'
        };

        $text = "{$title}\n";
        $text .= Telegram::hr() . "\n";
        $text .= "⚡ برق: <b>" . round((float)$buildingCarbon['electricity_kg'], 2) . "</b> kgCO₂e\n";
        $text .= "🔥 گاز: <b>" . round((float)$buildingCarbon['gas_kg'], 2) . "</b> kgCO₂e\n";
        $text .= "💧 آب: <b>" . round((float)$buildingCarbon['water_kg'], 3) . "</b> kgCO₂e\n";
        $text .= Telegram::hr() . "\n";
        $text .= "📌 مجموع ساختمان: <b>" . round((float)$buildingCarbon['total_kg'], 2) . "</b> kgCO₂e\n\n";

        $condition = match ($period) {
            'week' => "cr.timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "cr.timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(cr.timestamp) = CURDATE()"
        };

        $unitRows = DB::select(
            "SELECT u.id, u.floor_number, u.unit_name, cr.metric_type, SUM(cr.value) as total
             FROM consumption_readings cr
             JOIN units u ON cr.unit_id = u.id
             WHERE u.building_id = ? AND u.is_active = 1 AND {$condition}
             GROUP BY u.id, cr.metric_type",
            [$this->buildingId]
        );

        $byUnit = [];
        foreach ($unitRows as $r) {
            $uid = (int)$r['id'];
            if (!isset($byUnit[$uid])) {
                $byUnit[$uid] = [
                    'label' => "طبقه {$r['floor_number']} - {$r['unit_name']}",
                    'water' => 0.0,
                    'electricity' => 0.0,
                    'gas' => 0.0,
                ];
            }
            $type = (string)$r['metric_type'];
            if (isset($byUnit[$uid][$type])) {
                $byUnit[$uid][$type] = (float)($r['total'] ?? 0);
            }
        }

        $factors = $engine->getFactors();
        $unitTotals = [];
        foreach ($byUnit as $uid => $c) {
            $kg = ((float)$c['water'] * (float)$factors['water'])
                + ((float)$c['electricity'] * (float)$factors['electricity'])
                + ((float)$c['gas'] * (float)$factors['gas']);
            $unitTotals[] = ['label' => $c['label'], 'kg' => $kg];
        }

        usort($unitTotals, static fn($a, $b) => ($b['kg'] <=> $a['kg']));
        $top = array_slice($unitTotals, 0, 5);

        if (!empty($top)) {
            $text .= "🏆 <b>۵ واحد پرکربن</b>\n";
            $maxKg = max(array_map(static fn($x) => (float)($x['kg'] ?? 0), $top));
            foreach ($top as $t) {
                $kg = (float)($t['kg'] ?? 0);
                $bar = Telegram::progressBar($maxKg > 0 ? ($kg / $maxKg) : 0.0, 10);
                $text .= "• {$bar} {$t['label']} — <b>" . round($kg, 2) . "</b> kgCO₂e\n";
            }
            $text .= "\n";
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('بروزرسانی 🔄', 'mgr_carbon')],
            [
                Telegram::inlineButton('امروز 📅', 'mgr_carbon'),
                Telegram::inlineButton('هفته 📆', 'mgr_carbon_week')
            ],
            [Telegram::inlineButton('۳۰ روز اخیر 📈', 'mgr_carbon_month')],
            [Telegram::inlineButton('بازگشت 🔙', 'mgr_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show units list with consumption
     */
    public function showUnits(): void
    {
        $units = Unit::getByBuilding($this->buildingId);

        if (empty($units)) {
            $text = "🏠 <b>واحدها</b>\n" . Telegram::hr() . "\n\n";
            $text .= "هیچ واحد فعالی برای این ساختمان ثبت نشده است.";
            $keyboard = Telegram::inlineKeyboard([[Telegram::inlineButton('بازگشت 🔙', 'mgr_home')]]);
        } else {
            $text = "🏠 <b>واحدهای ساختمان</b>\n";
            $text .= Telegram::hr() . "\n\n";

            $buttons = [];
            foreach ($units as $unit) {
                $consumption = Unit::getCurrentConsumption($unit['id'], 'today');

                $text .= "📍 <b>طبقه {$unit['floor_number']} · واحد {$unit['unit_name']}</b>\n";
                $text .= "👤 مالک: " . ($unit['owner_name'] ?? 'نامشخص') . "\n";
                $text .= "💧 آب: <b>" . round((float)$consumption['water'], 1) . "</b> لیتر\n";
                $text .= "⚡ برق: <b>" . round((float)$consumption['electricity'], 1) . "</b> کیلووات\n";
                $text .= "🔥 گاز: <b>" . round((float)$consumption['gas'], 1) . "</b> مترمکعب\n";
                $text .= Telegram::hr() . "\n\n";

                $buttons[] = [
                    Telegram::inlineButton(
                        "🔎 طبقه {$unit['floor_number']} · {$unit['unit_name']}",
                        'mgr_unit_' . $unit['id']
                    )
                ];
            }

            $buttons[] = [Telegram::inlineButton('بازگشت 🔙', 'mgr_home')];

            $keyboard = Telegram::inlineKeyboard($buttons);
        }

        $this->respond($text, $keyboard);
    }

    /**
     * Show live consumption summary
     */
    public function showLiveConsumption(): void
    {
        $consumption = DB::select(
            "SELECT 
                u.floor_number,
                u.unit_name,
                cr.metric_type,
                SUM(cr.value) as total
             FROM consumption_readings cr
             JOIN units u ON cr.unit_id = u.id
             WHERE u.building_id = ? AND DATE(cr.timestamp) = CURDATE()
             GROUP BY u.id, cr.metric_type
             ORDER BY u.floor_number, u.unit_name",
            [$this->buildingId]
        );

        $text = "📊 <b>مصرف امروز (خلاصه)</b>\n";
        $text .= Telegram::hr() . "\n\n";

        $currentUnit = null;
        $unitData = [];

        foreach ($consumption as $row) {
            $unitKey = "طبقه {$row['floor_number']} - {$row['unit_name']}";

            if (!isset($unitData[$unitKey])) {
                $unitData[$unitKey] = [
                    'water' => 0,
                    'electricity' => 0,
                    'gas' => 0
                ];
            }

            $unitData[$unitKey][$row['metric_type']] = (float)$row['total'];
        }

        foreach ($unitData as $unitName => $data) {
            $text .= "🏠 <b>{$unitName}</b>\n";
            $text .= "💧 آب: <b>" . round((float)$data['water'], 1) . "</b>\n";
            $text .= "⚡ برق: <b>" . round((float)$data['electricity'], 1) . "</b>\n";
            $text .= "🔥 گاز: <b>" . round((float)$data['gas'], 1) . "</b>\n";
            $text .= Telegram::hr() . "\n\n";
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('بروزرسانی 🔄', 'mgr_live_consumption')],
            [Telegram::inlineButton('بازگشت 🔙', 'mgr_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show alerts for building
     */
    public function showAlerts(): void
    {
        $alerts = DB::select(
            "SELECT a.*, u.floor_number, u.unit_name
             FROM alerts a
             JOIN units u ON a.unit_id = u.id
             WHERE u.building_id = ?
             ORDER BY a.created_at DESC
             LIMIT 20",
            [$this->buildingId]
        );

        if (empty($alerts)) {
            $text = "✅ <b>هشدارهای ساختمان</b>\n" . Telegram::hr() . "\n\n";
            $text .= "فعلاً هشداری ثبت نشده است.";
        } else {
            $text = "🚨 <b>هشدارهای ساختمان</b>\n" . Telegram::hr() . "\n\n";

            foreach ($alerts as $alert) {
                $icon = match ($alert['severity']) {
                    'critical' => '🚨',
                    'warning' => '⚠️',
                    default => 'ℹ️'
                };

                $status = $alert['is_read'] ? '✅' : '🟠';

                $text .= "{$status} {$icon} <b>{$alert['title']}</b>\n";
                $text .= "🏠 واحد: طبقه {$alert['floor_number']} · {$alert['unit_name']}\n";
                $text .= "📝 {$alert['message']}\n";
                $text .= "⏱ " . date('H:i - Y/m/d', strtotime($alert['created_at'])) . "\n";
                $text .= Telegram::hr() . "\n\n";
            }
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('علامت‌گذاری همه به‌عنوان خوانده‌شده ✅', 'mgr_mark_all_read')],
            [Telegram::inlineButton('بازگشت 🔙', 'mgr_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show credits management
     */
    public function showCreditsManagement(): void
    {
        $credits = DB::select(
            "SELECT 
                u.floor_number,
                u.unit_name,
                ec.metric_type,
                ec.balance
             FROM energy_credits ec
             JOIN units u ON ec.unit_id = u.id
             WHERE u.building_id = ?
             ORDER BY u.floor_number, u.unit_name, ec.metric_type",
            [$this->buildingId]
        );

        $text = "💳 <b>وضعیت اعتبارات واحدها</b>\n";
        $text .= Telegram::hr() . "\n\n";

        $unitData = [];
        foreach ($credits as $credit) {
            $unitKey = "طبقه {$credit['floor_number']} - {$credit['unit_name']}";

            if (!isset($unitData[$unitKey])) {
                $unitData[$unitKey] = [];
            }

            $unitData[$unitKey][$credit['metric_type']] = (float)$credit['balance'];
        }

        foreach ($unitData as $unitName => $balances) {
            $text .= "🏠 <b>{$unitName}</b>\n";

            foreach (['water' => '💧', 'electricity' => '⚡', 'gas' => '🔥'] as $type => $icon) {
                $balance = $balances[$type] ?? 0;
                $status = $balance >= 0 ? '✅' : '⚠️';
                $sign = $balance >= 0 ? '+' : '';

                $text .= "{$icon} {$status} <b>{$sign}" . round((float)$balance, 1) . "</b>\n";
            }

            $text .= Telegram::hr() . "\n\n";
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('محاسبه مجدد 🔄', 'mgr_recalculate_credits')],
            [Telegram::inlineButton('بازگشت 🔙', 'mgr_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function showUnitDetails(int $unitId): void
    {
        $unit = DB::select(
            "SELECT u.*, usr.first_name as owner_name
             FROM units u
             LEFT JOIN users usr ON u.owner_id = usr.id
             WHERE u.id = ? AND u.building_id = ?
             LIMIT 1",
            [$unitId, $this->buildingId]
        );

        if (empty($unit)) {
            $this->respond(
                "واحد یافت نشد.",
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('بازگشت 🔙', 'mgr_units')]
                ])
            );
            return;
        }

        $u = $unit[0];

        $today = Unit::getCurrentConsumption((int)$u['id'], 'today');
        $unreadAlerts = DB::select(
            "SELECT COUNT(*) as count FROM alerts WHERE unit_id = ? AND is_read = 0",
            [(int)$u['id']]
        );

        $unread = (int)($unreadAlerts[0]['count'] ?? 0);

        $text = "🏠 <b>جزئیات واحد</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "📍 طبقه: <b>{$u['floor_number']}</b> · واحد: <b>{$u['unit_name']}</b>\n";
        $text .= "👤 مالک: " . ($u['owner_name'] ?? 'نامشخص') . "\n";
        $text .= "📐 متراژ: <b>{$u['area_m2']}</b> متر\n";
        $text .= "👥 ساکنین: <b>{$u['occupants_count']}</b> نفر\n";
        $text .= "🔔 هشدارهای خوانده‌نشده: <b>{$unread}</b>\n";
        $text .= Telegram::hr() . "\n";

        $text .= "<b>مصرف امروز</b>\n";
        $text .= "💧 آب: <b>" . round((float)$today['water'], 1) . "</b>\n";
        $text .= "⚡ برق: <b>" . round((float)$today['electricity'], 1) . "</b>\n";
        $text .= "🔥 گاز: <b>" . round((float)$today['gas'], 1) . "</b>\n";

        $text .= Telegram::hr() . "\n";
        $text .= "برای مشاهده هشدارهای مرتبط، از دکمه زیر استفاده کنید:";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('هشدارها ⚠️', 'mgr_alerts')],
            [Telegram::inlineButton('بازگشت 🔙', 'mgr_units')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function markAllAlertsRead(): void
    {
        DB::execute(
            "UPDATE alerts a
             JOIN units u ON a.unit_id = u.id
             SET a.is_read = 1, a.read_at = NOW()
             WHERE u.building_id = ? AND a.is_read = 0",
            [$this->buildingId]
        );
    }
}
