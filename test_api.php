<?php

/**
 * API Integration Test Suite
 * Tests all endpoints and edge cases.
 * 
 * NOTE: With Kafka queue, POST returns 202 (queued).
 * Stats tests require the worker to have processed events first.
 */

$baseUrl = 'http://localhost/email-analytics/public';
$passed = 0;
$failed = 0;

function request(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => json_decode($response, true),
        'raw'  => $response,
    ];
}

function test(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ PASS: {$name}\n";
        $passed++;
    } else {
        echo "  ❌ FAIL: {$name}\n";
        $failed++;
    }
}

echo "============================================\n";
echo "  EMAIL ANALYTICS API - INTEGRATION TESTS\n";
echo "============================================\n\n";

// ============ POST /events tests ============
echo "--- POST /events ---\n";

// Test 1: Valid event returns 202 (queued to Kafka)
$r = request('POST', "{$baseUrl}/events", [
    'event_id'    => 'test-post-1',
    'campaign_id' => 'camp-test',
    'type'        => 'sent',
    'timestamp'   => '2026-06-17T10:00:00Z',
]);
test('Valid event returns 202 (queued)', $r['code'] === 202);
test('Response status is "queued"', ($r['body']['status'] ?? '') === 'queued');

// Test 2: All four types accepted
$types = ['sent', 'opened', 'clicked', 'bounced'];
foreach ($types as $type) {
    $r = request('POST', "{$baseUrl}/events", [
        'event_id'    => "test-type-{$type}-" . uniqid(),
        'campaign_id' => 'camp-test',
        'type'        => $type,
        'timestamp'   => '2026-06-17T10:00:00Z',
    ]);
    test("Event type '{$type}' returns 202", $r['code'] === 202);
}

// Test 3: Duplicate event_id still returns 202 (queued, dedup happens in worker)
$r = request('POST', "{$baseUrl}/events", [
    'event_id'    => 'test-post-1',
    'campaign_id' => 'camp-test',
    'type'        => 'sent',
    'timestamp'   => '2026-06-17T10:00:00Z',
]);
test('Duplicate event returns 202 (queued, dedup in worker)', $r['code'] === 202);

// Test 4: Invalid type
$r = request('POST', "{$baseUrl}/events", [
    'event_id'    => 'test-invalid-type',
    'campaign_id' => 'camp-test',
    'type'        => 'deleted',
    'timestamp'   => '2026-06-17T10:00:00Z',
]);
test('Invalid type returns 400', $r['code'] === 400);
test('Invalid type has error message', isset($r['body']['error']));

// Test 5: Missing required fields
$r = request('POST', "{$baseUrl}/events", [
    'event_id' => 'test-missing',
]);
test('Missing fields returns 400', $r['code'] === 400);

// Test 6: Invalid timestamp
$r = request('POST', "{$baseUrl}/events", [
    'event_id'    => 'test-bad-ts',
    'campaign_id' => 'camp-test',
    'type'        => 'sent',
    'timestamp'   => 'not-a-date',
]);
test('Invalid timestamp returns 400', $r['code'] === 400);

// Test 7: Invalid JSON body
$ch = curl_init("{$baseUrl}/events");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => 'not json at all',
]);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
test('Invalid JSON body returns 400', $code === 400);

// ============ POST /events/batch tests ============
echo "\n--- POST /events/batch ---\n";

// Test 8: Valid batch
$r = request('POST', "{$baseUrl}/events/batch", [
    'events' => [
        ['event_id' => 'batch-1-' . uniqid(), 'campaign_id' => 'camp-test', 'type' => 'sent', 'timestamp' => '2026-06-17T10:00:00Z'],
        ['event_id' => 'batch-2-' . uniqid(), 'campaign_id' => 'camp-test', 'type' => 'opened', 'timestamp' => '2026-06-17T10:00:00Z'],
    ]
]);
test('Valid batch returns 202', $r['code'] === 202);
test('Batch received count = 2', ($r['body']['received'] ?? 0) === 2);

// Test 9: Empty batch
$r = request('POST', "{$baseUrl}/events/batch", ['events' => []]);
test('Empty batch returns 400', $r['code'] === 400);

// Test 10: Invalid body (no events key)
$r = request('POST', "{$baseUrl}/events/batch", ['data' => 'wrong']);
test('Missing events key returns 400', $r['code'] === 400);

// ============ GET /campaigns/{id}/stats tests ============
echo "\n--- GET /campaigns/{id}/stats ---\n";

// Test 11: Stats endpoint returns 200
$r = request('GET', "{$baseUrl}/campaigns/camp-test/stats");
test('Stats returns 200', $r['code'] === 200);
test('Stats has sent field', isset($r['body']['sent']));
test('Stats has opened field', isset($r['body']['opened']));
test('Stats has clicked field', isset($r['body']['clicked']));
test('Stats has bounced field', isset($r['body']['bounced']));

// Test 12: Empty campaign returns zeros
$r = request('GET', "{$baseUrl}/campaigns/nonexistent-campaign/stats");
test('Empty campaign returns 200', $r['code'] === 200);
test('Empty campaign all zeros', $r['body']['sent'] === 0 && $r['body']['opened'] === 0 && $r['body']['clicked'] === 0 && $r['body']['bounced'] === 0);

// ============ CORS tests ============
echo "\n--- CORS & Preflight ---\n";

$ch = curl_init("{$baseUrl}/campaigns/camp-test/stats");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$response = curl_exec($ch);
curl_close($ch);
test('CORS header present', strpos($response, 'Access-Control-Allow-Origin: *') !== false);

$ch = curl_init("{$baseUrl}/events");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'OPTIONS']);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
test('OPTIONS preflight returns 200', $code === 200);

// ============ Load test ============
echo "\n--- Load Test (Burst to Kafka) ---\n";

$startTime = microtime(true);
$batchEvents = [];
for ($i = 0; $i < 100; $i++) {
    $batchEvents[] = [
        'event_id'    => 'load-' . uniqid() . '-' . $i,
        'campaign_id' => 'camp-load',
        'type'        => $types[$i % 4],
        'timestamp'   => '2026-06-17T10:00:00Z',
    ];
}
$r = request('POST', "{$baseUrl}/events/batch", ['events' => $batchEvents]);
$elapsed = round((microtime(true) - $startTime) * 1000);
test("Burst 100 events queued successfully", $r['code'] === 202);
echo "  ⏱️  100 events queued in {$elapsed}ms\n";

echo "\n============================================\n";
echo "  RESULTS: {$passed} passed, {$failed} failed\n";
echo "============================================\n";

exit($failed > 0 ? 1 : 0);
