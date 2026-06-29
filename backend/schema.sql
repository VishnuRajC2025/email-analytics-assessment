-- Email Analytics Schema

CREATE DATABASE IF NOT EXISTS email_analytics;
USE email_analytics;

CREATE TABLE IF NOT EXISTS events (
    event_id VARCHAR(255) NOT NULL PRIMARY KEY,
    campaign_id VARCHAR(255) NOT NULL,
    type ENUM('sent', 'opened', 'clicked', 'bounced') NOT NULL,
    event_timestamp DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign_type (campaign_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_stats (
    campaign_id VARCHAR(255) NOT NULL PRIMARY KEY,
    sent INT UNSIGNED NOT NULL DEFAULT 0,
    opened INT UNSIGNED NOT NULL DEFAULT 0,
    clicked INT UNSIGNED NOT NULL DEFAULT 0,
    bounced INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
