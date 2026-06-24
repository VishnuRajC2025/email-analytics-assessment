<?php

declare(strict_types=1);

/**
 * Simple file-based queue (simulates Kafka/RabbitMQ behavior).
 * 
 * How it works:
 * - API writes events to queue files (instant, no DB wait)
 * - Background worker reads queue files and batch-inserts into MySQL
 * 
 * This decouples the API from the database:
 * - API responds in <1ms (just a file write)
 * - DB work happens asynchronously in the background worker
 * 
 * In production, replace this with Kafka/RabbitMQ for durability and distribution.
 */
class QueueService
{
    private string $queueDir;

    public function __construct()
    {
        $this->queueDir = __DIR__ . '/../queue';
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0777, true);
        }
    }

    /**
     * Enqueue events — writes to a file (instant, no DB involved).
     * Each file contains one batch of events as JSON.
     */
    public function enqueue(array $events): void
    {
        // Unique filename: timestamp + random to avoid collisions
        $filename = sprintf(
            '%s/%s_%s.json',
            $this->queueDir,
            microtime(true) * 10000,
            bin2hex(random_bytes(4))
        );

        file_put_contents($filename, json_encode($events), LOCK_EX);
    }

    /**
     * Dequeue — reads and deletes the oldest batch file.
     * Returns array of events, or null if queue is empty.
     */
    public function dequeue(): ?array
    {
        $files = glob($this->queueDir . '/*.json');
        if (empty($files)) {
            return null;
        }

        // Sort by filename (oldest first since filenames are timestamp-based)
        sort($files);
        $file = $files[0];

        $content = file_get_contents($file);
        unlink($file); // Remove from queue after reading

        return json_decode($content, true);
    }

    /**
     * Get number of pending files in the queue.
     */
    public function size(): int
    {
        $files = glob($this->queueDir . '/*.json');
        return count($files);
    }
}
