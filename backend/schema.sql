-- Email Analytics Schema
-- Designed for high-throughput event ingestion with deduplication

CREATE DATABASE IF NOT EXISTS email_analytics;
USE email_analytics;

CREATE TABLE IF NOT EXISTS events (
    event_id VARCHAR(255) NOT NULL PRIMARY KEY,
    campaign_id VARCHAR(255) NOT NULL,
    type ENUM('sent', 'opened', 'clicked', 'bounced') NOT NULL,
    timestamp DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Composite index for the stats query: GROUP BY type WHERE campaign_id = ?
    INDEX idx_campaign_type (campaign_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The PRIMARY KEY on event_id guarantees uniqueness (deduplication).
-- INSERT IGNORE leverages this to silently drop duplicate inserts.
-- The idx_campaign_type index covers the stats aggregation query efficiently.
