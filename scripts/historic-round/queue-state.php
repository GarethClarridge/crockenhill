<?php

/**
 * Every worker queue's state on this checkout's Redis: paused or not, and its waiting, delayed and
 * reserved jobs. One line per queue with anything in it, then a total line. Read-only.
 *
 *   php scripts/historic-round/queue-state.php            table
 *   php scripts/historic-round/queue-state.php --reserved total reserved jobs only (for waiting until idle)
 */
use App\Services\HistoricMedia\HistoricRerunWatch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = (string) config('queue.default');
$redis = Redis::connection((string) config("queue.connections.{$connection}.connection", 'default'));
$totals = ['waiting' => 0, 'delayed' => 0, 'reserved' => 0, 'paused' => 0];
$lines = [];

foreach (app(HistoricRerunWatch::class)->queues() as $queue) {
    $state = [
        'waiting' => (int) $redis->llen("queues:{$queue}"),
        'delayed' => (int) $redis->zcard("queues:{$queue}:delayed"),
        'reserved' => (int) $redis->zcard("queues:{$queue}:reserved"),
        'paused' => Queue::isPaused($connection, $queue) ? 1 : 0,
    ];
    foreach ($state as $key => $value) {
        $totals[$key] += $value;
    }
    if (array_sum($state) > 0) {
        $lines[] = sprintf('%-28s %s waiting %d, delayed %d, reserved %d', $queue, $state['paused'] ? 'PAUSED ' : 'running', $state['waiting'], $state['delayed'], $state['reserved']);
    }
}

if (in_array('--reserved', $argv, true)) {
    echo $totals['reserved'], PHP_EOL;

    exit(0);
}

echo implode(PHP_EOL, $lines), $lines === [] ? '' : PHP_EOL;
printf("all queues: %d paused of %d; waiting %d, delayed %d, reserved %d\n", $totals['paused'], count(app(HistoricRerunWatch::class)->queues()), $totals['waiting'], $totals['delayed'], $totals['reserved']);
