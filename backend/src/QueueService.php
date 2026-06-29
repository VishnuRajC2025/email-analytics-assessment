<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use longlang\phpkafka\Producer\Producer;
use longlang\phpkafka\Producer\ProducerConfig;
use longlang\phpkafka\Consumer\Consumer;
use longlang\phpkafka\Consumer\ConsumerConfig;

/**
 * Kafka-based queue service.
 * 
 * Uses Apache Kafka for durable, high-throughput event streaming.
 * - Producer: API writes events to Kafka topic (instant, no DB wait)
 * - Consumer: Background worker reads from Kafka and batch-inserts into MySQL
 * 
 * Kafka guarantees:
 * - Messages are durable (written to disk, replicated in production)
 * - Order is preserved within a partition
 * - At-least-once delivery (consumer commits offset after processing)
 */
class QueueService
{
    private const TOPIC = 'email-events';
    private const BROKER = 'localhost:29092';

    /**
     * Produce (enqueue) events to Kafka topic.
     * Sends all events in a single producer session for maximum throughput.
     */
    public function enqueue(array $events): void
    {
        $config = new ProducerConfig();
        $config->setBootstrapServers(self::BROKER);
        $config->setAcks(1); // Leader ack only (faster than -1 for high throughput)

        $producer = new Producer($config);

        // Send all events in one session (reuses connection)
        foreach ($events as $event) {
            if (isset($event['timestamp']) && !isset($event['event_timestamp'])) {
                $event['event_timestamp'] = $event['timestamp'];
                unset($event['timestamp']);
            }
            $producer->send(self::TOPIC, json_encode($event));
        }

        $producer->close();
    }

    public function dequeue(): ?array
    {
        $config = new ConsumerConfig();
        $config->setBootstrapServers(self::BROKER);
        $config->setGroupId('event-worker');
        $config->setTopic(self::TOPIC);
        $config->setAutoCommit(false);
        $config->setInterval(0.1); // 100ms poll interval

        $consumer = new Consumer($config);
        $message = $consumer->consume();

        if ($message === null) {
            $consumer->close();
            return null;
        }

        $events = json_decode($message->getValue(), true);
        $consumer->ack($message); // Commit offset — message won't be redelivered
        $consumer->close();

        return $events;
    }
}
