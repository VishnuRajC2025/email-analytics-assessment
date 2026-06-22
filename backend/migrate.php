<?php

/**
 * Run database migration — creates the events table.
 * Usage: php migrate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

echo "Running migration...\n";

try {
    Database::migrate();
    echo "Migration completed successfully.\n";
} catch (\Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
