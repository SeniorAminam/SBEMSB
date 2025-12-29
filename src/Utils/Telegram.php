<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Utils/Telegram.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Telegram Bot API wrapper for sending messages and handling callbacks
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Utils;

class Telegram
{
    private string $botToken;
    private string $apiUrl;

    public function __construct()
    {
        $this->botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        $this->apiUrl = $this->botToken !== ''
            ? "https://api.telegram.org/bot{$this->botToken}/"
            : '';
    }

    /**
     * Send a message to user
     */
    public function sendMessage(
        int $chatId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = 'HTML'
    ): array {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];

        if ($replyMarkup !== null) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->apiRequest('sendMessage', $data);
    }

    /**
     * Edit existing message
     */
    public function editMessage(
        int $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null
    ): array {
        $data = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($replyMarkup !== null) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->apiRequest('editMessageText', $data);
    }

    /**
     * Answer callback query
     */
    public function answerCallback(string $callbackId, string $text = '', bool $showAlert = false): array
    {
        return $this->apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $showAlert
        ]);
    }

    /**
     * Set webhook
     */
    public function setWebhook(string $url, string $secret = ''): array
    {
        $data = ['url' => $url];

        if (!empty($secret)) {
            $data['secret_token'] = $secret;
        }

        return $this->apiRequest('setWebhook', $data);
    }

    public function deleteWebhook(bool $dropPendingUpdates = true): array
    {
        return $this->apiRequest('deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    public function getWebhookInfo(): array
    {
        return $this->apiRequest('getWebhookInfo');
    }

    /**
     * Create inline keyboard
     */
    public static function inlineKeyboard(array $buttons): array
    {
        return ['inline_keyboard' => $buttons];
    }

    /**
     * Create inline button
     */
    public static function inlineButton(string $text, string $callbackData): array
    {
        return ['text' => $text, 'callback_data' => $callbackData];
    }

    /**
     * Create reply keyboard (persistent keyboard buttons)
     */
    public static function replyKeyboard(array $buttons, bool $resize = true, bool $oneTime = false): array
    {
        return [
            'keyboard' => $buttons,
            'resize_keyboard' => $resize,
            'one_time_keyboard' => $oneTime
        ];
    }

    /**
     * Create keyboard button
     */
    public static function keyboardButton(string $text): array
    {
        return ['text' => $text];
    }

    /**
     * Remove keyboard
     */
    public static function removeKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }

    public static function hr(): string
    {
        return "━━━━━━━━━━━━━━━━━━";
    }

    public static function progressBar(float $ratio, int $width = 10): string
    {
        $ratio = max(0.0, min(1.0, $ratio));
        $filled = (int)round($ratio * $width);
        $filled = max(0, min($width, $filled));

        return str_repeat('▰', $filled) . str_repeat('▱', $width - $filled);
    }

    /**
     * Execute API request
     */
    private function apiRequest(string $method, array $data = []): array
    {
        if ($this->apiUrl === '') {
            error_log('Telegram API Error: TELEGRAM_BOT_TOKEN is not configured');
            return ['ok' => false];
        }

        $ch = curl_init($this->apiUrl . $method);
        if ($ch === false) {
            error_log("Telegram API Error: {$method} - Failed to initialize cURL");
            return ['ok' => false];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            $this->closeCurlHandle($ch);
            error_log("Telegram API Error: {$method} - {$error}");
            return ['ok' => false];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->closeCurlHandle($ch);

        if ($httpCode !== 200) {
            error_log("Telegram API Error: {$method} - HTTP {$httpCode}");
            return ['ok' => false];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            error_log("Telegram API Error: {$method} - Invalid JSON response");
            return ['ok' => false];
        }

        if (($decoded['ok'] ?? true) !== true) {
            $code = (string)($decoded['error_code'] ?? '');
            $desc = (string)($decoded['description'] ?? 'Unknown');
            error_log("Telegram API Error: {$method} - {$code} {$desc}");
        }

        return $decoded;
    }

    private function closeCurlHandle($ch): void
    {
        try {
            if ($ch !== null) {
                call_user_func('curl_close', $ch);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Format number with Persian digits
     */
    public static function persianNumber($number): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($english, $persian, (string)$number);
    }

    /**
     * Format price with thousands separator
     */
    public static function formatPrice(float $price): string
    {
        return number_format($price, 0, '.', ',') . ' تومان';
    }
}
