<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Panels/ConsumerPanel.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Consumer/Unit owner panel for viewing consumption and managing credits
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Panels;

use SmartBuilding\Utils\Telegram;
use SmartBuilding\Database\DB;
use SmartBuilding\Models\Unit;
use SmartBuilding\Services\CreditEngine;
use SmartBuilding\Services\CarbonEngine;
use SmartBuilding\Services\DigitalTwinEngine;
use SmartBuilding\Services\DataSimulator;
use SmartBuilding\Services\ConsumptionAnalyzer;
use SmartBuilding\Services\ForecastEngine;
use SmartBuilding\Services\RecommendationEngine;

class ConsumerPanel
{
    private Telegram $telegram;
    private int $chatId;
    private int $unitId;
    private ?int $contextMessageId;

    public function __construct(Telegram $telegram, int $chatId, int $unitId, ?int $contextMessageId = null)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        $this->unitId = $unitId;
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
     * Show main consumer menu
     */
    public function showMainMenu(): void
    {
        $unit = Unit::find($this->unitId);

        if (!$unit) {
            $this->respond("واحد یافت نشد!");
            return;
        }

        // Get unread alerts
        $alertsCount = DB::select(
            "SELECT COUNT(*) as count FROM alerts WHERE unit_id = ? AND is_read = 0",
            [$this->unitId]
        );

        $alertBadge = $alertsCount[0]['count'] > 0 ? " ({$alertsCount[0]['count']})" : "";

        $creditEngine = new CreditEngine();
        $credits = $creditEngine->getCredits($this->unitId);
        $hasNegative = false;
        foreach ($credits as $credit) {
            if ($credit < 0) {
                $hasNegative = true;
                break;
            }
        }

        // Persistent keyboard buttons (main navigation)
        $keyboard = Telegram::replyKeyboard([
            [
                Telegram::keyboardButton('مصرف امروز 📊'),
                Telegram::keyboardButton('آمار هفتگی 📈')
            ],
            [
                Telegram::keyboardButton('هزینه‌ها 💵'),
                Telegram::keyboardButton('کربن 🌍')
            ],
            [
                Telegram::keyboardButton('هشدارها ⚠️'),
                Telegram::keyboardButton('اعتبارات 💰')
            ],
            [
                Telegram::keyboardButton('مدیریت هوشمند 🎛')
            ],
            [
                Telegram::keyboardButton('منوی اصلی 🏠'),
                Telegram::keyboardButton('تماس با مدیر 📞')
            ],
            [
                Telegram::keyboardButton('راهنما 📚'),
                Telegram::keyboardButton('شناسه من 🆔')
            ]
        ]);

        $unread = (int)($alertsCount[0]['count'] ?? 0);
        $creditStatus = $hasNegative ? '⚠️ <b>نیاز به شارژ اعتبار</b>' : '✅ <b>اعتبار شما مطلوب است</b>';

        $text = "🏠 <b>داشبورد واحد</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "📍 <b>طبقه {$unit['floor_number']} · واحد {$unit['unit_name']}</b>\n\n";
        $text .= "🔔 هشدارهای خوانده‌نشده: <b>{$unread}</b>{$alertBadge}\n";
        $text .= "💳 وضعیت اعتبار: {$creditStatus}\n";
        $text .= Telegram::hr() . "\n";
        $text .= "یک بخش را انتخاب کنید:";

        if ($this->contextMessageId !== null) {
            $inlineButtons = [
                [Telegram::inlineButton('مصرف امروز 📊', 'con_today'), Telegram::inlineButton('هزینه‌ها 💵', 'con_costs')],
                [Telegram::inlineButton('آمار هفتگی 📈', 'con_weekly'), Telegram::inlineButton('کربن 🌍', 'con_carbon')],
                [Telegram::inlineButton('مدیریت هوشمند (Digital Twin) 🧬', 'con_smart')],
                [Telegram::inlineButton('هشدارها 🔔' . $alertBadge, 'con_alerts'), Telegram::inlineButton('اعتبارات 💳', 'con_credits')],
            ];

            if ($hasNegative) {
                $inlineButtons[] = [Telegram::inlineButton('خرید اعتبار (اقدام فوری) 🛒', 'con_buy_credit')];
            }

            $inlineButtons[] = [Telegram::inlineButton('بازگشت 🔙', 'con_home')];

            $this->respond($text, Telegram::inlineKeyboard($inlineButtons));
            return;
        }

        $this->respond($text, $keyboard, true);

        // Quick action inline buttons (glass buttons) - only if there are alerts or negative credits
        $needsAction = false;
        $inlineButtons = [];

        if ($alertsCount[0]['count'] > 0) {
            $needsAction = true;
            $inlineButtons[] = [Telegram::inlineButton("هشدارها{$alertBadge} ⚠️", 'con_alerts')];
        }

        if ($hasNegative) {
            $needsAction = true;
            $inlineButtons[] = [Telegram::inlineButton('خرید اعتبار (اقدام فوری) 🛒', 'con_buy_credit')];
        }

        if ($needsAction) {
            $this->respond(
                "🚨 <b>اقدامات فوری</b>\n" . Telegram::hr(),
                Telegram::inlineKeyboard($inlineButtons),
                true
            );
        }
    }

    public function showSmartMenu(): void
    {
        $twin = new DigitalTwinEngine();
        $state = $twin->getState($this->unitId);

        $scenarioLabel = match ((string)($state['scenario'] ?? 'family')) {
            'empty' => '🏠 خانه خالی',
            'family' => '👨‍👩‍👧 خانواده',
            'party' => '🎉 مهمانی',
            'night' => '🌙 شب',
            'travel' => '✈️ مسافرت',
            default => '👨‍👩‍👧 خانواده',
        };

        $seasonLabel = match ((string)($state['season'] ?? 'spring')) {
            'spring' => '🌱 بهار',
            'summer' => '☀️ تابستان',
            'autumn' => '🍂 پاییز',
            'winter' => '❄️ زمستان',
            default => '🌱 بهار',
        };

        $eco = (bool)($state['eco_mode'] ?? false);
        $ecoLabel = $eco ? '✅ فعال' : '❌ غیرفعال';
        $lights = (bool)($state['lights_on'] ?? true);
        $lightsLabel = $lights ? 'روشن' : 'خاموش';
        $waterHeater = (bool)($state['water_heater_on'] ?? true);
        $waterHeaterLabel = $waterHeater ? 'روشن' : 'خاموش';
        $acMode = (string)($state['ac_mode'] ?? 'off');
        $acLabel = match ($acMode) {
            'low' => 'کم',
            'medium' => 'متوسط',
            'high' => 'زیاد',
            default => 'خاموش',
        };
        $heatingTemp = (int)($state['heating_temp'] ?? 22);
        $costSens = (int)($state['cost_sensitivity'] ?? 50);
        $greenSens = (int)($state['green_sensitivity'] ?? 50);
        $budget = (int)($state['monthly_budget_toman'] ?? 1500000);

        $costBar = Telegram::progressBar($costSens / 100, 10);
        $greenBar = Telegram::progressBar($greenSens / 100, 10);

        $text = "🧬 <b>مدیریت هوشمند (Digital Twin)</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "🎭 سناریو: <b>{$scenarioLabel}</b>\n";
        $text .= "🗓 فصل: <b>{$seasonLabel}</b>\n";
        $text .= "♻️ Eco Mode: <b>{$ecoLabel}</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "💡 چراغ‌ها: <b>{$lightsLabel}</b>   🚿 آبگرمکن: <b>{$waterHeaterLabel}</b>\n";
        $text .= "❄️ کولر: <b>{$acLabel}</b>   🔥 گرمایش: <b>{$heatingTemp}°</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "💰 بودجه ماهانه: <b>" . Telegram::formatPrice((float)$budget) . "</b>\n";
        $text .= "🎯 اقتصادی: {$costBar} <b>{$costSens}</b>/100\n";
        $text .= "🌍 سبز: {$greenBar} <b>{$greenSens}</b>/100\n";

        $keyboard = Telegram::inlineKeyboard([
            [
                Telegram::inlineButton('سناریو 🎭', 'con_scn'),
                Telegram::inlineButton('فصل 🗓', 'con_season')
            ],
            [
                Telegram::inlineButton('تجهیزات 🧩', 'con_devices'),
                Telegram::inlineButton('Eco Mode ♻️', 'con_eco')
            ],
            [
                Telegram::inlineButton('بودجه 💰', 'con_budget'),
                Telegram::inlineButton('حساسیت‌ها 🎯', 'con_sens')
            ],
            [
                Telegram::inlineButton('شبیه‌سازی الان 🧪', 'con_sim_now'),
                Telegram::inlineButton('پیش‌بینی 🔮', 'con_forecast')
            ],
            [Telegram::inlineButton('پیشنهادهای هوشمند 🧠', 'con_reco')],
            [Telegram::inlineButton('بازگشت 🔙', 'con_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function showScenarioMenu(): void
    {
        $twin = new DigitalTwinEngine();
        $state = $twin->getState($this->unitId);
        $current = (string)($state['scenario'] ?? 'family');

        $text = "🎭 <b>انتخاب سناریو</b>\n\n";
        $text .= "سناریو روی میزان مصرف و رفتار سنسورها اثر مستقیم دارد.";

        $btn = static function (string $label, string $key, string $currentKey): array {
            $mark = $key === $currentKey ? ' ✅' : '';
            return Telegram::inlineButton($label . $mark, 'con_scn_set_' . $key);
        };

        $keyboard = Telegram::inlineKeyboard([
            [
                $btn('خانه خالی 🏠', 'empty', $current),
                $btn('خانواده 👨‍👩‍👧', 'family', $current)
            ],
            [
                $btn('مهمانی 🎉', 'party', $current),
                $btn('شب 🌙', 'night', $current)
            ],
            [$btn('مسافرت ✈️', 'travel', $current)],
            [Telegram::inlineButton('بازگشت 🔙', 'con_smart')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function setScenario(string $scenario): void
    {
        $twin = new DigitalTwinEngine();
        $twin->setScenario($this->unitId, $scenario);
        $this->showSmartMenu();
    }

    public function showSeasonMenu(): void
    {
        $twin = new DigitalTwinEngine();
        $state = $twin->getState($this->unitId);
        $current = (string)($state['season'] ?? 'spring');

        $text = "🗓 <b>انتخاب فصل</b>\n\n";
        $text .= "فصل روی مصرف برق/گاز (کولر/گرمایش) اثر می‌گذارد.";

        $btn = static function (string $label, string $key, string $currentKey): array {
            $mark = $key === $currentKey ? ' ✅' : '';
            return Telegram::inlineButton($label . $mark, 'con_season_set_' . $key);
        };

        $keyboard = Telegram::inlineKeyboard([
            [$btn('بهار 🌱', 'spring', $current), $btn('تابستان ☀️', 'summer', $current)],
            [$btn('پاییز 🍂', 'autumn', $current), $btn('زمستان ❄️', 'winter', $current)],
            [Telegram::inlineButton('بازگشت 🔙', 'con_smart')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function setSeason(string $season): void
    {
        $twin = new DigitalTwinEngine();
        $twin->setSeason($this->unitId, $season);
        $this->showSmartMenu();
    }

    public function showDevicesMenu(): void
    {
        $twin = new DigitalTwinEngine();
        $state = $twin->getState($this->unitId);

        $lights = (bool)($state['lights_on'] ?? true);
        $waterHeater = (bool)($state['water_heater_on'] ?? true);
        $acMode = (string)($state['ac_mode'] ?? 'off');
        $heatingTemp = (int)($state['heating_temp'] ?? 22);

        $acLabel = match ($acMode) {
            'low' => 'کم',
            'medium' => 'متوسط',
            'high' => 'زیاد',
            default => 'خاموش',
        };

        $lightsBtn = '💡 چراغ‌ها: ' . ($lights ? 'روشن ✅' : 'خاموش ❌');
        $whBtn = '🚿 آبگرمکن: ' . ($waterHeater ? 'روشن ✅' : 'خاموش ❌');

        $text = "🧩 <b>کنترل تجهیزات</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "💡 چراغ‌ها: <b>" . ($lights ? 'روشن' : 'خاموش') . "</b>\n";
        $text .= "🚿 آبگرمکن: <b>" . ($waterHeater ? 'روشن' : 'خاموش') . "</b>\n";
        $text .= "❄️ کولر: <b>{$acLabel}</b>\n";
        $text .= "🔥 دمای گرمایش: <b>{$heatingTemp}°</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "با دکمه‌ها وضعیت را تغییر دهید:";

        $keyboard = Telegram::inlineKeyboard([
            [
                Telegram::inlineButton($lightsBtn, 'con_dev_toggle_lights'),
                Telegram::inlineButton($whBtn, 'con_dev_toggle_wh')
            ],
            [
                Telegram::inlineButton('❄️ خاموش', 'con_dev_ac_off'),
                Telegram::inlineButton('❄️ کم', 'con_dev_ac_low')
            ],
            [
                Telegram::inlineButton('❄️ متوسط', 'con_dev_ac_medium'),
                Telegram::inlineButton('❄️ زیاد', 'con_dev_ac_high')
            ],
            [
                Telegram::inlineButton('🔥 دما -1°', 'con_dev_heat_-1'),
                Telegram::inlineButton('🔥 دما +1°', 'con_dev_heat_1')
            ],
            [Telegram::inlineButton('بازگشت 🔙', 'con_smart')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function toggleEcoMode(): void
    {
        $twin = new DigitalTwinEngine();
        $twin->toggleEcoMode($this->unitId);
        $this->showSmartMenu();
    }

    public function toggleDevice(string $device): void
    {
        $twin = new DigitalTwinEngine();
        $twin->toggleDevice($this->unitId, $device);
        $this->showDevicesMenu();
    }

    public function setAcMode(string $mode): void
    {
        $twin = new DigitalTwinEngine();
        $twin->setAcMode($this->unitId, $mode);
        $this->showDevicesMenu();
    }

    public function adjustHeatingTemp(int $delta): void
    {
        $twin = new DigitalTwinEngine();
        $twin->adjustHeatingTemp($this->unitId, $delta);
        $this->showDevicesMenu();
    }

    public function simulateNow(): void
    {
        $sim = new DataSimulator();
        $sim->simulateUnitNow($this->unitId);

        $alertsCreated = 0;
        try {
            $analyzer = new ConsumptionAnalyzer();
            $alertsCreated = $analyzer->analyzeUnit($this->unitId);
        } catch (\Throwable $e) {
        }

        $text = "🧪 ✅ <b>شبیه‌سازی انجام شد</b>\n\n";
        $text .= "یک بسته داده جدید از سنسورها برای واحد شما تولید شد.";

        if ($alertsCreated > 0) {
            $text .= "\n\n⚠️ تعداد هشدارهای جدید: <b>{$alertsCreated}</b>";
        }

        $this->respond(
            $text,
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('📊 مصرف امروز', 'con_today')],
                [Telegram::inlineButton('🌍 کربن', 'con_carbon')],
                [Telegram::inlineButton('🎛 مدیریت هوشمند', 'con_smart')]
            ])
        );
    }

    public function showForecast(): void
    {
        $forecastEngine = new ForecastEngine();
        $data = $forecastEngine->getUnitMonthlyForecast($this->unitId);

        $riskIcon = match ((string)($data['risk'] ?? 'low')) {
            'high' => '🚨',
            'medium' => '⚠️',
            default => '✅'
        };

        $carbonEngine = new CarbonEngine();
        $carbonForecast = $carbonEngine->forecastUnitMonthCarbonKg($this->unitId);

        $text = "🔮 <b>پیش‌بینی ماه جاری</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "💰 هزینه تا امروز: <b>" . Telegram::formatPrice((float)$data['cost_so_far']) . "</b>\n";
        $text .= "📈 پیش‌بینی پایان ماه: <b>" . Telegram::formatPrice((float)$data['forecast_month']) . "</b> {$riskIcon}\n";
        $text .= "🎯 بودجه ماهانه: <b>" . Telegram::formatPrice((float)($data['budget'] ?? 0)) . "</b>\n";
        $text .= "🌍 پیش‌بینی کربن پایان ماه: <b>" . round((float)$carbonForecast, 2) . "</b> kgCO₂e\n";
        $text .= Telegram::hr() . "\n";

        $cons = $data['consumption'] ?? ['water' => 0, 'electricity' => 0, 'gas' => 0];
        $prices = $data['prices'] ?? ['water' => 0, 'electricity' => 0, 'gas' => 0];

        $text .= "<b>جزئیات مصرف این ماه</b>\n";
        $text .= "💧 آب: " . round((float)$cons['water'], 1) . " × " . Telegram::formatPrice((float)$prices['water']) . "\n";
        $text .= "⚡ برق: " . round((float)$cons['electricity'], 1) . " × " . Telegram::formatPrice((float)$prices['electricity']) . "\n";
        $text .= "🔥 گاز: " . round((float)$cons['gas'], 1) . " × " . Telegram::formatPrice((float)$prices['gas']) . "\n";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('بروزرسانی 🔄', 'con_forecast')],
            [
                Telegram::inlineButton('پیشنهادها 🧠', 'con_reco'),
                Telegram::inlineButton('Eco Mode ♻️', 'con_apply_eco')
            ],
            [Telegram::inlineButton('تنظیم بودجه 💰', 'con_budget')],
            [Telegram::inlineButton('بازگشت 🔙', 'con_smart')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function showRecommendations(): void
    {
        $engine = new RecommendationEngine();
        $items = $engine->getUnitRecommendations($this->unitId);

        $text = "🧠 <b>پیشنهادهای هوشمند</b>\n\n";
        $buttons = [];

        $i = 1;
        foreach ($items as $item) {
            $title = (string)($item['title'] ?? '');
            $desc = (string)($item['desc'] ?? '');
            $text .= "{$i}) <b>{$title}</b>\n{$desc}\n\n";

            $action = (string)($item['action'] ?? '');
            $actionText = (string)($item['action_text'] ?? 'اقدام');
            if ($action !== '') {
                $buttons[] = [Telegram::inlineButton('✅ ' . $actionText, $action)];
            }
            $i++;
        }

        $buttons[] = [Telegram::inlineButton('بروزرسانی 🔄', 'con_reco')];
        $buttons[] = [Telegram::inlineButton('بازگشت 🔙', 'con_smart')];

        $this->respond($text, Telegram::inlineKeyboard($buttons));
    }

    public function applyAction(string $action): void
    {
        $twin = new DigitalTwinEngine();
        $state = $twin->getState($this->unitId);

        if ($action === 'con_apply_eco') {
            if (!(bool)($state['eco_mode'] ?? false)) {
                $twin->toggleEcoMode($this->unitId);
            }
            $this->showSmartMenu();
            return;
        }

        if ($action === 'con_apply_heat_down') {
            $twin->adjustHeatingTemp($this->unitId, -1);
            $this->showDevicesMenu();
            return;
        }

        if ($action === 'con_apply_lights') {
            $twin->toggleDevice($this->unitId, 'lights_on');
            $this->showDevicesMenu();
            return;
        }

        if ($action === 'con_apply_ac_down') {
            $mode = (string)($state['ac_mode'] ?? 'off');
            $next = match ($mode) {
                'high' => 'medium',
                'medium' => 'low',
                'low' => 'off',
                default => 'off',
            };
            $twin->setAcMode($this->unitId, $next);
            $this->showDevicesMenu();
            return;
        }

        $this->showSmartMenu();
    }

    public function showBudgetMenu(): void
    {
        $twin = new DigitalTwinEngine();
        $state = $twin->getState($this->unitId);
        $budget = (int)($state['monthly_budget_toman'] ?? 1500000);

        $text = "💰 <b>بودجه ماهانه</b>\n\n";
        $text .= "بودجه فعلی: <b>" . Telegram::formatPrice((float)$budget) . "</b>\n\n";
        $text .= "با دکمه‌ها بودجه را تنظیم کنید.";

        $keyboard = Telegram::inlineKeyboard([
            [
                Telegram::inlineButton('➖ ۲۵۰هزار', 'con_budget_adj_-250000'),
                Telegram::inlineButton('➖ ۱۰۰هزار', 'con_budget_adj_-100000')
            ],
            [
                Telegram::inlineButton('➕ ۱۰۰هزار', 'con_budget_adj_100000'),
                Telegram::inlineButton('➕ ۲۵۰هزار', 'con_budget_adj_250000')
            ],
            [Telegram::inlineButton('بروزرسانی 🔄', 'con_budget')],
            [Telegram::inlineButton('بازگشت 🔙', 'con_smart')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function adjustBudget(int $delta): void
    {
        $twin = new DigitalTwinEngine();
        $state = $twin->getState($this->unitId);
        $budget = (int)($state['monthly_budget_toman'] ?? 1500000);
        $budget = max(0, $budget + $delta);
        $twin->setMonthlyBudget($this->unitId, $budget);
        $this->showBudgetMenu();
    }

    public function showSensitivityMenu(): void
    {
        $twin = new DigitalTwinEngine();
        $state = $twin->getState($this->unitId);
        $costSens = (int)($state['cost_sensitivity'] ?? 50);
        $greenSens = (int)($state['green_sensitivity'] ?? 50);

        $text = "🎯 <b>حساسیت‌ها</b>\n\n";
        $text .= "💰 حساسیت اقتصادی: <b>{$costSens}/100</b>\n";
        $text .= "🌍 حساسیت سبز: <b>{$greenSens}/100</b>\n\n";
        $text .= "هرچه عدد بالاتر باشد، Eco Mode اثر بیشتری خواهد داشت.";

        $keyboard = Telegram::inlineKeyboard([
            [
                Telegram::inlineButton('💰 -5', 'con_sens_cost_-5'),
                Telegram::inlineButton('💰 +5', 'con_sens_cost_5')
            ],
            [
                Telegram::inlineButton('🌍 -5', 'con_sens_green_-5'),
                Telegram::inlineButton('🌍 +5', 'con_sens_green_5')
            ],
            [Telegram::inlineButton('بروزرسانی 🔄', 'con_sens')],
            [Telegram::inlineButton('بازگشت 🔙', 'con_smart')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function adjustSensitivity(string $type, int $delta): void
    {
        $twin = new DigitalTwinEngine();
        $state = $twin->getState($this->unitId);
        $cost = (int)($state['cost_sensitivity'] ?? 50);
        $green = (int)($state['green_sensitivity'] ?? 50);

        if ($type === 'cost') {
            $cost = max(0, min(100, $cost + $delta));
        }
        if ($type === 'green') {
            $green = max(0, min(100, $green + $delta));
        }

        $twin->setSensitivities($this->unitId, $cost, $green);
        $this->showSensitivityMenu();
    }

    public function showCarbon(string $period = 'today'): void
    {
        $engine = new CarbonEngine();
        $carbon = $engine->getUnitCarbonBreakdown($this->unitId, $period);
        $forecastMonth = $engine->forecastUnitMonthCarbonKg($this->unitId);
        $targetDaily = $engine->getDailyTargetKg();

        $title = match ($period) {
            'week' => '🌍 ردپای کربنی (۷ روز اخیر)',
            'month' => '🌍 ردپای کربنی (۳۰ روز اخیر)',
            default => '🌍 ردپای کربنی امروز'
        };

        $text = "{$title}\n\n";
        $text .= "⚡ برق: <b>" . round((float)$carbon['electricity_kg'], 2) . "</b> kgCO₂e\n";
        $text .= "🔥 گاز: <b>" . round((float)$carbon['gas_kg'], 2) . "</b> kgCO₂e\n";
        $text .= "💧 آب: <b>" . round((float)$carbon['water_kg'], 3) . "</b> kgCO₂e\n\n";
        $text .= "📌 مجموع: <b>" . round((float)$carbon['total_kg'], 2) . "</b> kgCO₂e\n";

        if ($period === 'today') {
            $status = ((float)$carbon['total_kg'] <= $targetDaily) ? '✅' : '⚠️';
            $text .= "🎯 هدف روزانه: " . round($targetDaily, 2) . " kgCO₂e {$status}\n";
        }

        $text .= "\n🔮 پیش‌بینی کربن پایان ماه: <b>" . round($forecastMonth, 2) . "</b> kgCO₂e\n";

        $dominant = 'electricity';
        $maxVal = (float)$carbon['electricity_kg'];
        foreach (['gas', 'water'] as $m) {
            $v = (float)$carbon[$m . '_kg'];
            if ($v > $maxVal) {
                $maxVal = $v;
                $dominant = $m;
            }
        }

        $tip = match ($dominant) {
            'gas' => "پیشنهاد: ۱ تا ۲ درجه کاهش دمای پکیج/بخاری در ساعات اوج مصرف.",
            'water' => "پیشنهاد: بررسی نشت/مصرف غیرعادی آب و کاهش زمان دوش.",
            default => "پیشنهاد: کاهش مصرف وسایل پرمصرف در ساعات ۱۸ تا ۲۲ و خاموشی هوشمند."
        };
        $text .= "\n🧠 {$tip}";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('بروزرسانی 🔄', 'con_carbon')],
            [
                Telegram::inlineButton('امروز 📅', 'con_carbon'),
                Telegram::inlineButton('هفته 📆', 'con_carbon_week')
            ],
            [Telegram::inlineButton('۳۰ روز اخیر 📈', 'con_carbon_month')],
            [Telegram::inlineButton('بازگشت 🔙', 'con_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show today's consumption
     */
    public function showTodayConsumption(): void
    {
        $consumption = Unit::getCurrentConsumption($this->unitId, 'today');

        // Get yesterday's consumption for comparison
        $yesterday = DB::select(
            "SELECT 
                metric_type,
                SUM(value) as total
             FROM consumption_readings
             WHERE unit_id = ? AND DATE(timestamp) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
             GROUP BY metric_type",
            [$this->unitId]
        );

        $yesterdayData = [];
        foreach ($yesterday as $row) {
            $yesterdayData[$row['metric_type']] = (float)$row['total'];
        }

        $text = "📊 <b>مصرف امروز</b>\n";
        $text .= "<i>" . date('Y/m/d') . "</i>\n";
        $text .= Telegram::hr() . "\n\n";

        foreach (['water' => '💧 آب', 'electricity' => '⚡ برق', 'gas' => '🔥 گاز'] as $type => $label) {
            $today = $consumption[$type];
            $yesterdayValue = $yesterdayData[$type] ?? 0;

            $unit = match ($type) {
                'water' => 'لیتر',
                'electricity' => 'کیلووات',
                'gas' => 'مترمکعب',
                default => ''
            };

            $text .= "{$label}: <b>" . round((float)$today, 1) . "</b> {$unit}\n";

            if ($yesterdayValue > 0) {
                $change = (($today - $yesterdayValue) / $yesterdayValue) * 100;
                $changeIcon = $change > 0 ? '📈' : '📉';
                $changeText = $change > 0 ? '+' : '';
                $changeText .= round($change, 1) . '%';

                if (abs($change) > 20) {
                    $text .= "   نسبت به دیروز: {$changeIcon} <b>{$changeText}</b> ";
                    $text .= $change > 0 ? "⚠️\n" : "✅\n";
                } else {
                    $text .= "   نسبت به دیروز: {$changeIcon} {$changeText}\n";
                }
            }

            $text .= "\n";
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('آمار هفتگی 📈', 'con_weekly'), Telegram::inlineButton('هزینه‌ها 💵', 'con_costs')],
            [Telegram::inlineButton('بازگشت 🔙', 'con_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show weekly statistics
     */
    public function showWeeklyStats(): void
    {
        $weeklyData = DB::select(
            "SELECT 
                metric_type,
                SUM(value) as total,
                AVG(value) as average
             FROM consumption_readings
             WHERE unit_id = ? 
               AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY metric_type",
            [$this->unitId]
        );

        $text = "📈 <b>آمار ۷ روز اخیر</b>\n";
        $text .= Telegram::hr() . "\n\n";

        foreach ($weeklyData as $data) {
            $icon = match ($data['metric_type']) {
                'water' => '💧',
                'electricity' => '⚡',
                'gas' => '🔥',
                default => '•'
            };

            $name = match ($data['metric_type']) {
                'water' => 'آب',
                'electricity' => 'برق',
                'gas' => 'گاز',
                default => $data['metric_type']
            };

            $total = (float)($data['total'] ?? 0);
            $dailyAvg = $total / 7.0;

            $text .= "{$icon} <b>{$name}</b>\n";
            $text .= "   مجموع: <b>" . round($total, 1) . "</b>\n";
            $text .= "   میانگین روزانه (تقریبی): " . round($dailyAvg, 1) . "\n\n";
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('مصرف امروز 💧', 'con_today')],
            [Telegram::inlineButton('بازگشت 🔙', 'con_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show user alerts
     */
    public function showAlerts(): void
    {
        $alerts = DB::select(
            "SELECT * FROM alerts
             WHERE unit_id = ?
             ORDER BY created_at DESC
             LIMIT 10",
            [$this->unitId]
        );

        if (empty($alerts)) {
            $text = "✅ <b>هشدارها</b>\n" . Telegram::hr() . "\n\n";
            $text .= "فعلاً هشداری برای واحد شما ثبت نشده است.";
        } else {
            $text = "🔔 <b>هشدارهای شما</b>\n" . Telegram::hr() . "\n\n";

            foreach ($alerts as $alert) {
                $icon = match ($alert['severity']) {
                    'critical' => '🚨',
                    'warning' => '⚠️',
                    default => 'ℹ️'
                };

                $text .= "{$icon} <b>{$alert['title']}</b>\n";
                $text .= "{$alert['message']}\n";
                $text .= "<i>" . date('H:i - Y/m/d', strtotime($alert['created_at'])) . "</i>\n";
                $text .= Telegram::hr() . "\n\n";
            }

            // Mark as read
            DB::execute(
                "UPDATE alerts SET is_read = 1, read_at = NOW() WHERE unit_id = ?",
                [$this->unitId]
            );
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('🔙 بازگشت', 'con_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    /**
     * Show credit balance and trading options
     */
    public function showCredits(): void
    {
        $creditEngine = new CreditEngine();
        $credits = $creditEngine->getCredits($this->unitId);

        $text = "💳 <b>اعتبار شما</b>\n";
        $text .= Telegram::hr() . "\n\n";

        $totalBalance = 0;
        $hasNegative = false;

        foreach (['water' => '💧 آب', 'electricity' => '⚡ برق', 'gas' => '🔥 گاز'] as $type => $label) {
            $balance = $credits[$type];
            $status = $balance >= 0 ? '✅' : '⚠️';
            $sign = $balance >= 0 ? '+' : '';

            $text .= "{$label}: {$status} <b>{$sign}" . round((float)$balance, 1) . "</b> واحد\n";

            if ($balance < 0) {
                $hasNegative = true;
                $price = $creditEngine->getCreditPrice($type);
                $cost = abs($balance) * $price;
                $text .= "   هزینه تقریبی: " . Telegram::formatPrice($cost) . "\n";
            }

            $text .= "\n";
        }

        if ($hasNegative) {
            $text .= "\n⚠️ <i>برای جلوگیری از جریمه، اعتبار را شارژ کنید.</i>";
        } else {
            $text .= "\n✅ <i>موجودی شما در وضعیت خوب است.</i>";
        }

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('🛒 خرید اعتبار', 'con_buy_credit'), Telegram::inlineButton('💰 فروش اعتبار', 'con_sell_credit')],
            [Telegram::inlineButton('🔙 بازگشت', 'con_home')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function showBuyCreditMenu(): void
    {
        $text = "🛒 <b>خرید اعتبار</b>\n\n";
        $text .= "نوع اعتبار را انتخاب کنید:";

        $keyboard = Telegram::inlineKeyboard([
            [
                Telegram::inlineButton('💧 آب', 'con_buy_metric_water'),
                Telegram::inlineButton('⚡ برق', 'con_buy_metric_electricity'),
                Telegram::inlineButton('🔥 گاز', 'con_buy_metric_gas')
            ],
            [Telegram::inlineButton('🔙 بازگشت', 'con_credits')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function showBuyCreditAmounts(string $metric): void
    {
        $metricName = match ($metric) {
            'water' => 'آب',
            'electricity' => 'برق',
            'gas' => 'گاز',
            default => ''
        };

        if ($metricName === '') {
            $this->respond(
                "نوع اعتبار نامعتبر است.",
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'con_buy_credit')]
                ])
            );
            return;
        }

        $text = "🛒 <b>خرید اعتبار {$metricName}</b>\n\n";
        $text .= "مقدار را انتخاب کنید:";

        $amounts = [10, 25, 50, 100];
        $buttons = [];

        foreach ($amounts as $amount) {
            $buttons[] = Telegram::inlineButton(
                Telegram::persianNumber($amount) . ' واحد',
                'con_buy_confirm_' . $metric . '_' . $amount
            );
        }

        $keyboard = Telegram::inlineKeyboard([
            array_slice($buttons, 0, 2),
            array_slice($buttons, 2, 2),
            [Telegram::inlineButton('🔙 بازگشت', 'con_buy_credit')]
        ]);

        $this->respond($text, $keyboard);
    }

    public function buyCredits(string $metric, float $amount): void
    {
        $allowed = ['water', 'electricity', 'gas'];
        if (!in_array($metric, $allowed, true) || $amount <= 0) {
            $this->respond(
                "درخواست نامعتبر است.",
                Telegram::inlineKeyboard([
                    [Telegram::inlineButton('🔙 بازگشت', 'con_buy_credit')]
                ])
            );
            return;
        }

        $creditEngine = new CreditEngine();
        $price = $creditEngine->getCreditPrice($metric);
        $estimatedCost = $amount * $price;

        $creditEngine->createTransaction(
            null,
            $this->unitId,
            $metric,
            $amount,
            'system_purchase'
        );

        $text = "✅ خرید انجام شد.\n\n";
        $text .= "مقدار: <b>" . Telegram::persianNumber(round($amount, 1)) . "</b> واحد\n";
        $text .= "هزینه تقریبی: <b>" . Telegram::formatPrice($estimatedCost) . "</b>\n\n";
        $text .= "برای مشاهده موجودی جدید، بخش اعتبارات را بررسی کنید.";

        $this->respond(
            $text,
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('💰 مشاهده اعتبارات', 'con_credits')],
                [Telegram::inlineButton('🔙 بازگشت', 'con_home')]
            ])
        );
    }

    public function showSellCreditInfo(): void
    {
        $text = "💰 <b>فروش اعتبار</b>\n\n";
        $text .= "در نسخه فعلی، فروش اعتبار به‌صورت بازار خودکار پیاده‌سازی نشده است.\n";
        $text .= "در حال حاضر می‌توانید از پنل مدیر/ادمین برای مدیریت انتقال اعتبارات استفاده کنید.";

        $this->respond(
            $text,
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('🔙 بازگشت', 'con_credits')]
            ])
        );
    }

    /**
     * Show estimated monthly costs
     */
    public function showCosts(): void
    {
        // Get current month consumption
        $consumption = DB::select(
            "SELECT 
                metric_type,
                SUM(value) as total
             FROM consumption_readings
             WHERE unit_id = ?
               AND YEAR(timestamp) = YEAR(CURDATE())
               AND MONTH(timestamp) = MONTH(CURDATE())
             GROUP BY metric_type",
            [$this->unitId]
        );

        // Get limits and prices
        $limits = DB::select(
            "SELECT metric_type, monthly_limit, price_per_unit
             FROM consumption_limits
             WHERE unit_id = ?
               AND CURDATE() BETWEEN period_start AND period_end",
            [$this->unitId]
        );

        $limitsData = [];
        foreach ($limits as $limit) {
            $limitsData[$limit['metric_type']] = [
                'limit' => (float)$limit['monthly_limit'],
                'price' => (float)$limit['price_per_unit']
            ];
        }

        $text = "💵 <b>هزینه‌های ماه جاری</b>\n";
        $text .= Telegram::hr() . "\n\n";

        $totalCost = 0;

        $consMap = [];
        foreach ($consumption as $cons) {
            $consMap[(string)$cons['metric_type']] = (float)$cons['total'];
        }

        foreach (['water', 'electricity', 'gas'] as $type) {
            $total = (float)($consMap[$type] ?? 0);

            $icon = match ($type) {
                'water' => '💧',
                'electricity' => '⚡',
                'gas' => '🔥',
                default => '•'
            };

            $name = match ($type) {
                'water' => 'آب',
                'electricity' => 'برق',
                'gas' => 'گاز',
                default => $type
            };

            $limitInfo = $limitsData[$type] ?? null;

            if ($limitInfo) {
                $cost = $total * $limitInfo['price'];
                $totalCost += $cost;

                $percent = ($total / $limitInfo['limit']) * 100;

                $bar = Telegram::progressBar($percent / 100, 10);
                $pIcon = $percent <= 70 ? '✅' : ($percent <= 100 ? '⚠️' : '🚨');

                $text .= "{$icon} <b>{$name}</b>\n";
                $text .= "   مصرف: <b>" . round($total, 1) . "</b> / " . round($limitInfo['limit'], 1) . "\n";
                $text .= "   {$pIcon} {$bar} " . round($percent, 1) . "%\n";
                $text .= "   هزینه: <b>" . Telegram::formatPrice($cost) . "</b>\n\n";
            }
        }

        $text .= Telegram::hr() . "\n";
        $text .= "<b>جمع کل: " . Telegram::formatPrice($totalCost) . "</b>";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::inlineButton('🔙 بازگشت', 'con_home')]
        ]);

        $this->respond($text, $keyboard);
    }
}
