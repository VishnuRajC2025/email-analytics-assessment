<?php

declare(strict_types=1);


class EventController
{
    private const VALID_TYPES = ['sent', 'opened', 'clicked', 'bounced'];

    public function handlePost(): void
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body']);
            return;
        }

        // Validate required fields
        $required = ['event_id', 'campaign_id', 'type', 'timestamp'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: {$field}"]);
                return;
            }
        }

        // Validate event type
        if (!in_array($data['type'], self::VALID_TYPES, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'type must be one of: sent, opened, clicked, bounced']);
            return;
        }

        // Validate timestamp format
        $ts = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $data['timestamp']);
        if ($ts === false) {
            $ts = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $data['timestamp'], new \DateTimeZone('UTC'));
        }
        if ($ts === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid timestamp format, use RFC3339 (e.g. 2026-06-17T10:00:00Z)']);
            return;
        }

        // Write to queue — NO DATABASE CALL, responds instantly
        $queue = new QueueService();
        $queue->enqueue([[
            'event_id'    => $data['event_id'],
            'campaign_id' => $data['campaign_id'],
            'type'        => $data['type'],
            'timestamp'   => $ts->format('Y-m-d H:i:s'),
        ]]);

        // Respond immediately — client doesn't wait for DB
        http_response_code(202); // 202 = Accepted (queued for processing)
        echo json_encode(['status' => 'queued']);
    }
}
