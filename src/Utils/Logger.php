<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Utils/Logger.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-25
 * 
 * Purpose: Simple file-based logger for local debugging and production troubleshooting
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Utils;

final class Logger
{
    private static bool $initialized = false;
    private static string $logFile = '';

    public static function init(string $projectRoot): void
    {
        $configured = $_ENV['LOG_FILE'] ?? '';
        $logFile = $configured !== ''
            ? $configured
            : rtrim($projectRoot, "\\/ ") . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'bot.log';

        if (self::$initialized && self::$logFile === $logFile) {
            return;
        }

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            if (@mkdir($dir, 0777, true) === false) {
                $logFile = rtrim(sys_get_temp_dir(), "\\/ ") . DIRECTORY_SEPARATOR . 'smart_building_bot.log';
                $dir = dirname($logFile);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
            }
        }

        if (!is_writable($dir)) {
            $logFile = rtrim(sys_get_temp_dir(), "\\/ ") . DIRECTORY_SEPARATOR . 'smart_building_bot.log';
            $dir = dirname($logFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }

        if (!file_exists($logFile)) {
            @touch($logFile);
        }

        if (file_exists($logFile) && !is_writable($logFile)) {
            $logFile = rtrim(sys_get_temp_dir(), "\\/ ") . DIRECTORY_SEPARATOR . 'smart_building_bot.log';
            if (!file_exists($logFile)) {
                @touch($logFile);
            }
        }

        self::$logFile = $logFile;

        @ini_set('log_errors', '1');
        @ini_set('error_log', $logFile);

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            self::error('php_error', $message, ['severity' => $severity, 'file' => $file, 'line' => $line]);
            return false;
        });

        set_exception_handler(static function (\Throwable $e): void {
            self::error('uncaught_exception', $e->getMessage(), [
                'type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        });

        self::$initialized = true;
        self::info('logger_initialized', 'Logger initialized', ['log_file' => self::$logFile]);
    }

    public static function info(string $event, string $message, array $context = []): void
    {
        self::write('INFO', $event, $message, $context);
    }

    public static function warning(string $event, string $message, array $context = []): void
    {
        self::write('WARN', $event, $message, $context);
    }

    public static function error(string $event, string $message, array $context = []): void
    {
        self::write('ERROR', $event, $message, $context);
    }

    private static function write(string $level, string $event, string $message, array $context): void
    {
        if (self::$logFile === '') {
            error_log("{$level} {$event}: {$message}");
            return;
        }

        $payload = [
            'ts' => date('Y-m-d H:i:s'),
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
        ];

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = date('Y-m-d H:i:s') . " {$level} {$event}: {$message}";
        }

        $ok = @file_put_contents(self::$logFile, $line . PHP_EOL, FILE_APPEND);
        if ($ok === false) {
            error_log($line);
        }
    }
}
