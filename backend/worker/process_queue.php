<?php

/**
 * Kafka Consumer Worker
 * 
 * Runs as a background process. Continuously consumes events from
 * the 'email-events' Kafka topic and batch-inserts into MySQL.
 * 
 * Usage: php worker/process_queue.php
 * 
 * This is a Kafka consumer — it:
 * - Connects to Kafka broker
 * - Subscribes to the 'email-events' topic
 * - Reads messages (batches of events)
 * - Inserts into MySQL with INSERT IGNORE (dedup)
 * - Updates campaign_stats counters
 * - Commits the offset (tells Kafka "I processed this")
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';

use longlang\phpkafka\Consumer\Consumer;
use longlang\phpkafka\Consumer\ConsumerConfig;

echo "🔄 Kafka Consumer Worker started.\n";
echo "   Broker: localhost:9092\n";
echo "   Topic: email-events\n";
echo "   Group: event-worker\n";
echo "   Waiting for messages...\n\n";

$config = new ConsumerConfig();
$config->setBootstrapServers('localhost:9092');
$config->setGroupId('event-worker');
$config->setTopic('email-events');
$config->setAutoCommit(false);  // Manual commit after processing

$consumer = new Consumer($config);
$processedTotal = 0;

while (true) {
    try {
        $message = $consumer->consume();

        if ($message === null) {
            usleep(100000); // 100ms — no messages, wait
            continue;
        }

        $events = json_decode($message->getValue(), true);

        if (!is_array($events) || empty($events)) {
            $consumer->ack($message);
            continue;
        }

        $count = count($events);

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
            $counters = [];
            foreach ($events as $event) {
                $cid = $event['campaign_id'];
                $type = $event['type'];
                if (!isset($counters[$cid])) {
                    $counters[$cid] = ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0];
                }
                $counters[$cid][$type]++;
            }

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

        // Commit offset — tells Kafka "I've processed this message"
        $consumer->ack($message);

        $processedTotal += $count;
        echo "  ✅ Processed {$count} events ({$inserted} new, " . ($count - $inserted) . " dups). Total: {$processedTotal}\n";

    } catch (\PDOException $e) {
        error_log("Worker DB error: " . $e->getMessage());
        echo "  ❌ DB error: " . $e->getMessage() . "\n";
        // Don't ack — message will be redelivered (at-least-once guarantee)
    } catch (\Throwable $e) {
        error_log("Worker error: " . $e->getMessage());
        echo "  ❌ Error: " . $e->getMessage() . "\n";
        usleep(1000000); // 1 second backoff on error
    }
}
