<?php

declare(strict_types=1);

/**
 * Handles POST /events
 * 
 * Ingests engagement events with idempotency via INSERT IGNORE.
 * Duplicate event_id values are silently discarded (never double-counted).
 */
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

        // Validate and parse timestamp (RFC3339 / ISO8601)
        $ts = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $data['timestamp']);
        if ($ts === false) {
            // Try ISO8601 variant without timezone offset (Z suffix)
            $ts = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $data['timestamp'], new \DateTimeZone('UTC'));
        }
        if ($ts === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid timestamp format, use RFC3339 (e.g. 2026-06-17T10:00:00Z)']);
            return;
        }

        $mysqlTimestamp = $ts->format('Y-m-d H:i:s');

        try {
            $pdo = Database::getConnection();

            // INSERT IGNORE: if event_id already exists (PRIMARY KEY), the row is silently skipped.
            // This guarantees idempotency — the same event is never counted twice.
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO events (event_id, campaign_id, type, timestamp) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                $data['event_id'],
                $data['campaign_id'],
                $data['type'],
                $mysqlTimestamp,
            ]);

            http_response_code(201);
            echo json_encode(['status' => 'accepted']);
        } catch (\PDOException $e) {
            error_log('Database error in POST /events: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
}
