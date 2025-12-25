<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: tools/set_webhook.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Helper script to set Telegram webhook
 * Developed by Amin Davodian
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
$webhookUrl = $_ENV['TELEGRAM_WEBHOOK_URL'] ?? '';
$secret = $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '';

$setupKey = $_ENV['TELEGRAM_SETUP_KEY'] ?? '';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $providedKey = (string)($_GET['key'] ?? '');
    if ($setupKey === '' || $providedKey === '' || !hash_equals($setupKey, $providedKey)) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }

    if ($webhookUrl === '') {
        $https = (string)($_SERVER['HTTPS'] ?? '');
        $proto = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $scriptDir = rtrim((string)dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $rootDir = preg_replace('#/tools$#', '', $scriptDir);
        $webhookUrl = $proto . '://' . $host . $rootDir . '/index.php';
    }
}

if (empty($botToken) || empty($webhookUrl)) {
    die("❌ Error: Bot token or webhook URL not configured in .env\n");
}

if (!str_starts_with($webhookUrl, 'https://')) {
    die("❌ Error: TELEGRAM_WEBHOOK_URL must start with https:// (Telegram requires HTTPS)\n");
}

echo "Setting webhook...\n";
echo "URL: {$webhookUrl}\n\n";

$url = "https://api.telegram.org/bot{$botToken}/setWebhook";
$data = [
    'url' => $webhookUrl
];

if (!empty($secret)) {
    $data['secret_token'] = $secret;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

$result = json_decode($response, true);

if ($httpCode === 200 && $result['ok']) {
    echo "✅ Webhook set successfully!\n\n";

    // Get webhook info
    $infoUrl = "https://api.telegram.org/bot{$botToken}/getWebhookInfo";
    $ch = curl_init($infoUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $info = curl_exec($ch);

    $webhookInfo = json_decode($info, true);

    if ($webhookInfo['ok']) {
        echo "Webhook Info:\n";
        echo "URL: " . ($webhookInfo['result']['url'] ?? 'N/A') . "\n";
        echo "Has custom certificate: " . ($webhookInfo['result']['has_custom_certificate'] ? 'Yes' : 'No') . "\n";
        echo "Pending updates: " . ($webhookInfo['result']['pending_update_count'] ?? 0) . "\n";
        echo "Last error: " . ($webhookInfo['result']['last_error_message'] ?? 'None') . "\n";
    }
} else {
    echo "❌ Failed to set webhook\n";
    echo "Response: {$response}\n";
}
