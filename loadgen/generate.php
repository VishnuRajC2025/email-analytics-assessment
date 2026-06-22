<?php

/**
 * Load Generator for Email Analytics API
 * 
 * Fires a configurable number of events at POST /events.
 * 
 * Usage:
 *   php generate.php [campaign_id] [event_count] [concurrency]
 * 
 * Examples:
 *   php generate.php camp-1 1000 10
 *   php generate.php camp-2 5000 20
 * 
 * Defaults: campaign_id=camp-1, event_count=100, concurrency=10
 */

declare(strict_types=1);

$campaignId  = $argv[1] ?? 'camp-1';
$eventCount  = (int) ($argv[2] ?? 100);
$concurrency = (int) ($argv[3] ?? 10);
$baseUrl     = getenv('API_URL') ?: 'http://localhost:8080';

$eventTypes = ['sent', 'opened', 'clicked', 'bounced'];

echo "🚀 Load Generator\n";
echo "   Target:      {$baseUrl}/events\n";
echo "   Campaign:    {$campaignId}\n";
echo "   Events:      {$eventCount}\n";
echo "   Concurrency: {$concurrency} (sequential batches)\n\n";

$sent = 0;
$errors = 0;
$startTime = microtime(true);

// Use curl_multi for concurrent requests
$batchSize = $concurrency;

for ($i = 0; $i < $eventCount; $i += $batchSize) {
    $mh = curl_multi_init();
    $handles = [];
    $batchEnd = min($i + $batchSize, $eventCount);

    for ($j = $i; $j < $batchEnd; $j++) {
        $event = [
            'event_id'    => sprintf('evt-%s-%d-%s', $campaignId, $j, bin2hex(random_bytes(4))),
            'campaign_id' => $campaignId,
            'type'        => $eventTypes[$j % 4],
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $ch = curl_init("{$baseUrl}/events");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($event),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }

    // Execute all handles in parallel
    do {
        $status = curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    // Check results
    foreach ($handles as $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode === 201) {
            $sent++;
        } else {
            $errors++;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    // Progress indicator
    $progress = min($batchEnd, $eventCount);
    $percent = round(($progress / $eventCount) * 100);
    echo "\r   Progress: {$progress}/{$eventCount} ({$percent}%)";
}

$elapsed = round((microtime(true) - $startTime) * 1000);
$rate = $eventCount > 0 ? round($eventCount / ((microtime(true) - $startTime)), 1) : 0;

echo "\n\n✅ Complete!\n";
echo "   Sent:    {$sent}\n";
echo "   Errors:  {$errors}\n";
echo "   Time:    {$elapsed}ms\n";
echo "   Rate:    {$rate} events/sec\n";
