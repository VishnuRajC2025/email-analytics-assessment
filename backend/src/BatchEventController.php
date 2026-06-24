<?php

declare(strict_types=1);

/**
 * Handles POST /events/batch
 * 
 * FAST PATH: Validates events, writes batch to queue, responds immediately.
 * The API NEVER waits for MySQL — that happens in the background worker.
 */
class BatchEventController
{
    private const VALID_TYPES = ['sent', 'opened', 'clicked', 'bounced'];
    private const MAX_BATCH_SIZE = 1000;

    public function handlePost(): void
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Request body must contain an "events" array']);
            return;
        }

        $events = $data['events'];

        if (count($events) === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Events array is empty']);
            return;
        }

        if (count($events) > self::MAX_BATCH_SIZE) {
            http_response_code(400);
            echo json_encode(['error' => 'Max batch size is ' . self::MAX_BATCH_SIZE]);
            return;
        }

        // Validate all events
        $validEvents = [];
        foreach ($events as $event) {
            if (!is_array($event)) continue;

            if (empty($event['event_id']) || empty($event['campaign_id']) || 
                empty($event['type']) || empty($event['timestamp'])) {
                continue;
            }

            if (!in_array($event['type'], self::VALID_TYPES, true)) {
                continue;
            }

            $ts = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $event['timestamp']);
            if ($ts === false) {
                $ts = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $event['timestamp'], new \DateTimeZone('UTC'));
            }
            if ($ts === false) {
                continue;
            }

            $validEvents[] = [
                'event_id'    => $event['event_id'],
                'campaign_id' => $event['campaign_id'],
                'type'        => $event['type'],
                'timestamp'   => $ts->format('Y-m-d H:i:s'),
            ];
        }

        if (empty($validEvents)) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid events in batch']);
            return;
        }

        // Write to queue — NO DATABASE CALL, responds instantly
        $queue = new QueueService();
        $queue->enqueue($validEvents);

        // Respond immediately — client doesn't wait for DB
        http_response_code(202); // 202 = Accepted (queued for processing)
        echo json_encode([
            'status'   => 'queued',
            'received' => count($validEvents),
        ]);
    }
}
