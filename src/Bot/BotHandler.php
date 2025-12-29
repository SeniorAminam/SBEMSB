<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Bot/BotHandler.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Main bot handler for processing updates
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Bot;

use SmartBuilding\Utils\Telegram;
use SmartBuilding\Database\DB;
use SmartBuilding\Models\User;
use SmartBuilding\Panels\AdminPanel;
use SmartBuilding\Panels\ManagerPanel;
use SmartBuilding\Panels\ConsumerPanel;
use SmartBuilding\Services\CreditEngine;
use SmartBuilding\Utils\Logger;

class BotHandler
{
    private Telegram $telegram;
    private array $update;

    public function __construct(array $update)
    {
        $this->telegram = new Telegram();
        $this->update = $update;
    }

    /**
     * Process incoming update
     */
    public function handle(): void
    {
        try {
            // Handle callback queries (inline keyboard buttons)
            if (isset($this->update['callback_query'])) {
                $this->handleCallback();
                return;
            }

            // Handle messages
            if (isset($this->update['message'])) {
                $this->handleMessage();
                return;
            }
        } catch (\Throwable $e) {
            Logger::error('update_handle_failed', $e->getMessage(), [
                'has_message' => isset($this->update['message']),
                'has_callback' => isset($this->update['callback_query']),
            ]);

            $chatId = $this->extractChatId();
            if ($chatId !== null) {
                $this->telegram->sendMessage(
                    $chatId,
                    "❌ <b>خطای داخلی ربات</b>\n" .
                    Telegram::hr() . "\n" .
                    "سیستم نتوانست درخواست را پردازش کند.\n\n" .
                    "✅ پیشنهاد سریع برای لوکال:\n" .
                    "- اگر روی WSL اجرا می‌کنی: افزونه <code>pdo_mysql</code> را نصب/فعال کن\n" .
                    "- یا ربات را با PHP داخل XAMPP اجرا کن\n"
                );
            }
        }
    }

    private function extractChatId(): ?int
    {
        $chatId = $this->update['message']['chat']['id'] ?? null;
        if (is_int($chatId) || is_numeric($chatId)) {
            return (int)$chatId;
        }

        $chatId = $this->update['callback_query']['message']['chat']['id'] ?? null;
        if (is_int($chatId) || is_numeric($chatId)) {
            return (int)$chatId;
        }

        return null;
    }

    /**
     * Handle text messages
     */
    private function handleMessage(): void
    {
        $message = $this->update['message'];
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';

        Logger::info('incoming_message', 'Message received', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);

        // Get or create user
        $user = User::findByTelegramId($chatId);

        if (!$user) {
            // New user registration
            $this->registerNewUser($message);
            return;
        }

        // Handle commands
        if (str_starts_with($text, '/')) {
            $this->handleCommand($text, $chatId, $user);
            return;
        }

        // Handle keyboard button presses
        $this->handleKeyboardButton($text, $chatId, $user);
    }

    /**
     * Register new user
     */
    private function registerNewUser(array $message): void
    {
        $chatId = $message['chat']['id'];

        $adminIds = $this->getAdminTelegramIds();

        $existingAdmins = DB::select("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
        $hasAdmin = ((int)($existingAdmins[0]['count'] ?? 0)) > 0;

        $role = 'consumer';
        if (in_array($chatId, $adminIds, true)) {
            $role = 'admin';
        } elseif (!$hasAdmin) {
            $role = 'admin';
        }

        Logger::info('register_user', 'Registering new user', [
            'chat_id' => $chatId,
            'role' => $role,
        ]);

        User::create([
            'telegram_id' => $chatId,
            'username' => $message['from']['username'] ?? null,
            'first_name' => $message['from']['first_name'] ?? 'کاربر',
            'role' => $role
        ]);

        $name = (string)($message['from']['first_name'] ?? '');
        $nameLine = $name !== '' ? "سلام <b>{$name}</b> 👋\n" : "سلام 👋\n";

        $welcomeText = "✨ <b>سامانه مدیریت هوشمند انرژی ساختمان</b>\n";
        $welcomeText .= Telegram::hr() . "\n";
        $welcomeText .= $nameLine;

        if ($role === 'admin') {
            $welcomeText .= "🛡️ نقش شما: <b>مدیر سیستم</b>\n";
            $welcomeText .= Telegram::hr() . "\n";
            $welcomeText .= "برای شروع:\n";
            $welcomeText .= "1) دکمه <b>🏠 منوی اصلی</b>\n";
            $welcomeText .= "2) بخش <b>🧪 ابزارها</b> برای دمو سریع\n";
            $welcomeText .= "3) بخش <b>📈 گزارش</b> برای خروجی جذاب\n";
        } else {
            $welcomeText .= "✅ ثبت‌نام شما انجام شد.\n";
            $welcomeText .= "⏳ وضعیت: <b>در انتظار تخصیص واحد</b>\n";
            $welcomeText .= Telegram::hr() . "\n";
            $welcomeText .= "تا زمان تخصیص، این گزینه‌ها برای شما فعال است:\n";
            $welcomeText .= "• <b>🆔 شناسه من</b>\n";
            $welcomeText .= "• <b>📞 تماس با مدیر</b>\n";
        }

        $this->telegram->sendMessage($chatId, $welcomeText);

        $user = User::findByTelegramId($chatId);
        if ($user) {
            $this->showUserPanel($chatId, $user);
        }
    }

    /**
     * Handle commands
     */
    private function handleCommand(string $command, int $chatId, array $user): void
    {
        $this->showUserPanel($chatId, $user);
    }

    /**
     * Show appropriate panel based on user role
     */
    private function showUserPanel(int $chatId, array $user): void
    {
        $this->ensureAdminExists($chatId, $user);
        $this->autoPromoteIfConfigured($chatId, $user);
        $user = User::findByTelegramId($chatId) ?? $user;

        switch ($user['role']) {
            case 'admin':
                $panel = new AdminPanel($this->telegram, $chatId);
                $panel->showMainMenu();
                break;

            case 'manager':
                if ($user['building_id']) {
                    $panel = new ManagerPanel($this->telegram, $chatId, (int)$user['building_id']);
                    $panel->showMainMenu();
                } else {
                    $this->telegram->sendMessage(
                        $chatId,
                        "شما هنوز به ساختمانی اختصاص نیافته‌اید.",
                        $this->pendingAssignmentKeyboard('manager')
                    );
                }
                break;

            case 'consumer':
                if ($user['unit_id']) {
                    $panel = new ConsumerPanel($this->telegram, $chatId, (int)$user['unit_id']);
                    $panel->showMainMenu();
                } else {
                    $this->telegram->sendMessage(
                        $chatId,
                        "شما هنوز به واحدی اختصاص نیافته‌اید.",
                        $this->pendingAssignmentKeyboard('consumer')
                    );
                }
                break;
        }
    }

    private function ensureAdminExists(int $chatId, array $user): void
    {
        $existingAdmins = DB::select("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
        $hasAdmin = ((int)($existingAdmins[0]['count'] ?? 0)) > 0;

        if ($hasAdmin) {
            return;
        }

        if (($user['role'] ?? '') !== 'admin') {
            User::update((int)$user['id'], ['role' => 'admin']);
            Logger::warning('admin_recovery', 'No admins existed; promoted current user to admin', [
                'chat_id' => $chatId,
                'user_id' => (int)$user['id'],
            ]);
        }
    }

    private function pendingAssignmentKeyboard(string $role): array
    {
        $buttons = [
            [Telegram::keyboardButton('منوی اصلی 🏠')],
            [Telegram::keyboardButton('راهنما 📚')]
        ];

        $buttons[] = [Telegram::keyboardButton('شناسه من 🆔')];

        if ($role === 'consumer') {
            $buttons[] = [Telegram::keyboardButton('تماس با مدیر 📞')];
        }

        return Telegram::replyKeyboard($buttons);
    }

    private function getAdminTelegramIds(): array
    {
        $raw = $_ENV['ADMIN_TELEGRAM_IDS'] ?? '';
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $ids = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $ids[] = (int)$p;
        }

        return array_values(array_unique(array_filter($ids, static fn ($v) => $v > 0)));
    }

    private function autoPromoteIfConfigured(int $chatId, array $user): void
    {
        $adminIds = $this->getAdminTelegramIds();
        if (empty($adminIds)) {
            return;
        }

        if ($user['role'] !== 'admin' && in_array($chatId, $adminIds, true)) {
            User::update((int)$user['id'], ['role' => 'admin']);
            Logger::warning('auto_promote', 'User promoted to admin from env config', [
                'chat_id' => $chatId,
                'user_id' => (int)$user['id'],
            ]);
        }
    }

    /**
     * Handle callback queries
     */
    private function handleCallback(): void
    {
        $callback = $this->update['callback_query'];
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $data = $callback['data'];

        Logger::info('incoming_callback', 'Callback received', [
            'chat_id' => $chatId,
            'data' => $data,
        ]);

        // Answer callback to remove loading state
        $this->telegram->answerCallback($callback['id']);

        // Get user
        $user = User::findByTelegramId($chatId);

        if (!$user) {
            return;
        }

        // Route callback to appropriate panel
        $this->routeCallback($data, $chatId, $messageId, $user);
    }

    /**
     * Route callback to appropriate handler
     */
    private function routeCallback(string $data, int $chatId, int $messageId, array $user): void
    {
        // Admin callbacks
        if (str_starts_with($data, 'admin_')) {
            if (($user['role'] ?? '') !== 'admin') {
                $this->telegram->sendMessage($chatId, "⛔️ شما دسترسی به پنل مدیریت ندارید.");
                $this->showUserPanel($chatId, $user);
                return;
            }

            $panel = new AdminPanel($this->telegram, $chatId, $messageId);

            if (str_starts_with($data, 'admin_user_role_')) {
                $payload = substr($data, strlen('admin_user_role_'));
                $parts = explode('_', $payload, 2);
                $userId = (int)($parts[0] ?? 0);
                $role = (string)($parts[1] ?? '');
                if ($userId > 0 && $role !== '') {
                    $panel->setUserRole($userId, $role);
                }
                return;
            }

            if (str_starts_with($data, 'admin_user_')) {
                $userId = (int)substr($data, strlen('admin_user_'));
                if ($userId > 0) {
                    $panel->showUserDetails($userId);
                }
                return;
            }

            if (str_starts_with($data, 'admin_mgr_assign_')) {
                $userId = (int)substr($data, strlen('admin_mgr_assign_'));
                if ($userId > 0) {
                    $panel->showManagerBuildingSelect($userId);
                }
                return;
            }

            if (str_starts_with($data, 'admin_mgr_set_')) {
                $payload = substr($data, strlen('admin_mgr_set_'));
                $parts = explode('_', $payload, 2);
                $userId = (int)($parts[0] ?? 0);
                $buildingId = (int)($parts[1] ?? 0);
                if ($userId > 0 && $buildingId > 0) {
                    $panel->assignManagerToBuilding($userId, $buildingId);
                }
                return;
            }

            if (str_starts_with($data, 'admin_mgr_clear_')) {
                $userId = (int)substr($data, strlen('admin_mgr_clear_'));
                if ($userId > 0) {
                    $panel->clearManagerAssignment($userId);
                }
                return;
            }

            if (str_starts_with($data, 'admin_con_assign_')) {
                $userId = (int)substr($data, strlen('admin_con_assign_'));
                if ($userId > 0) {
                    $panel->showConsumerBuildingSelect($userId);
                }
                return;
            }

            if (str_starts_with($data, 'admin_con_build_')) {
                $payload = substr($data, strlen('admin_con_build_'));
                $parts = explode('_', $payload, 2);
                $userId = (int)($parts[0] ?? 0);
                $buildingId = (int)($parts[1] ?? 0);
                if ($userId > 0 && $buildingId > 0) {
                    $panel->showConsumerUnitSelect($userId, $buildingId);
                }
                return;
            }

            if (str_starts_with($data, 'admin_con_set_')) {
                $payload = substr($data, strlen('admin_con_set_'));
                $parts = explode('_', $payload, 2);
                $userId = (int)($parts[0] ?? 0);
                $unitId = (int)($parts[1] ?? 0);
                if ($userId > 0 && $unitId > 0) {
                    $panel->assignConsumerToUnit($userId, $unitId);
                }
                return;
            }

            if (str_starts_with($data, 'admin_con_clear_')) {
                $userId = (int)substr($data, strlen('admin_con_clear_'));
                if ($userId > 0) {
                    $panel->clearConsumerAssignment($userId);
                }
                return;
            }

            if (str_starts_with($data, 'admin_build_mgr_')) {
                $buildingId = (int)substr($data, strlen('admin_build_mgr_'));
                if ($buildingId > 0) {
                    $panel->showBuildingManagerSelect($buildingId);
                }
                return;
            }

            if (str_starts_with($data, 'admin_price_edit_')) {
                $metric = substr($data, strlen('admin_price_edit_'));
                if ($metric !== '') {
                    $panel->showPriceEditMenu($metric);
                }
                return;
            }

            if (str_starts_with($data, 'admin_price_adj_')) {
                $payload = substr($data, strlen('admin_price_adj_'));
                $parts = explode('_', $payload, 2);
                $metric = (string)($parts[0] ?? '');
                $delta = (int)($parts[1] ?? 0);
                if ($metric !== '' && $delta !== 0) {
                    $panel->adjustPrice($metric, $delta);
                }
                return;
            }

            if (str_starts_with($data, 'admin_building_')) {
                $buildingId = (int)substr($data, strlen('admin_building_'));
                $panel->showBuildingDetails($buildingId);
                return;
            }

            if ($data === 'admin_mark_all_alerts_read') {
                $panel->markAllAlertsRead();
                $panel->showAlerts();
                return;
            }

            match ($data) {
                'admin_home' => $panel->showMainMenu(),
                'admin_buildings' => $panel->showBuildings(),
                'admin_add_building' => $panel->showAddBuilding(),
                'admin_users' => $panel->showUsers(),
                'admin_list_admins' => $panel->showAdminsList(),
                'admin_list_managers' => $panel->showManagersList(),
                'admin_list_consumers' => $panel->showConsumersList(),
                'admin_unassigned_consumers' => $panel->showUnassignedConsumers(),
                'admin_unassigned_managers' => $panel->showUnassignedManagers(),
                'admin_prices' => $panel->showPriceSettings(),
                'admin_settings' => $panel->showSettings(),
                'admin_report' => $panel->showSystemReport(),
                'admin_alerts' => $panel->showAlerts(),
                'admin_carbon' => $panel->showSystemCarbon('today'),
                'admin_carbon_week' => $panel->showSystemCarbon('week'),
                'admin_carbon_month' => $panel->showSystemCarbon('month'),
                'admin_tools' => $panel->showToolsMenu(),
                'admin_webhook_menu' => $panel->showWebhookMenu(),
                'admin_webhook_info' => $panel->webhookInfo(),
                'admin_webhook_set' => $panel->webhookSetFromEnv(),
                'admin_webhook_delete' => $panel->webhookDelete(),
                'admin_tools_seed' => $panel->showSeedMenu(),
                'admin_tools_seed_safe' => $panel->seedSampleData(false),
                'admin_tools_seed_reset_confirm' => $panel->showSeedResetConfirm(),
                'admin_tools_seed_reset_run' => $panel->seedSampleData(true),
                'admin_tools_reset_all_confirm' => $panel->showResetAllConfirm(),
                'admin_tools_reset_all_run' => $panel->resetAllData(),
                'admin_tools_presets' => $panel->showSimulationPresetsMenu(),
                'admin_tools_preset_guest' => $panel->simulatePresetGuest(),
                'admin_tools_preset_high' => $panel->simulatePresetHigh(),
                'admin_tools_preset_low' => $panel->simulatePresetLow(),
                'admin_tools_preset_reset' => $panel->resetSimulationPreset(),
                'admin_tools_simulate' => $panel->simulateSystemNow(),
                'admin_tools_db_status' => $panel->showDbStatus(),
                'admin_tools_reward_low' => $panel->rewardLowConsumers(),
                'admin_refresh_credits' => $this->refreshCredits($chatId, $messageId),
                default => $panel->showMainMenu()
            };

            return;
        }

        // Manager callbacks
        if (str_starts_with($data, 'mgr_')) {
            if (!$user['building_id']) {
                return;
            }

            $panel = new ManagerPanel($this->telegram, $chatId, (int)$user['building_id'], $messageId);

            if (str_starts_with($data, 'mgr_unit_')) {
                $unitId = (int)substr($data, strlen('mgr_unit_'));
                $panel->showUnitDetails($unitId);
                return;
            }

            if ($data === 'mgr_mark_all_read') {
                $panel->markAllAlertsRead();
                $panel->showAlerts();
                return;
            }

            match ($data) {
                'mgr_home' => $panel->showMainMenu(),
                'mgr_units' => $panel->showUnits(),
                'mgr_live_consumption' => $panel->showLiveConsumption(),
                'mgr_alerts' => $panel->showAlerts(),
                'mgr_credits' => $panel->showCreditsManagement(),
                'mgr_carbon' => $panel->showBuildingCarbon('today'),
                'mgr_carbon_week' => $panel->showBuildingCarbon('week'),
                'mgr_carbon_month' => $panel->showBuildingCarbon('month'),
                'mgr_sim_now' => $panel->simulateNow(),
                'mgr_recalculate_credits' => $this->recalculateCredits($chatId, $messageId, (int)$user['building_id']),
                default => null
            };

            return;
        }

        // Consumer callbacks
        if (str_starts_with($data, 'con_')) {
            if (!$user['unit_id']) {
                return;
            }

            $panel = new ConsumerPanel($this->telegram, $chatId, (int)$user['unit_id'], $messageId);

            if ($data === 'con_smart') {
                $panel->showSmartMenu();
                return;
            }

            if ($data === 'con_scn') {
                $panel->showScenarioMenu();
                return;
            }

            if (str_starts_with($data, 'con_scn_set_')) {
                $scenario = substr($data, strlen('con_scn_set_'));
                $panel->setScenario($scenario);
                return;
            }

            if ($data === 'con_season') {
                $panel->showSeasonMenu();
                return;
            }

            if (str_starts_with($data, 'con_season_set_')) {
                $season = substr($data, strlen('con_season_set_'));
                $panel->setSeason($season);
                return;
            }

            if ($data === 'con_devices') {
                $panel->showDevicesMenu();
                return;
            }

            if ($data === 'con_eco') {
                $panel->toggleEcoMode();
                return;
            }

            if ($data === 'con_sim_now') {
                $panel->simulateNow();
                return;
            }

            if ($data === 'con_forecast') {
                $panel->showForecast();
                return;
            }

            if ($data === 'con_reco') {
                $panel->showRecommendations();
                return;
            }

            if (str_starts_with($data, 'con_apply_')) {
                $panel->applyAction($data);
                return;
            }

            if ($data === 'con_budget') {
                $panel->showBudgetMenu();
                return;
            }

            if (str_starts_with($data, 'con_budget_adj_')) {
                $delta = (int)substr($data, strlen('con_budget_adj_'));
                $panel->adjustBudget($delta);
                return;
            }

            if ($data === 'con_sens') {
                $panel->showSensitivityMenu();
                return;
            }

            if (str_starts_with($data, 'con_sens_cost_')) {
                $delta = (int)substr($data, strlen('con_sens_cost_'));
                $panel->adjustSensitivity('cost', $delta);
                return;
            }

            if (str_starts_with($data, 'con_sens_green_')) {
                $delta = (int)substr($data, strlen('con_sens_green_'));
                $panel->adjustSensitivity('green', $delta);
                return;
            }

            if ($data === 'con_dev_toggle_lights') {
                $panel->toggleDevice('lights_on');
                return;
            }

            if ($data === 'con_dev_toggle_wh') {
                $panel->toggleDevice('water_heater_on');
                return;
            }

            if (str_starts_with($data, 'con_dev_ac_')) {
                $mode = substr($data, strlen('con_dev_ac_'));
                $panel->setAcMode($mode);
                return;
            }

            if (str_starts_with($data, 'con_dev_heat_')) {
                $delta = (int)substr($data, strlen('con_dev_heat_'));
                $panel->adjustHeatingTemp($delta);
                return;
            }

            if ($data === 'con_buy_credit') {
                $panel->showBuyCreditMenu();
                return;
            }

            if (str_starts_with($data, 'con_buy_metric_')) {
                $metric = substr($data, strlen('con_buy_metric_'));
                $panel->showBuyCreditAmounts($metric);
                return;
            }

            if (str_starts_with($data, 'con_buy_confirm_')) {
                $payload = substr($data, strlen('con_buy_confirm_'));
                $parts = explode('_', $payload, 2);
                $metric = $parts[0] ?? '';
                $amount = isset($parts[1]) ? (float)$parts[1] : 0.0;
                $panel->buyCredits($metric, $amount);
                return;
            }

            if ($data === 'con_sell_credit') {
                $panel->showSellCreditInfo();
                return;
            }

            match ($data) {
                'con_home' => $panel->showMainMenu(),
                'con_today' => $panel->showTodayConsumption(),
                'con_weekly' => $panel->showWeeklyStats(),
                'con_alerts' => $panel->showAlerts(),
                'con_credits' => $panel->showCredits(),
                'con_costs' => $panel->showCosts(),
                'con_carbon' => $panel->showCarbon('today'),
                'con_carbon_week' => $panel->showCarbon('week'),
                'con_carbon_month' => $panel->showCarbon('month'),
                default => null
            };

            return;
        }
    }

    /**
     * Refresh all credits
     */
    private function refreshCredits(int $chatId, int $messageId): void
    {
        $creditEngine = new CreditEngine();
        $count = $creditEngine->calculateMonthlyCredits();

        $this->telegram->editMessage(
            $chatId,
            $messageId,
            "✅ <b>بروزرسانی اعتبارات انجام شد</b>\n" .
                Telegram::hr() . "\n" .
                "📌 تعداد موارد پردازش‌شده: <b>{$count}</b>",
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('🔄 بروزرسانی مجدد', 'admin_refresh_credits')],
                [Telegram::inlineButton('🔙 بازگشت', 'admin_home')]
            ])
        );
    }

    /**
     * Recalculate credits for building
     */
    private function recalculateCredits(int $chatId, int $messageId, int $buildingId): void
    {
        $creditEngine = new CreditEngine();
        $units = DB::select("SELECT id FROM units WHERE building_id = ? AND is_active = 1", [$buildingId]);

        foreach ($units as $unit) {
            $creditEngine->calculateUnitCredits((int)$unit['id']);
        }

        $this->telegram->editMessage(
            $chatId,
            $messageId,
            "✅ <b>محاسبه اعتبارات ساختمان انجام شد</b>\n" . Telegram::hr(),
            Telegram::inlineKeyboard([
                [Telegram::inlineButton('💰 مدیریت اعتبارات', 'mgr_credits')],
                [Telegram::inlineButton('🔙 بازگشت', 'mgr_home')]
            ])
        );
    }

    /**
     * Show help
     */
    private function showHelp(int $chatId): void
    {
        $text = "📚 <b>راهنمای سریع</b>\n";
        $text .= Telegram::hr() . "\n";
        $text .= "این ربات با دو نوع دکمه کار می‌کند:\n";
        $text .= "1) <b>کیبورد پایین صفحه</b> (دکمه‌های ثابت)\n";
        $text .= "2) <b>دکمه‌های داخل پیام</b> (شیشه‌ای/Inline)\n";
        $text .= Telegram::hr() . "\n";
        $text .= "نکته‌ها:\n";
        $text .= "• برای برگشت همیشه <b>بازگشت 🔙</b> را بزنید.\n";
        $text .= "• اگر منو را نمی‌بینید، دکمه <b>منوی اصلی 🏠</b> را بزنید.\n";
        $text .= "• اگر تخصیص ندارید، ابتدا مدیر سیستم باید شما را به ساختمان/واحد وصل کند.";

        $this->telegram->sendMessage($chatId, $text);
    }

    private function showAdminContacts(int $chatId): void
    {
        $admins = DB::select(
            "SELECT first_name, username FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY created_at ASC LIMIT 10"
        );

        $text = "📞 <b>تماس با مدیر سیستم</b>\n";
        $text .= Telegram::hr() . "\n\n";

        if (empty($admins)) {
            $text .= "در حال حاضر مدیری ثبت نشده است.";
        } else {
            $text .= "مدیران ثبت‌شده:\n\n";
            foreach ($admins as $admin) {
                $name = $admin['first_name'] ?? 'Admin';
                $username = $admin['username'] ?? null;
                if ($username) {
                    $text .= "• {$name} (@{$username})\n";
                } else {
                    $text .= "• {$name}\n";
                }
            }
        }

        $this->telegram->sendMessage($chatId, $text, $this->pendingAssignmentKeyboard('consumer'));
    }

    /**
     * Handle keyboard button presses
     */
    private function handleKeyboardButton(string $text, int $chatId, array $user): void
    {
        if ($text === '📚 راهنما' || $text === 'راهنما 📚') {
            $this->showHelp($chatId);
            return;
        }

        if ($text === '🆔 شناسه من' || $text === 'شناسه من 🆔') {
            $this->telegram->sendMessage(
                $chatId,
                "🆔 شناسه تلگرام شما: <code>{$chatId}</code>"
            );
            return;
        }

        if ($text === '📞 تماس با مدیر' || $text === 'تماس با مدیر 📞') {
            $this->showAdminContacts($chatId);
            return;
        }

        // Route based on user role and button text
        if ($user['role'] === 'admin') {
            $this->handleAdminKeyboard($text, $chatId);
        } elseif ($user['role'] === 'manager' && $user['building_id']) {
            $this->handleManagerKeyboard($text, $chatId, (int)$user['building_id']);
        } elseif ($user['role'] === 'consumer' && $user['unit_id']) {
            $this->handleConsumerKeyboard($text, $chatId, (int)$user['unit_id']);
        } else {
            // Default: show panel
            $this->showUserPanel($chatId, $user);
        }
    }

    /**
     * Handle admin keyboard buttons
     */
    private function handleAdminKeyboard(string $text, int $chatId): void
    {
        $panel = new AdminPanel($this->telegram, $chatId);

        match ($text) {
            '🏢 ساختمان‌ها', 'ساختمان‌ها 🏢' => $panel->showBuildings(),
            '👥 کاربران', 'کاربران 👥' => $panel->showUsers(),
            '💲 قیمت‌ها', 'قیمت‌ها 💲' => $panel->showPriceSettings(),
            '📈 گزارش', 'گزارش 📈' => $panel->showSystemReport(),
            '⚠️ هشدارها', 'هشدارها ⚠️' => $panel->showAlerts(),
            '🌍 کربن', 'کربن 🌍' => $panel->showSystemCarbon('today'),
            '⚙️ تنظیمات', 'تنظیمات ⚙️' => $panel->showSettings(),
            '🧪 ابزارها', 'ابزارها 🧪' => $panel->showToolsMenu(),
            '🏠 منوی اصلی', 'منوی اصلی 🏠' => $panel->showMainMenu(),
            default => $panel->showMainMenu()
        };
    }

    /**
     * Handle manager keyboard buttons
     */
    private function handleManagerKeyboard(string $text, int $chatId, int $buildingId): void
    {
        $panel = new ManagerPanel($this->telegram, $chatId, $buildingId);

        match ($text) {
            '🏠 واحدها', 'واحدها 🏠' => $panel->showUnits(),
            '📊 مصرف لحظه‌ای', 'مصرف لحظه‌ای 📊' => $panel->showLiveConsumption(),
            '🌍 کربن', 'کربن 🌍' => $panel->showBuildingCarbon('today'),
            '⚠️ هشدارها', 'هشدارها ⚠️' => $panel->showAlerts(),
            '💰 اعتبارات', 'اعتبارات 💰' => $panel->showCreditsManagement(),
            '🧪 شبیه‌سازی', 'شبیه‌سازی 🧪', '🧪 شبیه سازی', 'شبیه سازی 🧪' => $panel->simulateNow(),
            '🏠 منوی اصلی', 'منوی اصلی 🏠' => $panel->showMainMenu(),
            default => $panel->showMainMenu()
        };
    }

    /**
     * Handle consumer keyboard buttons
     */
    private function handleConsumerKeyboard(string $text, int $chatId, int $unitId): void
    {
        $panel = new ConsumerPanel($this->telegram, $chatId, $unitId);

        match ($text) {
            '📊 مصرف امروز', 'مصرف امروز 📊' => $panel->showTodayConsumption(),
            '📈 آمار هفتگی', 'آمار هفتگی 📈' => $panel->showWeeklyStats(),
            '🌍 کربن', 'کربن 🌍' => $panel->showCarbon('today'),
            '🎛 مدیریت هوشمند', 'مدیریت هوشمند 🎛' => $panel->showSmartMenu(),
            '⚠️ هشدارها', 'هشدارها ⚠️' => $panel->showAlerts(),
            '💰 اعتبارات', 'اعتبارات 💰' => $panel->showCredits(),
            '💵 هزینه‌ها', 'هزینه‌ها 💵', '💵 هزینه ها', 'هزینه ها 💵' => $panel->showCosts(),
            '🏠 منوی اصلی', 'منوی اصلی 🏠' => $panel->showMainMenu(),
            default => $panel->showMainMenu()
        };
    }
}
