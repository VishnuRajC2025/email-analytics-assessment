<?php

declare(strict_types=1);

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

        // Validate all events first
        $rows = [];
        foreach ($events as $i => $event) {
            if (!is_array($event)) continue;

            // Check required fields
            if (empty($event['event_id']) || empty($event['campaign_id']) || 
                empty($event['type']) || empty($event['timestamp'])) {
                continue; // Skip invalid events
            }

            // Check type
            if (!in_array($event['type'], self::VALID_TYPES, true)) {
                continue;
            }

            // Parse timestamp
            $ts = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $event['timestamp']);
            if ($ts === false) {
                $ts = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $event['timestamp'], new \DateTimeZone('UTC'));
            }
            if ($ts === false) {
                continue;
            }

            $rows[] = [
                $event['event_id'],
                $event['campaign_id'],
                $event['type'],
                $ts->format('Y-m-d H:i:s'),
            ];
        }

        if (empty($rows)) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid events in batch']);
            return;
        }

        try {
            $pdo = Database::getConnection();

            // Build multi-row INSERT IGNORE for maximum throughput
            // INSERT IGNORE INTO events (...) VALUES (?,?,?,?), (?,?,?,?), ...
            $placeholders = implode(',', array_fill(0, count($rows), '(?,?,?,?)'));
            $sql = "INSERT IGNORE INTO events (event_id, campaign_id, type, timestamp) VALUES {$placeholders}";

            $params = [];
            foreach ($rows as $row) {
                $params = array_merge($params, $row);
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            http_response_code(201);
            echo json_encode([
                'status' => 'accepted',
                'received' => count($rows),
            ]);
        } catch (\PDOException $e) {
            error_log('Database error in POST /events/batch: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
}
