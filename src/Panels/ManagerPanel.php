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

        // Get unread alerts count
        $alertsCount = DB::select(
            "SELECT COUNT(*) as count
             FROM alerts a
             JOIN units u ON a.unit_id = u.id
             WHERE u.building_id = ? AND a.is_read = 0",
            [$this->buildingId]
        );

        $alertBadge = $alertsCount[0]['count'] > 0 ? " ({$alertsCount[0]['count']})" : "";

        // Persistent keyboard buttons (main navigation)
        $keyboard = Telegram::replyKeyboard([
            [
                Telegram::keyboardButton('🏠 واحدها'),
                Telegram::keyboardButton('📊 مصرف لحظه‌ای')
            ],
            [
                Telegram::keyboardButton('🌍 کربن'),
                Telegram::keyboardButton('⚠️ هشدارها')
            ],
            [
                Telegram::keyboardButton('💰 اعتبارات'),
                Telegram::keyboardButton('🧪 شبیه‌سازی')
            ],
            [
                Telegram::keyboardButton('🏠 منوی اصلی')
            ],
            [
                Telegram::keyboardButton('📚 راهنما'),
                Telegram::keyboardButton('🆔 شناسه من')
            ]
        ]);

        $text = "🏢 <b>پنل مدیریت: {$buildingName}</b>\n\nخوش آمدید\n\n" .
            "از دکمه‌های زیر برای دسترسی به اطلاعات ساختمان استفاده کنید.";

        // Quick action inline buttons (glass buttons)
        $inlineButtons = [
            [Telegram::inlineButton('🏠 واحدها', 'mgr_units')],
            [Telegram::inlineButton('📊 مصرف لحظه‌ای', 'mgr_live_consumption')],
            [Telegram::inlineButton('🌍 کربن', 'mgr_carbon')],
            [Telegram::inlineButton('💰 اعتبارات', 'mgr_credits')],
            [Telegram::inlineButton('🧪 شبیه‌سازی ساختمان', 'mgr_sim_now')],
            [Telegram::inlineButton('🔄 محاسبه مجدد اعتبارات', 'mgr_recalculate_credits')],
        ];

        if ($alertsCount[0]['count'] > 0) {
            array_unshift($inlineButtons, [Telegram::inlineButton("⚠️ هشدارها{$alertBadge}", 'mgr_alerts')]);
        }

        $inlineKeyboard = Telegram::inlineKeyboard($inlineButtons);

        if ($this->contextMessageId !== null) {
            $this->respond($text, $inlineKeyboard);
            return;
        }

        $this->respond($text, $keyboard, true);
        $this->respond("⚡ <b>عملیات سریع:</b>", $inlineKeyboard, true);
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

        $text = "🧪 ✅ <b>شبیه‌سازی ساختمان انجام شد</b>\n\n";
        $text .= "تعداد واحدهای پردازش‌شده: <b>{$unitsProcessed}</b>\n";
        if ($alertsCreated > 0) {
            $text .= "⚠️ هشدارهای جدید: <b>{$alertsCreated}</b>\n";
        }

        $this->respond(
            $text,
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('📊 مصرف لحظه‌ای', 'mgr_live_consumption')],
                [Telegram::inlineButton('🌍 کربن', 'mgr_carbon')],
                [Telegram::inlineButton('⚠️ هشدارها', 'mgr_alerts')],
                [Telegram::inlineButton('🔙 بازگشت', 'mgr_home')]
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

        $text = "{$title}\n\n";
        $text .= "⚡ برق: <b>" . round((float)$buildingCarbon['electricity_kg'], 2) . "</b> kgCO₂e\n";
        $text .= "🔥 گاز: <b>" . round((float)$buildingCarbon['gas_kg'], 2) . "</b> kgCO₂e\n";
        $text .= "💧 آب: <b>" . round((float)$buildingCarbon['water_kg'], 3) . "</b> kgCO₂e\n\n";
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
            $text .= "🏠 <b>Top 5 واحد پرکربن</b>\n";
            foreach ($top as $t) {
                $text .= "• {$t['label']} — <b>" . round((float)$t['kg'], 2) . "</b> kgCO₂e\n";
            }
            $text .= "\n";
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('🔄 بروزرسانی', 'mgr_carbon')],
            [
                Telegram::inlineButton('📅 امروز', 'mgr_carbon'),
                Telegram::inlineButton('📆 هفته', 'mgr_carbon_week')
            ],
            [Telegram::inlineButton('📈 ۳۰ روز اخیر', 'mgr_carbon_month')],
            [Telegram::inlineButton('🔙 بازگشت', 'mgr_home')]
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
            $text = "هیچ واحدی ثبت نشده است.";
            $keyboard = Telegram::inlineKeyboard([[Telegram::inlineButton('🔙 بازگشت', 'mgr_home')]]);
        } else {
            $text = "🏠 <b>لیست واحدها</b>\n\n";

            $buttons = [];
            foreach ($units as $unit) {
                $consumption = Unit::getCurrentConsumption($unit['id'], 'today');

                $text .= "📍 <b>طبقه {$unit['floor_number']} - {$unit['unit_name']}</b>\n";
                $text .= "   مالک: " . ($unit['owner_name'] ?? 'نامشخص') . "\n";
                $text .= "   💧 آب: " . round((float)$consumption['water'], 1) . " لیتر\n";
                $text .= "   ⚡ برق: " . round((float)$consumption['electricity'], 1) . " کیلووات\n";
                $text .= "   🔥 گاز: " . round((float)$consumption['gas'], 1) . " مترمکعب\n\n";

                $buttons[] = [
                    Telegram::inlineButton(
                        "طبقه {$unit['floor_number']} - {$unit['unit_name']}",
                        'mgr_unit_' . $unit['id']
                    )
                ];
            }

            $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'mgr_home')];

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

        $text = "📊 <b>مصرف امروز</b>\n\n";

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
            $text .= "   💧 آب: " . round((float)$data['water'], 1) . "\n";
            $text .= "   ⚡ برق: " . round((float)$data['electricity'], 1) . "\n";
            $text .= "   🔥 گاز: " . round((float)$data['gas'], 1) . "\n\n";
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('🔄 بروزرسانی', 'mgr_live_consumption')],
            [Telegram::inlineButton('🔙 بازگشت', 'mgr_home')]
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
            $text = "✅ هشداری وجود ندارد";
        } else {
            $text = "⚠️ <b>هشدارهای ساختمان</b>\n\n";

            foreach ($alerts as $alert) {
                $icon = match ($alert['severity']) {
                    'critical' => '🚨',
                    'warning' => '⚠️',
                    default => 'ℹ️'
                };

                $status = $alert['is_read'] ? '✓' : '●';

                $text .= "{$status} {$icon} <b>{$alert['title']}</b>\n";
                $text .= "   واحد: طبقه {$alert['floor_number']} - {$alert['unit_name']}\n";
                $text .= "   {$alert['message']}\n";
                $text .= "   زمان: " . date('H:i - Y/m/d', strtotime($alert['created_at'])) . "\n\n";
            }
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('✓ علامت‌گذاری همه', 'mgr_mark_all_read')],
            [Telegram::inlineButton('🔙 بازگشت', 'mgr_home')]
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

        $text = "💳 <b>وضعیت اعتبارات واحدها</b>\n\n";

        $unitData = [];
        foreach ($credits as $credit) {
            $unitKey = "طبقه {$credit['floor_number']} - {$credit['unit_name']}";

            if (!isset($unitData[$unitKey])) {
                $unitData[$unitKey] = [];
            }

            $unitData[$unitKey][$credit['metric_type']] = (float)$credit['balance'];
        }

        foreach ($unitData as $unitName => $balances) {
            $text .= "🏠 {$unitName}\n";

            foreach (['water' => '💧', 'electricity' => '⚡', 'gas' => '🔥'] as $type => $icon) {
                $balance = $balances[$type] ?? 0;
                $status = $balance >= 0 ? '✅' : '⚠️';
                $sign = $balance >= 0 ? '+' : '';

                $text .= "   {$icon} {$status} {$sign}" . round((float)$balance, 1) . "\n";
            }

            $text .= "\n";
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('🔄 محاسبه مجدد', 'mgr_recalculate_credits')],
            [Telegram::inlineButton('🔙 بازگشت', 'mgr_home')]
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
                    [Telegram::inlineButton('🔙 بازگشت', 'mgr_units')]
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

        $text = "🏠 <b>جزئیات واحد</b>\n\n";
        $text .= "طبقه: {$u['floor_number']}\n";
        $text .= "واحد: {$u['unit_name']}\n";
        $text .= "مالک: " . ($u['owner_name'] ?? 'نامشخص') . "\n";
        $text .= "متراژ: {$u['area_m2']} متر\n";
        $text .= "ساکنین: {$u['occupants_count']} نفر\n";
        $text .= "هشدارهای خوانده‌نشده: " . ($unreadAlerts[0]['count'] ?? 0) . "\n\n";

        $text .= "<b>مصرف امروز:</b>\n";
        $text .= "💧 آب: " . round((float)$today['water'], 1) . "\n";
        $text .= "⚡ برق: " . round((float)$today['electricity'], 1) . "\n";
        $text .= "🔥 گاز: " . round((float)$today['gas'], 1) . "\n";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('⚠️ هشدارها', 'mgr_alerts')],
            [Telegram::inlineButton('🔙 بازگشت', 'mgr_units')]
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
