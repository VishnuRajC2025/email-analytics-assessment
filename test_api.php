<?php

/**
 * API Integration Test Suite
 * Tests all endpoints and edge cases.
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

// Clean up from previous test runs
echo "--- Setup: Cleaning test data ---\n";
$pdo = new PDO('mysql:host=127.0.0.1;dbname=email_analytics', 'root', '');
$pdo->exec("DELETE FROM events WHERE campaign_id IN ('camp-test', 'camp-dedup', 'camp-empty')");
echo "  Cleaned previous test data.\n\n";

// ============ POST /events tests ============
echo "--- POST /events ---\n";

// Test 1: Valid event
$r = request('POST', "{$baseUrl}/events", [
    'event_id'    => 'test-post-1',
    'campaign_id' => 'camp-test',
    'type'        => 'sent',
    'timestamp'   => '2026-06-17T10:00:00Z',
]);
test('Valid event returns 201', $r['code'] === 201);
test('Valid event body has status=accepted', ($r['body']['status'] ?? '') === 'accepted');

// Test 2: All four types work
$types = ['sent', 'opened', 'clicked', 'bounced'];
foreach ($types as $i => $type) {
    $r = request('POST', "{$baseUrl}/events", [
        'event_id'    => "test-type-{$type}",
        'campaign_id' => 'camp-test',
        'type'        => $type,
        'timestamp'   => '2026-06-17T10:00:00Z',
    ]);
    test("Event type '{$type}' returns 201", $r['code'] === 201);
}

// Test 3: Duplicate event_id (idempotency)
$r = request('POST', "{$baseUrl}/events", [
    'event_id'    => 'test-dedup-1',
    'campaign_id' => 'camp-dedup',
    'type'        => 'sent',
    'timestamp'   => '2026-06-17T10:00:00Z',
]);
test('First insert returns 201', $r['code'] === 201);

$r = request('POST', "{$baseUrl}/events", [
    'event_id'    => 'test-dedup-1',
    'campaign_id' => 'camp-dedup',
    'type'        => 'sent',
    'timestamp'   => '2026-06-17T10:00:00Z',
]);
test('Duplicate insert returns 201 (idempotent)', $r['code'] === 201);

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

echo "\n--- GET /campaigns/{id}/stats ---\n";

// Test 8: Stats for campaign with events
$r = request('GET', "{$baseUrl}/campaigns/camp-test/stats");
test('Stats returns 200', $r['code'] === 200);
test('Stats has sent field', isset($r['body']['sent']));
test('Stats has opened field', isset($r['body']['opened']));
test('Stats has clicked field', isset($r['body']['clicked']));
test('Stats has bounced field', isset($r['body']['bounced']));
test('Stats sent count > 0', $r['body']['sent'] > 0);

// Test 9: Dedup verification — camp-dedup should have exactly 1 sent
$r = request('GET', "{$baseUrl}/campaigns/camp-dedup/stats");
test('Dedup: only 1 event counted despite 2 inserts', $r['body']['sent'] === 1);

// Test 10: Empty campaign
$r = request('GET', "{$baseUrl}/campaigns/camp-empty/stats");
test('Empty campaign returns 200', $r['code'] === 200);
test('Empty campaign all zeros', $r['body']['sent'] === 0 && $r['body']['opened'] === 0 && $r['body']['clicked'] === 0 && $r['body']['bounced'] === 0);

// Test 11: CORS header present
$ch = curl_init("{$baseUrl}/campaigns/camp-test/stats");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
]);
$response = curl_exec($ch);
curl_close($ch);
test('CORS header present', strpos($response, 'Access-Control-Allow-Origin: *') !== false);

// Test 12: OPTIONS preflight
$ch = curl_init("{$baseUrl}/events");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'OPTIONS',
]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
test('OPTIONS preflight returns 200', $code === 200);

echo "\n--- Load Test ---\n";

// Test 13: Burst of 100 events
$startTime = microtime(true);
$mh = curl_multi_init();
$handles = [];
for ($i = 0; $i < 100; $i++) {
    $event = json_encode([
        'event_id'    => 'load-' . uniqid() . '-' . $i,
        'campaign_id' => 'camp-test',
        'type'        => $types[$i % 4],
        'timestamp'   => '2026-06-17T10:00:00Z',
    ]);
    $ch = curl_init("{$baseUrl}/events");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $event,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$errors = 0;
foreach ($handles as $ch) {
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 201) $errors++;
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);
$elapsed = round((microtime(true) - $startTime) * 1000);
test("Burst 100 events: 0 errors (got {$errors})", $errors === 0);
echo "  ⏱️  100 concurrent events took {$elapsed}ms\n";

echo "\n============================================\n";
echo "  RESULTS: {$passed} passed, {$failed} failed\n";
echo "============================================\n";

exit($failed > 0 ? 1 : 0);
