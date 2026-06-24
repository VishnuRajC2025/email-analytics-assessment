<?php

/**
 * Background Queue Worker
 * 
 * This runs as a separate process. It continuously:
 * 1. Reads events from the queue
 * 2. Batch-inserts them into MySQL
 * 3. Updates campaign_stats counters
 * 
 * Usage: php worker/process_queue.php
 * 
 * In production this would be:
 * - A Kafka consumer
 * - Running as a systemd service
 * - Multiple instances for parallelism
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/QueueService.php';

echo "🔄 Queue Worker started. Waiting for events...\n";

$queue = new QueueService();
$processedTotal = 0;

// Run forever (like a Kafka consumer)
while (true) {
    $events = $queue->dequeue();

    if ($events === null) {
        // Queue empty — sleep briefly then check again
        usleep(100000); // 100ms
        continue;
    }

    $count = count($events);
    
    try {
        $pdo = Database::getConnection();

        // --- Step 1: Batch INSERT IGNORE into events table ---
        $placeholders = implode(',', array_fill(0, $count, '(?,?,?,?)'));
        $sql = "INSERT IGNORE INTO events (event_id, campaign_id, type, timestamp) VALUES {$placeholders}";

        $params = [];
        foreach ($events as $event) {
            $params[] = $event['event_id'];
            $params[] = $event['campaign_id'];
            $params[] = $event['type'];
            $params[] = $event['timestamp'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inserted = $stmt->rowCount();

        // --- Step 2: Update campaign_stats counters ---
        if ($inserted > 0) {
            // Count per campaign per type
            $counters = [];
            foreach ($events as $event) {
                $cid = $event['campaign_id'];
                $type = $event['type'];
                if (!isset($counters[$cid])) {
                    $counters[$cid] = ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0];
                }
                $counters[$cid][$type]++;
            }

            // Update each campaign's stats
            foreach ($counters as $cid => $counts) {
                $stmt = $pdo->prepare(
                    "INSERT INTO campaign_stats (campaign_id, sent, opened, clicked, bounced) 
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE 
                        sent = sent + VALUES(sent),
                        opened = opened + VALUES(opened),
                        clicked = clicked + VALUES(clicked),
                        bounced = bounced + VALUES(bounced)"
                );
                $stmt->execute([$cid, $counts['sent'], $counts['opened'], $counts['clicked'], $counts['bounced']]);
            }
        }

        $processedTotal += $count;
        echo "  ✅ Processed {$count} events ({$inserted} new, " . ($count - $inserted) . " duplicates). Total: {$processedTotal}\n";

    } catch (\PDOException $e) {
        // If DB fails, log error but don't lose events
        // In production: put back in queue or dead-letter queue
        error_log("Worker DB error: " . $e->getMessage());
        echo "  ❌ DB error: " . $e->getMessage() . "\n";
    }
}
