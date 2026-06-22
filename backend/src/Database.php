<?php

declare(strict_types=1);

/**
 * Database singleton — provides a shared PDO connection.
 * 
 * Configuration via environment variables:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $name = getenv('DB_NAME') ?: 'email_analytics';
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Persistent connections for connection pooling
                PDO::ATTR_PERSISTENT         => true,
            ]);
        }

        return self::$instance;
    }

    /**
     * Run the migration to create the events table if it doesn't exist.
     */
    public static function migrate(): void
    {
        $pdo = self::getConnection();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS events (
                event_id VARCHAR(255) NOT NULL PRIMARY KEY,
                campaign_id VARCHAR(255) NOT NULL,
                type ENUM('sent', 'opened', 'clicked', 'bounced') NOT NULL,
                timestamp DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_campaign_type (campaign_id, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
