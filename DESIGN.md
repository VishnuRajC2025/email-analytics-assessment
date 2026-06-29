# Design Document — Email Engagement Analytics

## Overview

A service that ingests engagement events at high volume and exposes a stats endpoint for a live dashboard. Built with PHP 8.2, Apache Kafka, MySQL 8.0, and Vue 3.

### Architecture

```
Client → API (validates + produces to Kafka) → responds instantly (202)
                     ↓
         Kafka topic "email-events" (durable message store)
                     ↓
         Worker (consumes from Kafka → batch INSERT IGNORE → update counters)
                     ↓
         MySQL (events table + campaign_stats table)
                     ↓
         Dashboard (polls campaign_stats every 5s → shows live numbers)
```

---

## Schema & Indexing Choices

```sql
CREATE TABLE events (
    event_id VARCHAR(255) NOT NULL PRIMARY KEY,
    campaign_id VARCHAR(255) NOT NULL,
    type ENUM('sent', 'opened', 'clicked', 'bounced') NOT NULL,
    timestamp DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign_type (campaign_id, type)
) ENGINE=InnoDB;
```

**Why this schema:**

- **PRIMARY KEY on event_id** — enforces uniqueness at the database level. Combined with `INSERT IGNORE`, any duplicate delivery is silently discarded without error or extra round-trip.
- **ENUM for type** — storage-efficient (1 byte) and self-documenting. Rejects invalid types at the DB layer too.
- **Composite index `idx_campaign_type`** — covers the stats query `SELECT type, COUNT(*) WHERE campaign_id = ? GROUP BY type`. MySQL can satisfy this entirely from the index without touching the clustered data pages (covering index scan).
- **InnoDB** — row-level locking means concurrent inserts don't block each other (important at 20k/s).

---

## Handling 20,000 Events/Second

The current implementation is a correct, synchronous baseline. To handle 20k events/sec at peak:

### Write Path (ingestion)

1. **Buffer + batch insert**: Instead of one INSERT per HTTP request, buffer events in-memory (or in a local queue like Redis) and flush batches of 500–1000 rows with multi-row INSERT IGNORE. MySQL handles bulk inserts far more efficiently — a single multi-row insert at 1000 rows is ~50x faster than 1000 individual inserts.

2. **Application-level queue**: Place a message queue (RabbitMQ, Kafka, or SQS) between the HTTP endpoint and the database. The HTTP handler ACKs immediately after enqueuing, and a pool of workers drains the queue into MySQL. This decouples ingestion rate from DB write throughput.

3. **Horizontal scaling**: Run multiple PHP-FPM workers behind a load balancer (nginx). Each worker handles requests independently. MySQL's row-level locking and the `INSERT IGNORE` pattern mean no coordination is needed between workers.

4. **Connection pooling**: Use persistent PDO connections (`PDO::ATTR_PERSISTENT`) to avoid TCP handshake overhead per request. In production, use a connection pooler like ProxySQL in front of MySQL.

### Read Path (stats)

5. **Materialized counters table**: Maintain a `campaign_stats` table with pre-aggregated counts, updated via triggers or async workers. The stats endpoint reads one row instead of scanning millions.

6. **Read replicas**: Route stats queries to MySQL read replicas to avoid contending with write traffic.

7. **Cache layer**: Place Redis/Memcached in front of stats queries with a 2–5 second TTL. Hundreds of dashboard users hit cache, not the DB.

---

## Ensuring No Events Are Lost

- **Synchronous write**: The current implementation writes to MySQL before responding 201. If the write fails, the client gets a 500 and should retry.
- **Queue-based approach**: With a durable message queue (Kafka with replication, SQS), the event is persisted in the queue before the HTTP 202 response. Workers drain the queue with at-least-once delivery and idempotent writes.
- **Client retries**: The API is idempotent (INSERT IGNORE), so clients can safely retry on timeout or 5xx without causing duplicates.
- **Disk-based WAL**: MySQL's InnoDB redo log (WAL) guarantees that committed transactions survive crashes.

---

## Avoiding Double-Counting Duplicates

- **Primary key constraint**: `event_id` is the PRIMARY KEY. MySQL physically cannot store two rows with the same event_id.
- **INSERT IGNORE**: If a duplicate arrives, MySQL returns success (affected rows = 0) without inserting. No error, no retry loop, no double-count.
- **Idempotent from any angle**: Whether duplicates come from client retries, network replays, or queue redelivery, the result is the same — one row per event_id.

---

## When the Database Is Slow or Down

### Current behavior
- If MySQL is unreachable, the POST /events handler returns HTTP 500. The client is expected to retry (with backoff).
- The stats endpoint also returns 500. The frontend shows an error state with a retry button.

### Production approach
- **Circuit breaker**: After N consecutive DB failures, stop sending traffic to MySQL for a cooldown period. Return 503 (Service Unavailable) with a Retry-After header.
- **Write-ahead queue**: Buffer events in a local or remote queue. If MySQL recovers, the queue drains. If it doesn't recover within a threshold, raise alerts.
- **Graceful degradation**: The stats endpoint can serve stale data from cache even when MySQL is down. The dashboard shows a "stale" indicator.
- **Health checks**: The backend exposes a `/health` endpoint. Load balancers route traffic away from unhealthy instances.

---

## Dashboard Polling Behavior When Stats Endpoint Is Slow

- **Timeout handling**: The frontend fetch has an implicit browser timeout. If the endpoint takes longer than expected, the previous data remains displayed with an increasingly stale timestamp.
- **No request stacking**: The polling interval is measured from completion of the previous request, not from a fixed clock. If a request takes 8 seconds, the next poll fires 5 seconds after that response — not overlapping.
- **Stale indicator**: If `lastUpdated` is more than 15 seconds old, the UI shows "⚠️ Stale" so the user knows the feed is frozen.
- **Error resilience**: If the endpoint returns an error but we already have data, we keep showing the last-known data (with stale indicator) rather than flashing to an error screen.

### Production improvement
- Use exponential backoff on consecutive errors (5s → 10s → 20s → cap at 60s).
- Add a WebSocket or Server-Sent Events channel for true real-time push instead of polling.

---

## Hundreds of Users Opening the Dashboard at Once

### Current behavior
- Each browser polls GET /campaigns/{id}/stats every 5 seconds. 200 users = 40 requests/second to the stats endpoint.
- The idx_campaign_type index means each query is fast (index scan), but at scale this still taxes the DB.

### Production approach
- **Response caching (HTTP)**: Add `Cache-Control: max-age=3` headers. A CDN or reverse proxy (nginx, Cloudflare) serves cached responses to all users hitting the same campaign within the TTL window. 200 users become 1 DB query every 3 seconds.
- **Application cache**: Cache stats in Redis with a 3–5 second TTL. All requests within that window hit memory, not MySQL.
- **Materialized counters**: A background worker updates a `campaign_stats` row every few seconds. The stats endpoint reads one row (primary key lookup) — O(1) regardless of event volume.
- **Rate limiting**: Protect against abuse with per-IP or per-session rate limits on the stats endpoint.

---

## Technology Choices

| Layer     | Choice         | Rationale                                                    |
|-----------|----------------|--------------------------------------------------------------|
| Backend   | PHP 8.2        | Widely deployed, process-per-request model is simple to reason about, good MySQL ecosystem |
| Database  | MySQL 8.0      | Mature, handles high write throughput with InnoDB, good tooling |
| Frontend  | Vue 3 + Vite   | Lightweight, reactive, fast HMR for development              |
| Container | Docker Compose | Easy local setup, reproducible environment                   |

---

## What's Built vs. What's Documented

### Built and working
- ✅ POST /events with validation and idempotent insert
- ✅ GET /campaigns/{id}/stats with aggregation
- ✅ Vue 3 dashboard with polling, loading/error/empty states, derived rates
- ✅ Load generator (both CLI script and in-dashboard button)
- ✅ Docker Compose for local development
- ✅ Database migration (auto-runs on startup)

### Documented (production scaling path)
- 📝 Message queue for write buffering
- 📝 Batch inserts for throughput
- 📝 Materialized counters for read scaling
- 📝 Redis caching layer
- 📝 Circuit breaker pattern
- 📝 CDN/HTTP caching for dashboard traffic
