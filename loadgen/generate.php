<?php

/**
 * Load Generator for Email Analytics API
 * 
 * Fires events using the batch endpoint for maximum throughput.
 * 
 * Usage:
 *   php generate.php [campaign_id] [event_count] [batch_size]
 * 
 * Examples:
 *   php generate.php camp-1 1000 500
 *   php generate.php camp-2 20000 1000
 * 
 * Defaults: campaign_id=camp-1, event_count=1000, batch_size=500
 */

declare(strict_types=1);

$campaignId = $argv[1] ?? 'camp-1';
$eventCount = (int) ($argv[2] ?? 1000);
$batchSize  = (int) ($argv[3] ?? 500);
$baseUrl    = getenv('API_URL') ?: 'http://localhost/email-analytics/public';

$eventTypes = ['sent', 'opened', 'clicked', 'bounced'];

echo "🚀 Load Generator\n";
echo "   Target:     {$baseUrl}/events/batch\n";
echo "   Campaign:   {$campaignId}\n";
echo "   Events:     {$eventCount}\n";
echo "   Batch size: {$batchSize}\n\n";

$sent = 0;
$errors = 0;
$startTime = microtime(true);

for ($i = 0; $i < $eventCount; $i += $batchSize) {
    $size = min($batchSize, $eventCount - $i);
    $events = [];

    for ($j = 0; $j < $size; $j++) {
        $events[] = [
            'event_id'    => sprintf('evt-%s-%d-%s', $campaignId, $i + $j, bin2hex(random_bytes(4))),
            'campaign_id' => $campaignId,
            'type'        => $eventTypes[($i + $j) % 4],
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    $ch = curl_init("{$baseUrl}/events/batch");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['events' => $events]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 202) {
        $sent += $size;
    } else {
        $errors += $size;
    }

    $progress = min($i + $size, $eventCount);
    $percent = round(($progress / $eventCount) * 100);
    echo "\r   Progress: {$progress}/{$eventCount} ({$percent}%)";
}

$elapsed = round((microtime(true) - $startTime) * 1000);
$rate = $eventCount > 0 ? round($eventCount / (microtime(true) - $startTime), 1) : 0;

echo "\n\n✅ Complete!\n";
echo "   Queued: {$sent}\n";
echo "   Errors: {$errors}\n";
echo "   Time:   {$elapsed}ms\n";
echo "   Rate:   {$rate} events/sec\n";
echo "\n   Note: Events are in Kafka. Run the worker to process into MySQL.\n";
