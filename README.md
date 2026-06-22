# Email Engagement Analytics

A high-throughput email engagement event ingestion service with a live campaign dashboard.

## Architecture

- **Backend**: PHP 8.2 (built-in dev server) + MySQL 8.0
- **Frontend**: Vue 3 + Vite
- **Load Generator**: PHP CLI script + in-dashboard button

## Prerequisites

- Docker & Docker Compose
- Node.js 18+ & npm
- PHP 8.2+ with `pdo_mysql` and `curl` extensions (for load generator)

## Quick Start

### 1. Start the backend (MySQL + PHP)

```bash
docker-compose up -d
```

This starts:
- MySQL on port 3306 (auto-runs schema migration)
- PHP dev server on port 8080

### 2. Start the frontend

```bash
cd frontend
npm install
npm run dev
```

The dashboard opens at http://localhost:3000

### 3. Test the API

```bash
# Send an event
curl -X POST http://localhost:8080/events \
  -H "Content-Type: application/json" \
  -d '{"event_id":"test-1","campaign_id":"camp-1","type":"sent","timestamp":"2026-06-17T10:00:00Z"}'

# Get campaign stats
curl http://localhost:8080/campaigns/camp-1/stats
```

### 4. Run the load generator

```bash
# From the project root — send 1000 events for camp-1 with concurrency 10
php loadgen/generate.php camp-1 1000 10
```

Or use the "Load Generator" panel built into the dashboard.

## Running Without Docker

If you prefer running services directly:

```bash
# 1. Start MySQL and create the database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS email_analytics;"

# 2. Set environment variables
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=email_analytics
export DB_USER=root
export DB_PASS=password

# 3. Run migration
php backend/migrate.php

# 4. Start PHP dev server
php -S localhost:8080 -t backend/public

# 5. Start frontend
cd frontend && npm install && npm run dev
```

## Project Structure

```
.
├── backend/
│   ├── public/index.php      # Front controller (router)
│   ├── src/
│   │   ├── Database.php      # PDO connection + migration
│   │   ├── EventController.php   # POST /events handler
│   │   └── StatsController.php   # GET /campaigns/{id}/stats handler
│   ├── schema.sql            # Full schema reference
│   └── migrate.php           # CLI migration runner
├── frontend/
│   ├── src/
│   │   ├── App.vue
│   │   └── components/
│   │       ├── CampaignDashboard.vue  # Main dashboard with polling
│   │       └── LoadGenerator.vue      # In-app load testing
│   ├── index.html
│   └── vite.config.js
├── loadgen/
│   └── generate.php          # CLI load generator
├── docker-compose.yml
├── DESIGN.md                 # Architecture & scaling decisions
└── README.md
```

## API Reference

### POST /events

Ingests a single engagement event. Idempotent — duplicate `event_id` values are silently ignored.

**Request:**
```json
{
  "event_id": "abc-123",
  "campaign_id": "camp-1",
  "type": "opened",
  "timestamp": "2026-06-17T10:00:00Z"
}
```

**Responses:**
- `201` — Event accepted
- `400` — Validation error (missing fields, invalid type, bad timestamp)
- `500` — Internal error

### GET /campaigns/{id}/stats

Returns aggregated counts for a campaign.

**Response:**
```json
{
  "sent": 1000,
  "opened": 450,
  "clicked": 120,
  "bounced": 30
}
```

## Design Decisions

See [DESIGN.md](./DESIGN.md) for detailed documentation on:
- Schema and indexing choices
- How to handle 20,000 events/sec
- Deduplication strategy
- Failure modes and resilience
- Dashboard polling behavior under load
