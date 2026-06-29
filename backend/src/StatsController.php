<?php

declare(strict_types=1);

/**
 * Handles GET /campaigns/{id}/stats
 * 
 * Returns aggregated counts for a campaign, grouped by event type.
 * Uses the idx_campaign_type covering index for efficient aggregation.
 */
class StatsController
{
    public function handleGet(string $campaignId): void
    {
        if (empty($campaignId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Campaign ID is required']);
            return;
        }

        try {
            $pdo = Database::getConnection();

            // Read stats by counting from events table
            // With the idx_campaign_type covering index, this is fast
            $stmt = $pdo->prepare(
                "SELECT type, COUNT(*) as cnt FROM events WHERE campaign_id = ? GROUP BY type"
            );
            $stmt->execute([$campaignId]);

            $stats = [
                'sent'    => 0,
                'opened'  => 0,
                'clicked' => 0,
                'bounced' => 0,
            ];

            while ($row = $stmt->fetch()) {
                if (isset($stats[$row['type']])) {
                    $stats[$row['type']] = (int) $row['cnt'];
                }
            }

            http_response_code(200);
            echo json_encode($stats);
        } catch (\PDOException $e) {
            error_log('Database error in GET /campaigns/stats: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
}
