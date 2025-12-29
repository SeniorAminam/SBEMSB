<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Panels/AdminPanel.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Admin panel for system-wide management
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Panels;

use SmartBuilding\Utils\Telegram;
use SmartBuilding\Database\DB;
use SmartBuilding\Services\ConsumptionAnalyzer;
use SmartBuilding\Services\CreditEngine;
use SmartBuilding\Services\CarbonEngine;
use SmartBuilding\Services\DataSimulator;
use SmartBuilding\Utils\Logger;

class AdminPanel
{
    private Telegram $telegram;
    private int $chatId;
    private ?int $contextMessageId;

    public function __construct(Telegram $telegram, int $chatId, ?int $contextMessageId = null)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
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
     * Show main admin menu
     */
    public function showMainMenu(): void
    {
        // Persistent keyboard buttons (main navigation)
        $keyboard = Telegram::replyKeyboard([
            [
                Telegram::keyboardButton('گزارش 📈'),
                Telegram::keyboardButton('هشدارها ⚠️')
            ],
            [
                Telegram::keyboardButton('کربن 🌍'),
                Telegram::keyboardButton('کاربران 👥')
            ],
            [
                Telegram::keyboardButton('ساختمان‌ها 🏢'),
                Telegram::keyboardButton('قیمت‌ها 💲')
            ],
            [
                Telegram::keyboardButton('تنظیمات ⚙️'),
                Telegram::keyboardButton('ابزارها 🧪')
            ],
            [
                Telegram::keyboardButton('راهنما 📚'),
                Telegram::keyboardButton('شناسه من 🆔')
            ],
            [
                Telegram::keyboardButton('منوی اصلی 🏠')
            ]
        ]);

        $counts = $this->getDbCounts();

        $text = "🛡️ <b>داشبورد مدیریت سیستم</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "ساختمان‌ها: <b>{$counts['buildings']}</b> 🏢\n";
        $text .= "واحدها: <b>{$counts['units']}</b> 🏠\n";
        $text .= "کاربران: <b>{$counts['users']}</b> 👥\n";
        $text .= "هشدارها: <b>{$counts['alerts']}</b> ⚠️\n";
        $text .= Telegram::hr() . "\n";
        $text .= "یک بخش را انتخاب کنید:";

        // Quick action inline buttons (glass buttons)
        $inlineKeyboard = Telegram::inlineKeyboard([
            [
                Telegram::inlineButton('ساختمان‌ها 🏢', 'admin_buildings'),
                Telegram::inlineButton('کاربران 👥', 'admin_users')
            ],
            [
                Telegram::inlineButton('گزارش سیستم 📈', 'admin_report'),
                Telegram::inlineButton('وضعیت دیتابیس 📊', 'admin_tools_db_status')
            ],
            [
                Telegram::inlineButton('هشدارها ⚠️', 'admin_alerts'),
                Telegram::inlineButton('کربن 🌍', 'admin_carbon')
            ],
            [Telegram::inlineButton('بروزرسانی اعتبارات 🔄', 'admin_refresh_credits')],
            [Telegram::inlineButton('ابزارهای ادمین 🧪', 'admin_tools')]
        ]);

        if ($this->contextMessageId !== null) {
            $this->respond($text, $inlineKeyboard);
            return;
        }

        $this->respond($text, $keyboard, true);
        $this->respond("⚡ <b>عملیات سریع</b>\n" . Telegram::hr(), $inlineKeyboard, true);
    }

    /**
     * Show buildings list
     */
    public function showBuildings(): void
    {
        $buildings = DB::select(
            "SELECT b.*, u.first_name as manager_name
             FROM buildings b
             LEFT JOIN users u ON b.manager_id = u.id
             WHERE b.is_active = 1"
        );

        if (empty($buildings)) {
            $text = "🏢 <b>ساختمان‌ها</b>\n" . Telegram::hr() . "\n\n";
            $text .= "هنوز ساختمانی ثبت نشده است.";
            $keyboard = Telegram::inlineKeyboard([
                [Telegram::inlineButton('➕ افزودن ساختمان', 'admin_add_building')],
                [Telegram::inlineButton('🔙 بازگشت', 'admin_home')]
            ]);
        } else {
            $text = "🏢 <b>لیست ساختمان‌ها</b>\n";
            $text .= Telegram::hr() . "\n\n";

            $buttons = [];
            $row = [];
            foreach ($buildings as $building) {
                $mgr = $building['manager_name'] ?? 'نامشخص';
                $text .= "🏢 <b>{$building['name']}</b>\n";
                $text .= "👤 مدیر: {$mgr}\n";
                $text .= "🏗 طبقات: <b>{$building['total_floors']}</b>\n";
                $text .= Telegram::hr() . "\n\n";

                $row[] = Telegram::inlineButton('🏢 ' . $building['name'], 'admin_building_' . $building['id']);
                if (count($row) === 2) {
                    $buttons[] = $row;
                    $row = [];
                }
            }

            if (!empty($row)) {
                $buttons[] = $row;
            }

            $buttons[] = [Telegram::inlineButton('➕ افزودن ساختمان', 'admin_add_building')];
            $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_home')];

            $keyboard = Telegram::inlineKeyboard($buttons);
        }

        $this->respond($text, $keyboard);
    }

    public function showAlerts(): void
    {
        $unread = DB::select("SELECT COUNT(*) as c FROM alerts WHERE is_read = 0");
        $unreadCount = (int)($unread[0]['c'] ?? 0);

        $rows = DB::select(
            "SELECT a.*, u.floor_number, u.unit_name, b.name as building_name
             FROM alerts a
             JOIN units u ON a.unit_id = u.id
             JOIN buildings b ON u.building_id = b.id
             ORDER BY a.created_at DESC
             LIMIT 30"
        );

        $text = "⚠️ <b>هشدارهای سیستم</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "خوانده‌نشده: <b>{$unreadCount}</b>\n";
        $text .= Telegram::hr() . "\n\n";

        if (empty($rows)) {
            $text .= "فعلاً هشداری ثبت نشده است.";
        } else {
            foreach ($rows as $a) {
                $icon = match ($a['severity']) {
                    'critical' => '🚨',
                    'warning' => '⚠️',
                    default => 'ℹ️'
                };
                $status = ((int)($a['is_read'] ?? 0) === 1) ? '✅' : '🟠';
                $buildingName = (string)($a['building_name'] ?? '-');
                $unitLabel = 'طبقه ' . ($a['floor_number'] ?? '-') . ' · واحد ' . ($a['unit_name'] ?? '-');
                $createdAt = (string)($a['created_at'] ?? '');

                $text .= "{$status} {$icon} <b>{$a['title']}</b>\n";
                $text .= "🏢 {$buildingName} / {$unitLabel}\n";
                $text .= "📝 {$a['message']}\n";
                if ($createdAt !== '') {
                    $text .= "⏱ " . date('H:i - Y/m/d', strtotime($createdAt)) . "\n";
                }
                $text .= Telegram::hr() . "\n\n";
            }
        }

        $buttons = [];
        if ($unreadCount > 0) {
            $buttons[] = [Telegram::inlineButton('علامت‌گذاری همه خوانده‌شده ✅', 'admin_mark_all_alerts_read')];
        }
        $buttons[] = [Telegram::inlineButton('بروزرسانی 🔄', 'admin_alerts')];
        $buttons[] = [Telegram::inlineButton('بازگشت 🔙', 'admin_home')];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }

    public function markAllAlertsRead(): void
    {
        DB::execute("UPDATE alerts SET is_read = 1, read_at = NOW() WHERE is_read = 0");
    }

    public function showSystemCarbon(string $period = 'today'): void
    {
        $engine = new CarbonEngine();
        $carbon = $this->getSystemCarbonBreakdown($period, $engine);

        $title = match ($period) {
            'week' => 'کربن سیستم (۷ روز اخیر) 🌍',
            'month' => 'کربن سیستم (۳۰ روز اخیر) 🌍',
            default => 'کربن سیستم امروز 🌍'
        };

        $text = "<b>{$title}</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "برق: <b>" . round((float)$carbon['electricity_kg'], 2) . "</b> kgCO₂e ⚡\n";
        $text .= "گاز: <b>" . round((float)$carbon['gas_kg'], 2) . "</b> kgCO₂e 🔥\n";
        $text .= "آب: <b>" . round((float)$carbon['water_kg'], 3) . "</b> kgCO₂e 💧\n";
        $text .= Telegram::hr() . "\n";
        $text .= "مجموع: <b>" . round((float)$carbon['total_kg'], 2) . "</b> kgCO₂e\n";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('بروزرسانی 🔄', 'admin_carbon')],
            [
                Telegram::inlineButton('امروز 📅', 'admin_carbon'),
                Telegram::inlineButton('هفته 📆', 'admin_carbon_week')
            ],
            [Telegram::inlineButton('۳۰ روز اخیر 📈', 'admin_carbon_month')],
            [Telegram::inlineButton('بازگشت 🔙', 'admin_home')],
        ]);

        $this->respond($text, $keyboard);
    }

    public function showResetAllConfirm(): void
    {
        $text = "⚠️ <b>تایید عملیات</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "این عملیات همه داده‌های سیستم را پاک می‌کند (به‌جز کاربران ادمین).";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('تایید می‌کنم ✅', 'admin_tools_reset_all_run')],
            [Telegram::inlineButton('لغو ❌', 'admin_tools')],
        ]);

        $this->respond($text, $keyboard);
    }

    public function resetAllData(): void
    {
        try {
            DB::beginTransaction();

            DB::execute("SET FOREIGN_KEY_CHECKS = 0");
            DB::execute("TRUNCATE TABLE consumption_readings");
            DB::execute("TRUNCATE TABLE consumption_limits");
            DB::execute("TRUNCATE TABLE unit_twin_states");
            DB::execute("TRUNCATE TABLE energy_credits");
            DB::execute("TRUNCATE TABLE credit_transactions");
            DB::execute("TRUNCATE TABLE alerts");
            DB::execute("TRUNCATE TABLE monthly_invoices");
            DB::execute("TRUNCATE TABLE units");
            DB::execute("TRUNCATE TABLE buildings");
            DB::execute("DELETE FROM users WHERE role != 'admin'");
            DB::execute("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'runtime_last_update_id'");
            DB::execute("SET FOREIGN_KEY_CHECKS = 1");

            DB::commit();

            $this->respond(
                "✅ <b>ریست کامل انجام شد</b>\n" . Telegram::hr() . "\n" .
                "اکنون سیستم بدون داده است.",
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('وضعیت دیتابیس 📊', 'admin_tools_db_status')],
                    [Telegram::inlineButton('بازگشت 🔙', 'admin_home')],
                ])
            );
        } catch (\Throwable $e) {
            DB::rollback();
            try {
                DB::execute("SET FOREIGN_KEY_CHECKS = 1");
            } catch (\Throwable $e2) {
            }

            Logger::error('admin_reset_all_failed', $e->getMessage(), ['chat_id' => $this->chatId]);
            $this->respond(
                "❌ خطا در ریست کامل: " . $e->getMessage(),
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('بازگشت 🔙', 'admin_tools')],
                ])
            );
        }
    }

    private function getSystemCarbonBreakdown(string $period, CarbonEngine $engine): array
    {
        $condition = match ($period) {
            'week' => "timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(timestamp) = CURDATE()",
        };

        $rows = DB::select(
            "SELECT metric_type, SUM(value) as total
             FROM consumption_readings
             WHERE {$condition}
             GROUP BY metric_type"
        );

        $consumption = ['water' => 0.0, 'electricity' => 0.0, 'gas' => 0.0];
        foreach ($rows as $r) {
            $type = (string)($r['metric_type'] ?? '');
            if (isset($consumption[$type])) {
                $consumption[$type] = (float)($r['total'] ?? 0);
            }
        }

        $factors = $engine->getFactors();
        $kgWater = $consumption['water'] * (float)$factors['water'];
        $kgElectricity = $consumption['electricity'] * (float)$factors['electricity'];
        $kgGas = $consumption['gas'] * (float)$factors['gas'];
        $total = $kgWater + $kgElectricity + $kgGas;

        return [
            'water_kg' => (float)round($kgWater, 3),
            'electricity_kg' => (float)round($kgElectricity, 3),
            'gas_kg' => (float)round($kgGas, 3),
            'total_kg' => (float)round($total, 3),
        ];
    }

    public function showToolsMenu(): void
    {
        $text = "🧪 <b>ابزارهای ادمین</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "این بخش برای دمو/تست سریع سیستم است.";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('ساخت دیتای نمونه 🏗️', 'admin_tools_seed')],
            [Telegram::inlineButton('پریست‌های شبیه‌سازی 🎛', 'admin_tools_presets')],
            [Telegram::inlineButton('شبیه‌سازی (کل سیستم) 🧪', 'admin_tools_simulate')],
            [Telegram::inlineButton('وضعیت دیتابیس 📊', 'admin_tools_db_status')],
            [Telegram::inlineButton('پاداش کم‌مصرف‌ها 🎁', 'admin_tools_reward_low')],
            [Telegram::inlineButton('ریست همه داده‌ها ♻️', 'admin_tools_reset_all_confirm')],
            [Telegram::inlineButton('مدیریت وبهوک 🌐', 'admin_webhook_menu')],
            [Telegram::inlineButton('بازگشت 🔙', 'admin_home')],
        ]);

        $this->respond($text, $keyboard);
    }

    public function showWebhookMenu(): void
    {
        $text = "🌐 <b>مدیریت وبهوک</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "برای اجرای سریع روی XAMPP/لوکال، معمولاً Polling پیشنهاد می‌شود.\n";
        $text .= "اگر وبهوک می‌خواهید، ابتدا URL و Secret را در .env تنظیم کنید.";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('ℹ️ وضعیت وبهوک', 'admin_webhook_info')],
            [Telegram::inlineButton('✅ تنظیم وبهوک از .env', 'admin_webhook_set')],
            [Telegram::inlineButton('🗑 حذف وبهوک (خاموش)', 'admin_webhook_delete')],
            [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
        ]);

        $this->respond($text, $keyboard);
    }

    public function webhookInfo(): void
    {
        $result = $this->telegram->getWebhookInfo();

        $info = is_array($result['result'] ?? null) ? $result['result'] : [];
        $url = (string)($info['url'] ?? '');
        $pending = (int)($info['pending_update_count'] ?? 0);
        $lastErr = (string)($info['last_error_message'] ?? '');

        $text = "ℹ️ <b>وضعیت وبهوک</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "URL: <code>" . ($url !== '' ? $url : '-') . "</code>\n";
        $text .= "Pending Updates: <b>{$pending}</b>\n";
        if ($lastErr !== '') {
            $text .= Telegram::hr() . "\n";
            $text .= "آخرین خطا: {$lastErr}\n";
        }

        $this->respond(
            $text,
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('🔄 بروزرسانی', 'admin_webhook_info')],
                [Telegram::inlineButton('🔙 بازگشت', 'admin_webhook_menu')],
            ])
        );
    }

    public function webhookSetFromEnv(): void
    {
        $url = (string)($_ENV['TELEGRAM_WEBHOOK_URL'] ?? '');
        $secret = (string)($_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '');

        if ($url === '') {
            $text = "❌ <b>وبهوک تنظیم نشد</b>\n";
            $text .= Telegram::hr() . "\n";
            $text .= "متغیر <code>TELEGRAM_WEBHOOK_URL</code> در .env خالی است.";
            $this->respond($text, Telegram::inlineKeyboard([[Telegram::inlineButton('🔙 بازگشت', 'admin_webhook_menu')]]));
            return;
        }

        $result = $this->telegram->setWebhook($url, $secret);

        $ok = (bool)($result['ok'] ?? false);
        $desc = (string)($result['description'] ?? '');

        $text = ($ok ? "✅ <b>وبهوک تنظیم شد</b>\n" : "❌ <b>وبهوک تنظیم نشد</b>\n");
        $text .= Telegram::hr() . "\n";
        $text .= "URL: <code>{$url}</code>\n";
        if ($desc !== '') {
            $text .= "نتیجه: {$desc}\n";
        }

        $this->respond(
            $text,
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('ℹ️ وضعیت وبهوک', 'admin_webhook_info')],
                [Telegram::inlineButton('🔙 بازگشت', 'admin_webhook_menu')],
            ])
        );
    }

    public function webhookDelete(): void
    {
        $result = $this->telegram->deleteWebhook(true);

        $ok = (bool)($result['ok'] ?? false);
        $desc = (string)($result['description'] ?? '');

        $text = ($ok ? "🗑 ✅ <b>وبهوک حذف شد</b>\n" : "❌ <b>حذف وبهوک انجام نشد</b>\n");
        $text .= Telegram::hr() . "\n";
        if ($desc !== '') {
            $text .= "نتیجه: {$desc}\n";
        }

        $this->respond(
            $text,
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('ℹ️ وضعیت وبهوک', 'admin_webhook_info')],
                [Telegram::inlineButton('🔙 بازگشت', 'admin_webhook_menu')],
            ])
        );
    }

    public function showSimulationPresetsMenu(): void
    {
        $text = "🎛 <b>پریست‌های شبیه‌سازی</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "هر پریست: (۱) تغییر وضعیت دیجیتال‌توئین، (۲) اجرای یک مرحله شبیه‌سازی.";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('👥 مهمان (افزایش نفرات)', 'admin_tools_preset_guest')],
            [Telegram::inlineButton('🔥 پرمصرف (Party/Winter)', 'admin_tools_preset_high')],
            [Telegram::inlineButton('🌿 کم‌مصرف (Empty/Eco)', 'admin_tools_preset_low')],
            [Telegram::inlineButton('♻️ بازگشت به حالت نرمال', 'admin_tools_preset_reset')],
            [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
        ]);

        $this->respond($text, $keyboard);
    }

    public function simulatePresetGuest(): void
    {
        $this->simulateSystemWithPreset('guest');
    }

    public function simulatePresetHigh(): void
    {
        $this->simulateSystemWithPreset('high');
    }

    public function simulatePresetLow(): void
    {
        $this->simulateSystemWithPreset('low');
    }

    public function resetSimulationPreset(): void
    {
        try {
            $unitsAffected = $this->applySimulationPreset('reset');

            $text = "♻️ ✅ <b>بازگشت به حالت نرمال انجام شد</b>\n\n";
            $text .= "🏠 تعداد واحدهای اعمال‌شده: <b>{$unitsAffected}</b>\n";

            $this->respond(
                $text,
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🧪 شبیه‌سازی (کل سیستم)', 'admin_tools_simulate')],
                    [Telegram::inlineButton('🎛 پریست‌ها', 'admin_tools_presets')],
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
                ])
            );
        } catch (\Throwable $e) {
            Logger::error('admin_preset_reset_failed', $e->getMessage(), ['chat_id' => $this->chatId]);
            $this->respond(
                "❌ خطا در بازگشت به حالت نرمال: " . $e->getMessage(),
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools_presets')],
                ])
            );
        }
    }

    private function simulateSystemWithPreset(string $preset): void
    {
        try {
            $unitsAffected = $this->applySimulationPreset($preset);

            $simulator = new DataSimulator();
            $unitsProcessed = $simulator->generateConsumptionData();

            $analyzer = new ConsumptionAnalyzer();
            $alertsCreated = $analyzer->analyzeAll();

            $creditEngine = new CreditEngine();
            $creditsCalculated = $creditEngine->calculateMonthlyCredits();

            $presetLabel = match ($preset) {
                'guest' => 'مهمان (افزایش نفرات)',
                'high' => 'پرمصرف',
                'low' => 'کم‌مصرف',
                default => $preset,
            };

            $text = "🎛 ✅ <b>شبیه‌سازی با پریست انجام شد</b>\n\n";
            $text .= "🧩 پریست: <b>{$presetLabel}</b>\n";
            $text .= "🏠 واحدهای اعمال‌شده: <b>{$unitsAffected}</b>\n\n";
            $text .= "🏠 واحدهای پردازش‌شده: <b>{$unitsProcessed}</b>\n";
            $text .= "⚠️ هشدارهای جدید: <b>{$alertsCreated}</b>\n";
            $text .= "💰 محاسبه اعتبارات: <b>{$creditsCalculated}</b> واحد";

            $this->respond(
                $text,
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔄 اجرای مجدد همین پریست', 'admin_tools_preset_' . $preset)],
                    [Telegram::inlineButton('🎛 پریست‌ها', 'admin_tools_presets')],
                    [Telegram::inlineButton('📊 وضعیت دیتابیس', 'admin_tools_db_status')],
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
                ])
            );
        } catch (\Throwable $e) {
            Logger::error('admin_simulate_preset_failed', $e->getMessage(), ['chat_id' => $this->chatId, 'preset' => $preset]);
            $this->respond(
                "❌ خطا در شبیه‌سازی با پریست: " . $e->getMessage(),
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools_presets')],
                ])
            );
        }
    }

    private function applySimulationPreset(string $preset): int
    {
        $units = DB::select("SELECT id FROM units WHERE is_active = 1");
        $unitCount = count($units);

        if ($unitCount === 0) {
            throw new \RuntimeException('هیچ واحد فعالی در سیستم وجود ندارد.');
        }

        DB::execute(
            "INSERT INTO unit_twin_states (unit_id)
             SELECT id FROM units WHERE is_active = 1
             ON DUPLICATE KEY UPDATE unit_id = unit_id"
        );

        $twinWhere = "unit_id IN (SELECT id FROM units WHERE is_active = 1)";

        match ($preset) {
            'guest' => $this->applyPresetGuest($twinWhere),
            'high' => $this->applyPresetHigh($twinWhere),
            'low' => $this->applyPresetLow($twinWhere),
            'reset' => $this->applyPresetReset($twinWhere),
            default => throw new \RuntimeException('پریست نامعتبر است.'),
        };

        Logger::info('admin_simulation_preset_applied', 'Preset applied', [
            'chat_id' => $this->chatId,
            'preset' => $preset,
            'units' => $unitCount,
        ]);

        return $unitCount;
    }

    private function applyPresetGuest(string $twinWhere): void
    {
        DB::execute(
            "UPDATE units
             SET occupants_count = LEAST(occupants_count + 2, 10)
             WHERE is_active = 1"
        );

        DB::execute(
            "UPDATE unit_twin_states
             SET scenario = 'family', eco_mode = 0, lights_on = 1, water_heater_on = 1, updated_at = NOW()
             WHERE {$twinWhere}"
        );
    }

    private function applyPresetHigh(string $twinWhere): void
    {
        DB::execute(
            "UPDATE units
             SET occupants_count = GREATEST(occupants_count, 7)
             WHERE is_active = 1"
        );

        DB::execute(
            "UPDATE unit_twin_states
             SET scenario = 'party', season = 'winter', eco_mode = 0, lights_on = 1,
                 ac_mode = 'high', heating_temp = 26, water_heater_on = 1,
                 cost_sensitivity = 20, green_sensitivity = 20,
                 updated_at = NOW()
             WHERE {$twinWhere}"
        );
    }

    private function applyPresetLow(string $twinWhere): void
    {
        DB::execute(
            "UPDATE units
             SET occupants_count = 1
             WHERE is_active = 1"
        );

        DB::execute(
            "UPDATE unit_twin_states
             SET scenario = 'empty', season = 'spring', eco_mode = 1, lights_on = 0,
                 ac_mode = 'off', heating_temp = 18, water_heater_on = 0,
                 cost_sensitivity = 90, green_sensitivity = 90,
                 updated_at = NOW()
             WHERE {$twinWhere}"
        );
    }

    private function applyPresetReset(string $twinWhere): void
    {
        DB::execute(
            "UPDATE units
             SET occupants_count = LEAST(GREATEST(occupants_count, 1), 5)
             WHERE is_active = 1"
        );

        DB::execute(
            "UPDATE unit_twin_states
             SET scenario = 'family', season = 'spring', eco_mode = 0, lights_on = 1,
                 ac_mode = 'off', heating_temp = 22, water_heater_on = 1,
                 cost_sensitivity = 50, green_sensitivity = 50,
                 updated_at = NOW()
             WHERE {$twinWhere}"
        );
    }

    public function showSeedMenu(): void
    {
        $counts = $this->getDbCounts();

        $text = "🏗️ <b>ساخت دیتای نمونه</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "📌 آمار فعلی سیستم:\n";
        $text .= "🏢 ساختمان‌ها: <b>{$counts['buildings']}</b>\n";
        $text .= "🏠 واحدها: <b>{$counts['units']}</b>\n";
        $text .= "👥 کاربران: <b>{$counts['users']}</b>\n";
        $text .= "📈 قرائت‌ها: <b>{$counts['readings']}</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "اگر دیتا دارید و می‌خواهید دمو تمیز باشد، «ریست کامل» بهتر است.";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('➕ ساخت (فقط اگر دیتابیس خالی است)', 'admin_tools_seed_safe')],
            [Telegram::inlineButton('⚠️ ریست کامل و ساخت مجدد', 'admin_tools_seed_reset_confirm')],
            [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
        ]);

        $this->respond($text, $keyboard);
    }

    public function showSeedResetConfirm(): void
    {
        $text = "⚠️ <b>تایید عملیات</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "این عملیات همه داده‌ها (به‌جز ادمین‌ها) را پاک می‌کند و دیتای نمونه جدید می‌سازد.";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('✅ تایید می‌کنم', 'admin_tools_seed_reset_run')],
            [Telegram::inlineButton('❌ لغو', 'admin_tools_seed')],
        ]);

        $this->respond($text, $keyboard);
    }

    public function seedSampleData(bool $reset): void
    {
        try {
            $result = $this->seedSampleDataInternal($reset);

            $text = "✅ <b>دیتای نمونه ساخته شد</b>\n\n";
            $text .= "🏢 ساختمان‌ها: <b>{$result['buildings']}</b>\n";
            $text .= "🏠 واحدها: <b>{$result['units']}</b>\n";
            $text .= "👥 مصرف‌کنندگان: <b>{$result['consumers']}</b>\n";
            $text .= "📈 قرائت‌ها: <b>{$result['readings']}</b>\n";

            $this->respond(
                $text,
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('📊 وضعیت دیتابیس', 'admin_tools_db_status')],
                    [Telegram::inlineButton('🧪 شبیه‌سازی (کل سیستم)', 'admin_tools_simulate')],
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
                ])
            );
        } catch (\Throwable $e) {
            Logger::error('admin_seed_failed', $e->getMessage(), ['chat_id' => $this->chatId]);
            $this->respond(
                "❌ خطا در ساخت دیتای نمونه: " . $e->getMessage(),
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools_seed')],
                ])
            );
        }
    }

    public function simulateSystemNow(): void
    {
        try {
            $simulator = new DataSimulator();
            $unitsProcessed = $simulator->generateConsumptionData();

            $analyzer = new ConsumptionAnalyzer();
            $alertsCreated = $analyzer->analyzeAll();

            $creditEngine = new CreditEngine();
            $creditsCalculated = $creditEngine->calculateMonthlyCredits();

            $text = "🧪 ✅ <b>شبیه‌سازی سیستم انجام شد</b>\n\n";
            $text .= "🏠 واحدهای پردازش‌شده: <b>{$unitsProcessed}</b>\n";
            $text .= "⚠️ هشدارهای جدید: <b>{$alertsCreated}</b>\n";
            $text .= "💰 محاسبه اعتبارات: <b>{$creditsCalculated}</b> واحد";

            $this->respond(
                $text,
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔄 اجرای مجدد', 'admin_tools_simulate')],
                    [Telegram::inlineButton('📈 گزارش', 'admin_report')],
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
                ])
            );
        } catch (\Throwable $e) {
            Logger::error('admin_simulate_failed', $e->getMessage(), ['chat_id' => $this->chatId]);
            $this->respond(
                "❌ خطا در شبیه‌سازی: " . $e->getMessage(),
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
                ])
            );
        }
    }

    public function showDbStatus(): void
    {
        $counts = $this->getDbCounts();

        $text = "📊 <b>وضعیت دیتابیس</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "🏢 ساختمان‌ها: <b>{$counts['buildings']}</b>\n";
        $text .= "🏠 واحدها: <b>{$counts['units']}</b>\n";
        $text .= "👥 کاربران: <b>{$counts['users']}</b>\n";
        $text .= "📈 قرائت‌ها: <b>{$counts['readings']}</b>\n";
        $text .= "⚠️ هشدارها: <b>{$counts['alerts']}</b>\n";
        $text .= "💳 تراکنش‌ها: <b>{$counts['transactions']}</b>\n";

        $this->respond(
            $text,
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('🔄 بروزرسانی', 'admin_tools_db_status')],
                [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
            ])
        );
    }

    public function rewardLowConsumers(): void
    {
        try {
            $creditEngine = new CreditEngine();
            $creditEngine->calculateMonthlyCredits();

            $threshold = 50.0;
            $rewardAmount = 5.0;

            $rows = DB::select(
                "SELECT unit_id, metric_type, balance
                 FROM energy_credits
                 WHERE balance >= ?",
                [$threshold]
            );

            $granted = 0;
            foreach ($rows as $r) {
                $unitId = (int)$r['unit_id'];
                $metric = (string)$r['metric_type'];
                if (!in_array($metric, ['water', 'electricity', 'gas'], true)) {
                    continue;
                }

                $creditEngine->createTransaction(null, $unitId, $metric, $rewardAmount, 'system_purchase');
                $granted++;
            }

            $text = "🎁 ✅ <b>پاداش‌دهی انجام شد</b>\n\n";
            $text .= "معیار: موجودی مثبت >= <b>{$threshold}</b>\n";
            $text .= "پاداش هر مورد: <b>{$rewardAmount}</b>\n\n";
            $text .= "تعداد پاداش‌ها: <b>{$granted}</b>";

            $this->respond(
                $text,
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔄 اجرای مجدد', 'admin_tools_reward_low')],
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
                ])
            );
        } catch (\Throwable $e) {
            Logger::error('admin_reward_failed', $e->getMessage(), ['chat_id' => $this->chatId]);
            $this->respond(
                "❌ خطا در پاداش‌دهی: " . $e->getMessage(),
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_tools')],
                ])
            );
        }
    }

    private function seedSampleDataInternal(bool $reset): array
    {
        $existingBuildings = DB::select("SELECT COUNT(*) as c FROM buildings");
        $hasData = ((int)($existingBuildings[0]['c'] ?? 0)) > 0;

        if ($hasData && !$reset) {
            throw new \RuntimeException('دیتا موجود است. برای ساخت مجدد، گزینه ریست کامل را بزنید.');
        }

        DB::beginTransaction();

        try {
            if ($reset) {
                DB::execute("SET FOREIGN_KEY_CHECKS = 0");
                DB::execute("TRUNCATE TABLE consumption_readings");
                DB::execute("TRUNCATE TABLE consumption_limits");
                DB::execute("TRUNCATE TABLE unit_twin_states");
                DB::execute("TRUNCATE TABLE energy_credits");
                DB::execute("TRUNCATE TABLE credit_transactions");
                DB::execute("TRUNCATE TABLE alerts");
                DB::execute("TRUNCATE TABLE monthly_invoices");
                DB::execute("TRUNCATE TABLE units");
                DB::execute("TRUNCATE TABLE buildings");
                DB::execute("DELETE FROM users WHERE role != 'admin'");
                DB::execute("SET FOREIGN_KEY_CHECKS = 1");
            }

            $buildings = [
                ['name' => 'ساختمان مرکزی', 'address' => 'تهران، خیابان ولیعصر، پلاک 123', 'floors' => 6],
                ['name' => 'برج آزادی', 'address' => 'تهران، میدان آزادی', 'floors' => 8],
            ];

            $buildingIds = [];
            foreach ($buildings as $b) {
                DB::execute(
                    "INSERT INTO buildings (name, address, total_floors, is_active) VALUES (?, ?, ?, 1)",
                    [$b['name'], $b['address'], $b['floors']]
                );
                $buildingIds[] = (int)DB::lastInsertId();
            }

            $allUnitIds = [];
            foreach ($buildingIds as $idx => $buildingId) {
                $floors = (int)$buildings[$idx]['floors'];
                for ($floor = 1; $floor <= $floors; $floor++) {
                    $unitsPerFloor = 2;
                    for ($unit = 1; $unit <= $unitsPerFloor; $unit++) {
                        $unitName = "{$floor}{$unit}";
                        $area = rand(60, 150);
                        $occupants = rand(1, 5);

                        DB::execute(
                            "INSERT INTO units (building_id, floor_number, unit_name, area_m2, occupants_count, is_active)
                             VALUES (?, ?, ?, ?, ?, 1)",
                            [$buildingId, $floor, $unitName, $area, $occupants]
                        );

                        $allUnitIds[] = (int)DB::lastInsertId();
                    }
                }
            }

            $persianNames = ['محمد', 'علی', 'رضا', 'حسین', 'احمد', 'مهدی', 'حسن', 'امیر', 'فاطمه', 'زهرا', 'مریم', 'سارا', 'نرگس', 'لیلا', 'سمیرا', 'نازنین'];
            $familyNames = ['محمدی', 'احمدی', 'رضایی', 'حسینی', 'کریمی', 'جعفری', 'موسوی', 'صادقی', 'نوری', 'عباسی', 'داودیان'];

            foreach ($allUnitIds as $unitId) {
                $firstName = $persianNames[array_rand($persianNames)];
                $lastName = $familyNames[array_rand($familyNames)];
                $fullName = $firstName . ' ' . $lastName;
                $telegramId = 2000000000 + $unitId;

                $buildingRow = DB::select("SELECT building_id FROM units WHERE id = ? LIMIT 1", [$unitId]);
                $buildingId = (int)($buildingRow[0]['building_id'] ?? 0);

                DB::execute(
                    "INSERT INTO users (telegram_id, first_name, role, building_id, unit_id, is_active)
                     VALUES (?, ?, 'consumer', ?, ?, 1)",
                    [$telegramId, $fullName, $buildingId, $unitId]
                );
                $userId = (int)DB::lastInsertId();

                DB::execute("UPDATE units SET owner_id = ? WHERE id = ?", [$userId, $unitId]);

                DB::execute(
                    "INSERT INTO unit_twin_states (unit_id) VALUES (?) ON DUPLICATE KEY UPDATE unit_id = unit_id",
                    [$unitId]
                );

                DB::execute(
                    "INSERT INTO consumption_limits (unit_id, metric_type, monthly_limit, price_per_unit, period_start, period_end)
                     VALUES
                     (?, 'water', 150, 1500, DATE_FORMAT(NOW(), '%Y-%m-01'), LAST_DAY(NOW())),
                     (?, 'electricity', 500, 2500, DATE_FORMAT(NOW(), '%Y-%m-01'), LAST_DAY(NOW())),
                     (?, 'gas', 100, 2000, DATE_FORMAT(NOW(), '%Y-%m-01'), LAST_DAY(NOW()))",
                    [$unitId, $unitId, $unitId]
                );

                DB::execute(
                    "INSERT INTO energy_credits (unit_id, metric_type, balance)
                     VALUES
                     (?, 'water', 0),
                     (?, 'electricity', 0),
                     (?, 'gas', 0)",
                    [$unitId, $unitId, $unitId]
                );
            }

            $daysBack = 3;
            $readingsPerDay = 6;
            $readingsInserted = 0;

            for ($day = $daysBack; $day >= 0; $day--) {
                $date = date('Y-m-d', strtotime("-{$day} days"));

                foreach ($allUnitIds as $unitId) {
                    for ($reading = 0; $reading < $readingsPerDay; $reading++) {
                        $hour = $reading * 4;
                        $timestamp = "{$date} " . sprintf('%02d:00:00', $hour);

                        $multiplier = 1.0;
                        if ($hour >= 6 && $hour <= 9) {
                            $multiplier = 1.5;
                        } elseif ($hour >= 18 && $hour <= 22) {
                            $multiplier = 1.8;
                        } elseif ($hour >= 0 && $hour <= 5) {
                            $multiplier = 0.3;
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
                                $timestamp,
                            ]
                        );
                        $readingsInserted += 3;
                    }
                }
            }

            DB::commit();

            $stats = $this->getDbCounts();
            return [
                'buildings' => $stats['buildings'],
                'units' => $stats['units'],
                'consumers' => (int)DB::select("SELECT COUNT(*) as c FROM users WHERE role = 'consumer'")[0]['c'],
                'readings' => $readingsInserted,
            ];
        } catch (\Throwable $e) {
            DB::rollback();
            try {
                DB::execute("SET FOREIGN_KEY_CHECKS = 1");
            } catch (\Throwable $e2) {
            }
            throw $e;
        }
    }

    private function getDbCounts(): array
    {
        return [
            'buildings' => (int)(DB::select("SELECT COUNT(*) as c FROM buildings")[0]['c'] ?? 0),
            'units' => (int)(DB::select("SELECT COUNT(*) as c FROM units")[0]['c'] ?? 0),
            'users' => (int)(DB::select("SELECT COUNT(*) as c FROM users")[0]['c'] ?? 0),
            'readings' => (int)(DB::select("SELECT COUNT(*) as c FROM consumption_readings")[0]['c'] ?? 0),
            'alerts' => (int)(DB::select("SELECT COUNT(*) as c FROM alerts")[0]['c'] ?? 0),
            'transactions' => (int)(DB::select("SELECT COUNT(*) as c FROM credit_transactions")[0]['c'] ?? 0),
        ];
    }

    public function showUnassignedConsumers(): void
    {
        $users = DB::select(
            "SELECT id, telegram_id, first_name, username, created_at
             FROM users
             WHERE role = 'consumer' AND is_active = 1 AND unit_id IS NULL
             ORDER BY created_at DESC
             LIMIT 30"
        );

        $text = "⏳ <b>مصرف‌کنندگان بدون واحد</b>\n" . Telegram::hr() . "\n\n";
        if (empty($users)) {
            $text .= "همه مصرف‌کنندگان واحد دارند.";
        } else {
            foreach ($users as $u) {
                $text .= "👤 <b>" . ($u['first_name'] ?? 'کاربر') . "</b>\n";
                $text .= "🆔 " . ($u['telegram_id'] ?? '-') . "\n";
                $text .= Telegram::hr() . "\n\n";
            }
        }

        $buttons = [];
        foreach ($users as $u) {
            $buttons[] = [Telegram::inlineButton('⚙️ ' . $this->userLabel($u), 'admin_user_' . $u['id'])];
        }
        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_users')];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }

    public function showUnassignedManagers(): void
    {
        $users = DB::select(
            "SELECT id, telegram_id, first_name, username, created_at
             FROM users
             WHERE role = 'manager' AND is_active = 1 AND building_id IS NULL
             ORDER BY created_at DESC
             LIMIT 30"
        );

        $text = "⏳ <b>مدیران بدون ساختمان</b>\n" . Telegram::hr() . "\n\n";
        if (empty($users)) {
            $text .= "همه مدیران ساختمان تخصیص دارند.";
        } else {
            foreach ($users as $u) {
                $text .= "👤 <b>" . ($u['first_name'] ?? 'کاربر') . "</b>\n";
                $text .= "🆔 " . ($u['telegram_id'] ?? '-') . "\n";
                $text .= Telegram::hr() . "\n\n";
            }
        }

        $buttons = [];
        foreach ($users as $u) {
            $buttons[] = [Telegram::inlineButton('⚙️ ' . $this->userLabel($u), 'admin_user_' . $u['id'])];
        }
        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_users')];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }

    public function showUserDetails(int $userId): void
    {
        $rows = DB::select(
            "SELECT u.*, 
                    b.name as building_name,
                    un.unit_name, un.floor_number,
                    ub.name as unit_building_name
             FROM users u
             LEFT JOIN buildings b ON u.building_id = b.id
             LEFT JOIN units un ON u.unit_id = un.id
             LEFT JOIN buildings ub ON un.building_id = ub.id
             WHERE u.id = ? LIMIT 1",
            [$userId]
        );

        if (empty($rows)) {
            $this->respond(
                "کاربر یافت نشد.",
                Telegram::inlineKeyboard([[Telegram::inlineButton('🔙 بازگشت', 'admin_users')]])
            );
            return;
        }

        $u = $rows[0];

        $roleName = match ($u['role']) {
            'admin' => 'مدیر سیستم',
            'manager' => 'مدیر ساختمان',
            'consumer' => 'مصرف‌کننده',
            default => (string)$u['role']
        };

        $text = "👤 <b>پروفایل کاربر</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "نام: <b>" . ($u['first_name'] ?? '-') . "</b>\n";
        if (!empty($u['username'])) {
            $text .= "یوزرنیم: @" . $u['username'] . "\n";
        }
        $text .= "نقش: <b>{$roleName}</b>\n";
        $text .= "شناسه: <code>" . ($u['telegram_id'] ?? '-') . "</code>\n";
        $text .= Telegram::hr() . "\n";

        $text .= "📌 <b>تخصیص‌ها</b>\n";
        $text .= "🏢 ساختمان: <b>" . ($u['building_name'] ?? '-') . "</b>\n";
        if (!empty($u['unit_id'])) {
            $unitLabel = 'طبقه ' . ($u['floor_number'] ?? '-') . ' - ' . ($u['unit_name'] ?? '-');
            $text .= "🏠 واحد: <b>" . ($u['unit_building_name'] ?? '-') . "</b> / {$unitLabel}\n";
        } else {
            $text .= "🏠 واحد: -\n";
        }

        $text .= Telegram::hr() . "\n";

        $text .= "🧩 <b>تغییر نقش</b> (با احتیاط)";

        $buttons = [
            [
                Telegram::inlineButton('👑 مدیر سیستم', 'admin_user_role_' . $userId . '_admin'),
                Telegram::inlineButton('🏢 مدیر ساختمان', 'admin_user_role_' . $userId . '_manager')
            ],
            [Telegram::inlineButton('🏠 مصرف‌کننده', 'admin_user_role_' . $userId . '_consumer')],
        ];

        $buttons[] = [Telegram::inlineButton('🏢 تخصیص ساختمان (مدیر)', 'admin_mgr_assign_' . $userId)];
        $buttons[] = [Telegram::inlineButton('🏠 تخصیص واحد (مصرف‌کننده)', 'admin_con_assign_' . $userId)];
        $buttons[] = [Telegram::inlineButton('🧹 حذف تخصیص واحد', 'admin_con_clear_' . $userId)];
        $buttons[] = [Telegram::inlineButton('🧹 حذف تخصیص ساختمان', 'admin_mgr_clear_' . $userId)];
        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_users')];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }

    public function setUserRole(int $userId, string $role): void
    {
        if (!in_array($role, ['admin', 'manager', 'consumer'], true)) {
            $this->respond("نقش نامعتبر است.");
            return;
        }

        $data = DB::select("SELECT id, role, building_id, unit_id FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (empty($data)) {
            $this->respond("کاربر یافت نشد.");
            return;
        }

        if ($role === 'admin') {
            $this->clearConsumerAssignment($userId, false);
            $this->clearManagerAssignment($userId, false);
            DB::execute("UPDATE users SET role = 'admin', building_id = NULL, unit_id = NULL WHERE id = ?", [$userId]);
        } elseif ($role === 'manager') {
            $this->clearConsumerAssignment($userId, false);
            DB::execute("UPDATE users SET role = 'manager', unit_id = NULL WHERE id = ?", [$userId]);
        } else {
            $this->clearManagerAssignment($userId, false);
            DB::execute("UPDATE users SET role = 'consumer', building_id = NULL, unit_id = NULL WHERE id = ?", [$userId]);
        }

        $this->showUserDetails($userId);
    }

    public function showManagerBuildingSelect(int $userId): void
    {
        $buildings = DB::select("SELECT id, name FROM buildings WHERE is_active = 1 ORDER BY name");

        $text = "🏢 <b>تخصیص ساختمان به مدیر</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "یک ساختمان را انتخاب کنید:";

        $buttons = [];
        foreach ($buildings as $b) {
            $buttons[] = [Telegram::inlineButton($b['name'], 'admin_mgr_set_' . $userId . '_' . $b['id'])];
        }
        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_user_' . $userId)];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }

    public function assignManagerToBuilding(int $userId, int $buildingId): void
    {
        $building = DB::select("SELECT id, name, manager_id FROM buildings WHERE id = ? LIMIT 1", [$buildingId]);
        if (empty($building)) {
            $this->respond("ساختمان یافت نشد.");
            return;
        }

        $prevManagerId = (int)($building[0]['manager_id'] ?? 0);
        if ($prevManagerId > 0 && $prevManagerId !== $userId) {
            DB::execute("UPDATE users SET building_id = NULL WHERE id = ?", [$prevManagerId]);
        }

        DB::execute("UPDATE buildings SET manager_id = ? WHERE id = ?", [$userId, $buildingId]);
        DB::execute("UPDATE users SET role = 'manager', building_id = ?, unit_id = NULL WHERE id = ?", [$buildingId, $userId]);

        $this->showUserDetails($userId);
    }

    public function clearManagerAssignment(int $userId, bool $refresh = true): void
    {
        $u = DB::select("SELECT building_id FROM users WHERE id = ? LIMIT 1", [$userId]);
        $buildingId = (int)($u[0]['building_id'] ?? 0);
        if ($buildingId > 0) {
            DB::execute("UPDATE buildings SET manager_id = NULL WHERE id = ? AND manager_id = ?", [$buildingId, $userId]);
        }
        DB::execute("UPDATE users SET building_id = NULL WHERE id = ?", [$userId]);

        if ($refresh) {
            $this->showUserDetails($userId);
        }
    }

    public function showConsumerBuildingSelect(int $userId): void
    {
        $buildings = DB::select("SELECT id, name FROM buildings WHERE is_active = 1 ORDER BY name");

        $text = "🏠 <b>تخصیص واحد به مصرف‌کننده</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "ابتدا ساختمان را انتخاب کنید:";

        $buttons = [];
        foreach ($buildings as $b) {
            $buttons[] = [Telegram::inlineButton($b['name'], 'admin_con_build_' . $userId . '_' . $b['id'])];
        }
        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_user_' . $userId)];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }

    public function showConsumerUnitSelect(int $userId, int $buildingId): void
    {
        $building = DB::select("SELECT id, name FROM buildings WHERE id = ? LIMIT 1", [$buildingId]);
        if (empty($building)) {
            $this->respond("ساختمان یافت نشد.");
            return;
        }

        $units = DB::select(
            "SELECT id, floor_number, unit_name, owner_id
             FROM units
             WHERE building_id = ? AND is_active = 1 AND (owner_id IS NULL OR owner_id = ?)
             ORDER BY floor_number, unit_name
             LIMIT 60",
            [$buildingId, $userId]
        );

        $text = "🏠 <b>انتخاب واحد</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "ساختمان: <b>" . $building[0]['name'] . "</b>\n\n";

        if (empty($units)) {
            $text .= "هیچ واحد آزاد (بدون مالک) در این ساختمان یافت نشد.";
            $this->respond(
                $text,
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_con_assign_' . $userId)],
                    [Telegram::inlineButton('👤 کاربر', 'admin_user_' . $userId)]
                ])
            );
            return;
        }

        $text .= "یک واحد آزاد را انتخاب کنید:";

        $buttons = [];
        foreach ($units as $un) {
            $label = 'طبقه ' . $un['floor_number'] . ' - واحد ' . $un['unit_name'];
            $buttons[] = [Telegram::inlineButton($label, 'admin_con_set_' . $userId . '_' . $un['id'])];
        }
        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_con_assign_' . $userId)];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }

    public function assignConsumerToUnit(int $userId, int $unitId): void
    {
        $unit = DB::select("SELECT id, building_id, unit_name, floor_number, owner_id FROM units WHERE id = ? LIMIT 1", [$unitId]);
        if (empty($unit)) {
            $this->respond("واحد یافت نشد.");
            return;
        }

        $ownerId = (int)($unit[0]['owner_id'] ?? 0);
        if ($ownerId > 0 && $ownerId !== $userId) {
            $this->respond(
                "این واحد قبلاً به کاربر دیگری تخصیص داده شده است.",
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_con_build_' . $userId . '_' . $unit[0]['building_id'])],
                    [Telegram::inlineButton('👤 کاربر', 'admin_user_' . $userId)]
                ])
            );
            return;
        }

        $this->clearConsumerAssignment($userId, false);

        DB::execute("UPDATE units SET owner_id = ? WHERE id = ?", [$userId, $unitId]);
        DB::execute(
            "UPDATE users SET role = 'consumer', unit_id = ?, building_id = ? WHERE id = ?",
            [$unitId, $unit[0]['building_id'], $userId]
        );

        $this->showUserDetails($userId);
    }

    public function clearConsumerAssignment(int $userId, bool $refresh = true): void
    {
        $u = DB::select("SELECT unit_id FROM users WHERE id = ? LIMIT 1", [$userId]);
        $unitId = (int)($u[0]['unit_id'] ?? 0);
        if ($unitId > 0) {
            DB::execute("UPDATE units SET owner_id = NULL WHERE id = ? AND owner_id = ?", [$unitId, $userId]);
        }
        DB::execute("UPDATE users SET unit_id = NULL, building_id = NULL WHERE id = ?", [$userId]);

        if ($refresh) {
            $this->showUserDetails($userId);
        }
    }

    public function showPriceEditMenu(string $metric): void
    {
        if (!in_array($metric, ['water', 'electricity', 'gas'], true)) {
            $this->respond("نوع مصرف نامعتبر است.");
            return;
        }

        $key = 'base_price_' . $metric;
        $row = DB::select("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1", [$key]);
        $current = (float)($row[0]['setting_value'] ?? 0);

        $name = match ($metric) {
            'water' => '💧 آب',
            'electricity' => '⚡ برق',
            'gas' => '🔥 گاز',
            default => $metric
        };

        $text = "💲 <b>تغییر نرخ</b>\n\n";
        $text .= "نوع: <b>{$name}</b>\n";
        $text .= "نرخ فعلی: <b>" . Telegram::formatPrice($current) . "</b>\n\n";
        $text .= "با دکمه‌ها مقدار را کم/زیاد کنید:";

        $buttons = [
            [
                Telegram::inlineButton('-100', 'admin_price_adj_' . $metric . '_-100'),
                Telegram::inlineButton('+100', 'admin_price_adj_' . $metric . '_100')
            ],
            [
                Telegram::inlineButton('-500', 'admin_price_adj_' . $metric . '_-500'),
                Telegram::inlineButton('+500', 'admin_price_adj_' . $metric . '_500')
            ],
            [
                Telegram::inlineButton('-1000', 'admin_price_adj_' . $metric . '_-1000'),
                Telegram::inlineButton('+1000', 'admin_price_adj_' . $metric . '_1000')
            ],
            [Telegram::inlineButton('🔙 بازگشت', 'admin_prices')]
        ];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }

    public function adjustPrice(string $metric, int $delta): void
    {
        if (!in_array($metric, ['water', 'electricity', 'gas'], true)) {
            $this->respond("نوع مصرف نامعتبر است.");
            return;
        }

        $key = 'base_price_' . $metric;
        $row = DB::select("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1", [$key]);
        $current = (int)round((float)($row[0]['setting_value'] ?? 0));
        $next = max(0, $current + $delta);

        DB::execute(
            "INSERT INTO system_settings (setting_key, setting_value, description)
             VALUES (?, ?, '')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$key, (string)$next]
        );

        $this->showPriceEditMenu($metric);
    }

    private function userLabel(array $user): string
    {
        $name = (string)($user['first_name'] ?? 'کاربر');
        $tid = (string)($user['telegram_id'] ?? '');

        if ($tid !== '') {
            return $name . ' (' . $tid . ')';
        }

        return $name;
    }

    /**
     * Show users management
     */
    public function showUsers(): void
    {
        $stats = DB::select(
            "SELECT 
                role,
                COUNT(*) as count
             FROM users
             WHERE is_active = 1
             GROUP BY role"
        );

        $text = "👥 <b>مدیریت کاربران</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "📊 <b>آمار کاربران</b>\n\n";

        foreach ($stats as $stat) {
            $roleName = match ($stat['role']) {
                'admin' => 'مدیران سیستم',
                'manager' => 'مدیران ساختمان',
                'consumer' => 'مصرف‌کنندگان',
                default => $stat['role']
            };

            $text .= "• {$roleName}: <b>{$stat['count']}</b> نفر\n";
        }

        $text .= "\n" . Telegram::hr() . "\n";
        $text .= "یک لیست را انتخاب کنید:";

        $keyboard = Telegram::inlineKeyboard([
            [
                Telegram::inlineButton('👑 مدیران سیستم', 'admin_list_admins'),
                Telegram::inlineButton('🏢 مدیران ساختمان', 'admin_list_managers')
            ],
            [Telegram::inlineButton('🏠 مصرف‌کنندگان', 'admin_list_consumers')],
            [Telegram::inlineButton('⏳ مصرف‌کنندگان بدون واحد', 'admin_unassigned_consumers')],
            [Telegram::inlineButton('⏳ مدیران بدون ساختمان', 'admin_unassigned_managers')],
            [Telegram::inlineButton('🔙 بازگشت', 'admin_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show price settings
     */
    public function showPriceSettings(): void
    {
        $prices = DB::select(
            "SELECT setting_key, setting_value
             FROM system_settings
             WHERE setting_key LIKE 'base_price_%'"
        );

        $text = "💲 <b>تنظیمات نرخ‌ها</b>\n";
        $text .= Telegram::hr() . "\n";

        foreach ($prices as $price) {
            $name = match ($price['setting_key']) {
                'base_price_water' => 'آب',
                'base_price_electricity' => 'برق',
                'base_price_gas' => 'گاز',
                default => $price['setting_key']
            };

            $text .= "• {$name}: <b>" . Telegram::formatPrice((float)$price['setting_value']) . "</b>\n";
        }

        $text .= "\n" . Telegram::hr() . "\n";
        $text .= "<b>تغییر نرخ</b>";

        $keyboard = Telegram::inlineKeyboard([
            [
                Telegram::inlineButton('💧 آب', 'admin_price_edit_water'),
                Telegram::inlineButton('⚡ برق', 'admin_price_edit_electricity')
            ],
            [Telegram::inlineButton('🔥 گاز', 'admin_price_edit_gas')],
            [Telegram::inlineButton('🔙 بازگشت', 'admin_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show system report
     */
    public function showSystemReport(): void
    {
        // Total buildings
        $buildingsCount = DB::select("SELECT COUNT(*) as count FROM buildings WHERE is_active = 1");

        // Total units
        $unitsCount = DB::select("SELECT COUNT(*) as count FROM units WHERE is_active = 1");

        // Today's total consumption
        $todayConsumption = DB::select(
            "SELECT 
                metric_type,
                SUM(value) as total
             FROM consumption_readings
             WHERE DATE(timestamp) = CURDATE()
             GROUP BY metric_type"
        );

        // Pending transactions
        $pendingTrans = DB::select(
            "SELECT COUNT(*) as count FROM credit_transactions WHERE status = 'pending'"
        );

        // Unread alerts
        $unreadAlerts = DB::select(
            "SELECT COUNT(*) as count FROM alerts WHERE is_read = 0"
        );

        $carbonEngine = new CarbonEngine();
        $carbon = $this->getSystemCarbonBreakdown('today', $carbonEngine);

        $text = "📈 <b>گزارش کلی سیستم</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "🏢 ساختمان‌ها: <b>{$buildingsCount[0]['count']}</b>\n";
        $text .= "🏠 واحدها: <b>{$unitsCount[0]['count']}</b>\n";
        $text .= Telegram::hr() . "\n";

        $text .= "<b>مصرف امروز</b>\n";
        foreach ($todayConsumption as $cons) {
            $name = match ($cons['metric_type']) {
                'water' => '💧 آب',
                'electricity' => '⚡ برق',
                'gas' => '🔥 گاز',
                default => $cons['metric_type']
            };
            $text .= "{$name}: " . round((float)$cons['total'], 2) . "\n";
        }

        $text .= Telegram::hr() . "\n";
        $text .= "📋 تراکنش‌های در انتظار: <b>{$pendingTrans[0]['count']}</b>\n";
        $text .= "⚠️ هشدارهای خوانده‌نشده: <b>{$unreadAlerts[0]['count']}</b>\n";
        $text .= "🌍 کربن امروز: <b>" . round((float)$carbon['total_kg'], 2) . "</b> kgCO₂e\n";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('بروزرسانی 🔄', 'admin_report')],
            [Telegram::inlineButton('بازگشت 🔙', 'admin_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show admins list
     */
    public function showAdminsList(): void
    {
        $admins = DB::select(
            "SELECT id, telegram_id, first_name, username, created_at
             FROM users
             WHERE role = 'admin' AND is_active = 1
             ORDER BY created_at DESC"
        );

        $text = "👑 <b>مدیران سیستم</b>\n" . Telegram::hr() . "\n\n";

        if (empty($admins)) {
            $text .= "هیچ مدیری ثبت نشده است.";
        } else {
            $text .= "تعداد: <b>" . count($admins) . "</b> نفر\n\n";
            foreach ($admins as $admin) {
                $text .= "• " . $admin['first_name'];
                if ($admin['username']) {
                    $text .= " (@" . $admin['username'] . ")";
                }
                $text .= "\n  شناسه: " . $admin['telegram_id'] . "\n\n";
            }
        }

        $buttons = [];
        foreach (array_slice($admins, 0, 10) as $admin) {
            $buttons[] = [Telegram::inlineButton('⚙️ ' . $this->userLabel($admin), 'admin_user_' . $admin['id'])];
        }
        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_users')];
        $keyboard = Telegram::inlineKeyboard($buttons);

        $this->respond($text, $keyboard);
    }

    /**
     * Show managers list
     */
    public function showManagersList(): void
    {
        $managers = DB::select(
            "SELECT u.id, u.telegram_id, u.first_name, u.username, 
                    b.name as building_name
             FROM users u
             LEFT JOIN buildings b ON u.building_id = b.id
             WHERE u.role = 'manager' AND u.is_active = 1
             ORDER BY u.created_at DESC"
        );

        $text = "🏢 <b>مدیران ساختمان</b>\n" . Telegram::hr() . "\n\n";

        if (empty($managers)) {
            $text .= "هیچ مدیر ساختمانی ثبت نشده است.";
        } else {
            $text .= "تعداد: <b>" . count($managers) . "</b> نفر\n\n";
            foreach ($managers as $manager) {
                $text .= "• " . $manager['first_name'];
                if ($manager['username']) {
                    $text .= " (@" . $manager['username'] . ")";
                }
                if ($manager['building_name']) {
                    $text .= "\n  ساختمان: " . $manager['building_name'];
                }
                $text .= "\n\n";
            }
        }

        $buttons = [];
        foreach (array_slice($managers, 0, 15) as $mgr) {
            $buttons[] = [Telegram::inlineButton('⚙️ ' . $this->userLabel($mgr), 'admin_user_' . $mgr['id'])];
        }
        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_users')];
        $keyboard = Telegram::inlineKeyboard($buttons);

        $this->respond($text, $keyboard);
    }

    /**
     * Show consumers list
     */
    public function showConsumersList(): void
    {
        $consumers = DB::select(
            "SELECT u.id, u.telegram_id, u.first_name, u.username,
                    b.name as building_name, un.unit_name
             FROM users u
             LEFT JOIN units un ON u.unit_id = un.id
             LEFT JOIN buildings b ON un.building_id = b.id
             WHERE u.role = 'consumer' AND u.is_active = 1
             ORDER BY u.created_at DESC
             LIMIT 50"
        );

        $text = "🏠 <b>مصرف‌کنندگان</b>\n" . Telegram::hr() . "\n\n";

        if (empty($consumers)) {
            $text .= "هیچ مصرف‌کننده‌ای ثبت نشده است.";
        } else {
            $totalCount = DB::select(
                "SELECT COUNT(*) as count FROM users WHERE role = 'consumer' AND is_active = 1"
            );
            $text .= "تعداد کل: " . $totalCount[0]['count'] . " نفر";
            $text .= " (نمایش: " . count($consumers) . " نفر اول)\n\n";

            foreach ($consumers as $consumer) {
                $text .= "• " . $consumer['first_name'];
                if ($consumer['building_name'] && $consumer['unit_name']) {
                    $text .= "\n  " . $consumer['building_name'] . " - واحد " . $consumer['unit_name'];
                }
                $text .= "\n\n";
            }
        }

        $buttons = [];
        foreach (array_slice($consumers, 0, 15) as $con) {
            $buttons[] = [Telegram::inlineButton('⚙️ ' . $this->userLabel($con), 'admin_user_' . $con['id'])];
        }
        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_users')];
        $keyboard = Telegram::inlineKeyboard($buttons);

        $this->respond($text, $keyboard);
    }

    /**
     * Show add building form
     */
    public function showAddBuilding(): void
    {
        $text = "➕ <b>افزودن ساختمان جدید</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "این بخش در حال توسعه است.\n\n";
        $text .= "<i>برای افزودن ساختمان، لطفاً از پنل وب استفاده کنید یا با پشتیبانی تماس بگیرید.</i>";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('🔙 بازگشت', 'admin_buildings')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show system settings
     */
    public function showSettings(): void
    {
        $settings = DB::select(
            "SELECT setting_key, setting_value
             FROM system_settings
             WHERE setting_key NOT LIKE 'base_price_%'
               AND setting_key NOT LIKE 'runtime_%'
             ORDER BY setting_key"
        );

        $text = "⚙️ <b>تنظیمات سیستم</b>\n";
        $text .= Telegram::hr() . "\n\n";

        if (empty($settings)) {
            $text .= "هیچ تنظیماتی یافت نشد.";
        } else {
            foreach ($settings as $setting) {
                $key = str_replace('_', ' ', (string)$setting['setting_key']);
                $text .= "• <b>" . ucfirst($key) . "</b>: " . $setting['setting_value'] . "\n";
            }
        }

        $text .= "\n<i>تنظیمات قابل تغییر از پایگاه داده هستند.</i>";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('بازگشت 🔙', 'admin_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function showBuildingDetails(int $buildingId): void
    {
        $building = DB::select(
            "SELECT b.*, u.first_name as manager_name
             FROM buildings b
             LEFT JOIN users u ON b.manager_id = u.id
             WHERE b.id = ? LIMIT 1",
            [$buildingId]
        );

        if (empty($building)) {
            $this->respond(
                "ساختمان یافت نشد.",
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'admin_buildings')]
                ])
            );
            return;
        }

        $b = $building[0];

        $unitsCount = DB::select(
            "SELECT COUNT(*) as count FROM units WHERE building_id = ? AND is_active = 1",
            [$buildingId]
        );

        $unreadAlerts = DB::select(
            "SELECT COUNT(*) as count
             FROM alerts a
             JOIN units un ON a.unit_id = un.id
             WHERE un.building_id = ? AND a.is_read = 0",
            [$buildingId]
        );

        $todayConsumption = DB::select(
            "SELECT cr.metric_type, SUM(cr.value) as total
             FROM consumption_readings cr
             JOIN units un ON cr.unit_id = un.id
             WHERE un.building_id = ? AND DATE(cr.timestamp) = CURDATE()
             GROUP BY cr.metric_type",
            [$buildingId]
        );

        $today = [
            'water' => 0.0,
            'electricity' => 0.0,
            'gas' => 0.0,
        ];

        foreach ($todayConsumption as $row) {
            if (isset($today[$row['metric_type']])) {
                $today[$row['metric_type']] = (float)$row['total'];
            }
        }

        $text = "🏢 <b>جزئیات ساختمان</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "نام: <b>{$b['name']}</b>\n";
        $text .= "👤 مدیر: " . ($b['manager_name'] ?? 'نامشخص') . "\n";
        $text .= "🏗 طبقات: <b>{$b['total_floors']}</b>\n";
        $text .= "🏠 واحدهای فعال: <b>" . ($unitsCount[0]['count'] ?? 0) . "</b>\n";
        $text .= "🔔 هشدارهای خوانده‌نشده: <b>" . ($unreadAlerts[0]['count'] ?? 0) . "</b>\n";
        $text .= Telegram::hr() . "\n";

        $text .= "<b>مصرف امروز ساختمان</b>\n";
        $text .= "💧 آب: <b>" . round($today['water'], 1) . "</b>\n";
        $text .= "⚡ برق: <b>" . round($today['electricity'], 1) . "</b>\n";
        $text .= "🔥 گاز: <b>" . round($today['gas'], 1) . "</b>\n";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('👤 تعیین مدیر ساختمان', 'admin_build_mgr_' . $buildingId)],
            [Telegram::inlineButton('🏢 لیست ساختمان‌ها', 'admin_buildings')],
            [Telegram::inlineButton('🔙 بازگشت', 'admin_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function showBuildingManagerSelect(int $buildingId): void
    {
        $building = DB::select("SELECT id, name FROM buildings WHERE id = ? LIMIT 1", [$buildingId]);
        if (empty($building)) {
            $this->respond("ساختمان یافت نشد.");
            return;
        }

        $candidates = DB::select(
            "SELECT id, telegram_id, first_name, username, role
             FROM users
             WHERE is_active = 1 AND role IN ('manager', 'consumer')
             ORDER BY (role = 'manager') DESC, created_at DESC
             LIMIT 25"
        );

        $text = "👤 <b>انتخاب مدیر ساختمان</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "ساختمان: <b>" . $building[0]['name'] . "</b>\n\n";
        $text .= "یک کاربر را انتخاب کنید (در صورت نیاز نقش به مدیر ساختمان تغییر می‌کند):";

        $buttons = [];
        foreach ($candidates as $u) {
            $role = $u['role'] === 'manager' ? '🏢' : '👤';
            $buttons[] = [Telegram::inlineButton($role . ' ' . $this->userLabel($u), 'admin_mgr_set_' . $u['id'] . '_' . $buildingId)];
        }

        $buttons[] = [Telegram::inlineButton('🔙 بازگشت', 'admin_building_' . $buildingId)];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }
}
