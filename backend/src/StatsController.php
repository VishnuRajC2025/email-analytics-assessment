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

            // Read from pre-calculated counters table (instant — one row lookup)
            // Instead of counting millions of rows every time
            $stmt = $pdo->prepare(
                "SELECT sent, opened, clicked, bounced FROM campaign_stats WHERE campaign_id = ?"
            );
            $stmt->execute([$campaignId]);

            $row = $stmt->fetch();

            if ($row) {
                $stats = [
                    'sent'    => (int) $row['sent'],
                    'opened'  => (int) $row['opened'],
                    'clicked' => (int) $row['clicked'],
                    'bounced' => (int) $row['bounced'],
                ];
            } else {
           
                    
                // Campaign has no events yet
                $stats = [
                    'sent'    => 0,
                    'opened'  => 0,
                    'clicked' => 0,
                    'bounced' => 0,
                ];
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
