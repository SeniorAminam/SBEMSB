<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: index.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Webhook endpoint for Telegram bot
 * Developed by Amin Davodian
 */

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use SmartBuilding\Bot\BotHandler;
use SmartBuilding\Database\DB;
use SmartBuilding\Utils\Logger;
use SmartBuilding\Utils\Telegram;
use SmartBuilding\Services\DataSimulator;
use SmartBuilding\Services\ConsumptionAnalyzer;
use SmartBuilding\Services\CreditEngine;

Logger::init(__DIR__);

try {
    // Load environment variables
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    Logger::init(__DIR__);
} catch (\Throwable $e) {
    Logger::error('dotenv_load_failed', $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Env Error\n");
        exit(1);
    }

    http_response_code(500);
    echo 'Env Error';
    exit;
}

if (PHP_SAPI === 'cli') {
    $args = $argv ?? [];
    $mode = (string)($args[1] ?? '');

    $botToken = (string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
    if ($botToken === '') {
        fwrite(STDERR, "TELEGRAM_BOT_TOKEN not set in .env\n");
        exit(1);
    }

    $closeCurlHandle = static function ($ch): void {
        try {
            if ($ch !== null) {
                call_user_func('curl_close', $ch);
            }
        } catch (\Throwable $e) {
        }
    };

    $ensureLastUpdateIdExists = static function (): void {
        DB::execute(
            "INSERT INTO system_settings (setting_key, setting_value, description)
             VALUES ('runtime_last_update_id', '0', 'Runtime: last processed Telegram update_id')
             ON DUPLICATE KEY UPDATE setting_value = setting_value"
        );
    };

    $shouldProcessUpdate = static function (int $updateId) use ($ensureLastUpdateIdExists): bool {
        if ($updateId <= 0) {
            return true;
        }

        try {
            $ensureLastUpdateIdExists();
            $affected = DB::executeWithRowCount(
                "UPDATE system_settings
                 SET setting_value = ?
                 WHERE setting_key = 'runtime_last_update_id'
                   AND CAST(setting_value AS UNSIGNED) < ?",
                [(string)$updateId, $updateId]
            );

            return $affected === 1;
        } catch (\Throwable $e) {
            Logger::error('update_dedup_failed', $e->getMessage(), ['update_id' => $updateId]);
            return true;
        }
    };

    if ($mode === 'webhook-info') {
        $tg = new Telegram();
        $res = $tg->getWebhookInfo();
        fwrite(STDOUT, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        exit((bool)($res['ok'] ?? false) ? 0 : 1);
    }

    if ($mode === 'webhook-delete') {
        $tg = new Telegram();
        $res = $tg->deleteWebhook(true);
        fwrite(STDOUT, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        exit((bool)($res['ok'] ?? false) ? 0 : 1);
    }

    if ($mode === 'webhook-set') {
        $url = (string)($_ENV['TELEGRAM_WEBHOOK_URL'] ?? '');
        $secret = (string)($_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '');
        if ($url === '') {
            fwrite(STDERR, "TELEGRAM_WEBHOOK_URL is empty in .env\n");
            exit(1);
        }

        $tg = new Telegram();
        $res = $tg->setWebhook($url, $secret);
        fwrite(STDOUT, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        exit((bool)($res['ok'] ?? false) ? 0 : 1);
    }

    if ($mode === 'poll') {
        try {
            $lockRow = DB::select("SELECT GET_LOCK('sbem_poll_lock', 0) as l");
            $locked = (int)($lockRow[0]['l'] ?? 0);
            if ($locked !== 1) {
                fwrite(STDERR, "Another polling process is already running (DB lock).\n");
                exit(1);
            }
        } catch (\Throwable $e) {
            Logger::error('polling_lock_failed', $e->getMessage());
        }

        $offset = 0;
        $apiUrl = "https://api.telegram.org/bot{$botToken}/";

        fwrite(STDOUT, "Polling mode started. Press Ctrl+C to stop.\n");

        while (true) {
            try {
                $url = $apiUrl . 'getUpdates?offset=' . $offset . '&timeout=30';

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 35,
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                $closeCurlHandle($ch);

                if ($response === false) {
                    Logger::error('polling_curl_error', 'curl_exec failed', ['error' => $curlErr]);
                    sleep(3);
                    continue;
                }

                $decoded = json_decode((string)$response, true);
                if ($httpCode !== 200) {
                    $desc = is_array($decoded) ? (string)($decoded['description'] ?? '') : '';
                    Logger::warning('polling_http_error', 'Non-200 response from Telegram', [
                        'http_code' => $httpCode,
                        'description' => $desc,
                    ]);

                    $hint = '';
                    if (stripos($desc, 'Conflict') !== false || $httpCode === 409) {
                        $hint = ' (Hint: webhook is likely set; deleteWebhook is required before polling)';
                    }
                    if (stripos($desc, 'Unauthorized') !== false || $httpCode === 401) {
                        $hint = ' (Hint: bot token is invalid/wrong)';
                    }
                    fwrite(STDERR, "Telegram HTTP {$httpCode}: {$desc}{$hint}\n");

                    sleep(3);
                    continue;
                }

                if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
                    $desc = is_array($decoded) ? (string)($decoded['description'] ?? 'Unknown') : 'Invalid JSON';
                    Logger::warning('polling_api_error', 'Telegram API returned ok=false', ['description' => $desc]);

                    $hint = '';
                    if (stripos($desc, 'Conflict') !== false) {
                        $hint = ' (Hint: webhook is likely set; deleteWebhook is required before polling)';
                    }
                    if (stripos($desc, 'Unauthorized') !== false) {
                        $hint = ' (Hint: bot token is invalid/wrong)';
                    }
                    fwrite(STDERR, "Telegram API error: {$desc}{$hint}\n");

                    sleep(3);
                    continue;
                }

                $updates = $decoded['result'] ?? [];
                if (!is_array($updates)) {
                    $updates = [];
                }

                foreach ($updates as $update) {
                    $updateId = (int)($update['update_id'] ?? 0);
                    if ($updateId > 0) {
                        $offset = $updateId + 1;
                    }

                    if ($updateId > 0 && !$shouldProcessUpdate($updateId)) {
                        continue;
                    }

                    try {
                        $handler = new BotHandler($update);
                        $handler->handle();
                    } catch (\Throwable $e) {
                        Logger::error('polling_handle_failed', $e->getMessage(), ['update_id' => $updateId]);
                    }
                }

                if (empty($updates)) {
                    usleep(300000);
                }
            } catch (\Throwable $e) {
                Logger::error('polling_fatal', $e->getMessage());
                sleep(3);
            }
        }
    }

    if ($mode === 'cron') {
        try {
            $simulator = new DataSimulator();
            $unitsProcessed = $simulator->generateConsumptionData();

            $analyzer = new ConsumptionAnalyzer();
            $alertsCreated = $analyzer->analyzeAll();

            $creditsCalculated = 0;
            $currentHour = (int)date('G');
            if ($currentHour === 0) {
                $creditEngine = new CreditEngine();
                $creditsCalculated = $creditEngine->calculateMonthlyCredits();
            }

            fwrite(STDOUT, "OK | units={$unitsProcessed} | alerts={$alertsCreated} | credits={$creditsCalculated}\n");
            exit(0);
        } catch (\Throwable $e) {
            Logger::error('cron_failed', $e->getMessage());
            fwrite(STDERR, "Error: {$e->getMessage()}\n");
            exit(1);
        }
    }

    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php index.php poll\n");
    fwrite(STDOUT, "  php index.php cron\n");
    fwrite(STDOUT, "  php index.php webhook-info\n");
    fwrite(STDOUT, "  php index.php webhook-set\n");
    fwrite(STDOUT, "  php index.php webhook-delete\n");
    exit(0);
}

// Verify webhook secret (security)
$secretToken = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
$expectedSecret = $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '';

if (!empty($expectedSecret) && $secretToken !== $expectedSecret) {
    Logger::warning('webhook_forbidden', 'Webhook secret mismatch', [
        'received' => $secretToken !== '' ? 'provided' : 'missing',
    ]);
    http_response_code(403);
    exit('Forbidden');
}

// Get update from Telegram
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    http_response_code(400);
    exit('Bad Request');
}

// Log update (for debugging)
if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
    error_log("Telegram Update: " . $content);
}

try {
    $updateId = (int)($update['update_id'] ?? 0);
    if ($updateId > 0) {
        $ensureLastUpdateIdExists = static function (): void {
            DB::execute(
                "INSERT INTO system_settings (setting_key, setting_value, description)
                 VALUES ('runtime_last_update_id', '0', 'Runtime: last processed Telegram update_id')
                 ON DUPLICATE KEY UPDATE setting_value = setting_value"
            );
        };

        $shouldProcessUpdate = static function (int $updateId) use ($ensureLastUpdateIdExists): bool {
            if ($updateId <= 0) {
                return true;
            }

            try {
                $ensureLastUpdateIdExists();
                $affected = DB::executeWithRowCount(
                    "UPDATE system_settings
                     SET setting_value = ?
                     WHERE setting_key = 'runtime_last_update_id'
                       AND CAST(setting_value AS UNSIGNED) < ?",
                    [(string)$updateId, $updateId]
                );
                return $affected === 1;
            } catch (\Throwable $e) {
                Logger::error('update_dedup_failed', $e->getMessage(), ['update_id' => $updateId]);
                return true;
            }
        };

        if (!$shouldProcessUpdate($updateId)) {
            http_response_code(200);
            echo 'OK';
            exit;
        }
    }

    // Handle the update
    $handler = new BotHandler($update);
    $handler->handle();

    http_response_code(200);
    echo 'OK';
} catch (\Exception $e) {
    // Log error
    error_log("Bot Error: " . $e->getMessage());

    http_response_code(500);
    echo 'Error';
}
