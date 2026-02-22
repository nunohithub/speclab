<?php
/**
 * SpecLab - Sistema de Logging Estruturado
 * Escreve logs com timestamp, nível, user e IP para ficheiros diários.
 */

class AppLogger {
    private static ?string $logFile = null;

    private static function getLogFile(): string {
        if (self::$logFile === null) {
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            self::$logFile = $logDir . '/app_' . date('Y-m-d') . '.log';
        }
        return self::$logFile;
    }

    public static function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? 'anonymous';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line = "[$timestamp] [$level] [user:$userId] [ip:$ip] $message$contextStr\n";
        @file_put_contents(self::getLogFile(), $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $msg, array $ctx = []): void { self::log('ERROR', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void { self::log('WARNING', $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void { self::log('INFO', $msg, $ctx); }
    public static function security(string $msg, array $ctx = []): void { self::log('SECURITY', $msg, $ctx); }
}
