<?php

// Helper for LeaderboardHardeningTest::testConcurrentPostsFromOneIpCreateAtMostTen:
// one separate PHP process = one concurrent POST. Waits for a barrier file so
// every process hits the API at the same moment, then prints the status code.
// Usage: php post_once.php <barrier-file> <client-ip> <json-body>

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $barrier, $ip, $body] = $argv;

$kernel = new Kernel('test', false);
$kernel->boot();

$deadline = microtime(true) + 20;
while (!is_file($barrier) && microtime(true) < $deadline) {
    usleep(1000);
}

$request = Request::create('/api/leaderboard', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip], $body);
try {
    echo $kernel->handle($request)->getStatusCode();
} catch (\Throwable) {
    echo 500;
}
