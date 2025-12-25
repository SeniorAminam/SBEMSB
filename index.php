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
use SmartBuilding\Utils\Logger;

Logger::init(__DIR__);

try {
    // Load environment variables
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    Logger::init(__DIR__);
} catch (\Throwable $e) {
    Logger::error('dotenv_load_failed', $e->getMessage());
    http_response_code(500);
    echo 'Env Error';
    exit;
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
