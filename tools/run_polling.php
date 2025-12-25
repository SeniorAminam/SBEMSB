<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: tools/run_polling.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Run bot in polling mode for local testing (no webhook needed)
 * Developed by Amin Davodian
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use SmartBuilding\Bot\BotHandler;
use SmartBuilding\Utils\Logger;

Logger::init(__DIR__ . '/..');

try {
    // Load environment
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
    Logger::init(__DIR__ . '/..');
} catch (\Throwable $e) {
    Logger::error('dotenv_load_failed', $e->getMessage());
    die("❌ Error: .env could not be loaded\n");
}

$botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';

if (empty($botToken)) {
    die("❌ Error: TELEGRAM_BOT_TOKEN not set in .env\n");
}

echo "🤖 Smart Building Bot - Polling Mode\n";
echo "Press Ctrl+C to stop\n\n";

$offset = 0;
$apiUrl = "https://api.telegram.org/bot{$botToken}/";

// Main polling loop
while (true) {
    try {
        // Get updates
        $url = $apiUrl . 'getUpdates?offset=' . $offset . '&timeout=30';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error('polling_curl_error', 'curl_exec failed', ['error' => $curlErr]);
            echo "❌ CURL Error: {$curlErr}\n";
            sleep(5);
            continue;
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            $desc = is_array($decoded) ? (string)($decoded['description'] ?? '') : '';
            Logger::warning('polling_http_error', 'Non-200 response from Telegram', [
                'http_code' => $httpCode,
                'description' => $desc,
            ]);

            echo "⚠️ HTTP Error: {$httpCode}";
            if ($desc !== '') {
                echo " - {$desc}";
            }
            echo "\n";

            if ($httpCode === 409) {
                echo "ℹ️ Hint: معمولاً یعنی یک instance دیگر polling فعال است یا webhook روشن است.\n";
                echo "   - فقط یک polling اجرا کنید\n";
                echo "   - اگر webhook فعال است: deleteWebhook را بزنید\n\n";
            }
            sleep(5);
            continue;
        }

        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            $desc = is_array($decoded) ? (string)($decoded['description'] ?? 'Unknown') : 'Invalid JSON';
            Logger::warning('polling_api_error', 'Telegram API returned ok=false', ['description' => $desc]);
            echo "⚠️ API Error: {$desc}\n";
            sleep(5);
            continue;
        }

        $updates = $decoded['result'];

        // Process each update
        foreach ($updates as $update) {
            $updateId = $update['update_id'];
            $offset = $updateId + 1;

            // Log update
            if (isset($update['message'])) {
                $from = $update['message']['from']['first_name'] ?? 'Unknown';
                $text = $update['message']['text'] ?? '[non-text]';
                echo "📨 Message from {$from}: {$text}\n";
            } elseif (isset($update['callback_query'])) {
                $from = $update['callback_query']['from']['first_name'] ?? 'Unknown';
                $data = $update['callback_query']['data'] ?? '';
                echo "🔘 Callback from {$from}: {$data}\n";
            }

            // Handle update
            try {
                $handler = new BotHandler($update);
                $handler->handle();
                echo "✅ Processed update #{$updateId}\n\n";
            } catch (\Exception $e) {
                echo "❌ Error processing update: " . $e->getMessage() . "\n\n";

                if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                    echo $e->getTraceAsString() . "\n\n";
                }
            }
        }

        // If no updates, just continue
        if (empty($updates)) {
            echo ".";
        }
    } catch (\Exception $e) {
        echo "❌ Fatal error: " . $e->getMessage() . "\n";
        sleep(5);
    }
}
