<?php

use Illuminate\Support\Str;

$historicFfmpegQueue = env('HISTORIC_MEDIA_QUEUE_FFMPEG', 'historic-ffmpeg');
$historicWhisperQueue = env('HISTORIC_MEDIA_QUEUE_WHISPER', 'historic-whisper');
$historicLlmQueue = env('HISTORIC_MEDIA_QUEUE_LLM', 'historic-llm');
$historicOrchestrationQueue = env('HISTORIC_MEDIA_QUEUE_ORCHESTRATION', 'historic-orchestration');
$historicFfmpegWorkers = (int) env('HISTORIC_MEDIA_WORKERS_FFMPEG', 1);
$historicWhisperWorkers = (int) env('HISTORIC_MEDIA_WORKERS_WHISPER', 1);
$historicLlmWorkers = (int) env('HISTORIC_MEDIA_WORKERS_LLM', 1);
$historicOrchestrationWorkers = (int) env('HISTORIC_MEDIA_WORKERS_ORCHESTRATION', 1);

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        // Mirrors the pre-Horizon `queue:work redis` supervisord worker exactly.
        // `balance => false` is the only strategy that passes the full queue list
        // to each worker in strict priority order (like `--queue=a,b,c`); the
        // `simple` strategy instead splits the fixed process count evenly across
        // queues, which with 6 queues and 2 processes would assign 0 per queue.
        // Pinning minProcesses = maxProcesses disables autoscaling.
        // INVARIANT: `timeout` must stay below queue.connections.redis.retry_after
        // (REDIS_QUEUE_RETRY_AFTER=7260) or jobs can be processed twice; Horizon
        // refuses to boot when this is violated.
        'supervisor-media' => [
            'connection' => 'redis',
            'queue' => [
                'video-processing',
                'audio-processing',
                'sermon-processing',
                'livestream-processing',
                'speaker-identification',
                'default',
            ],
            'balance' => false,
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 86400,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 7200,
            'sleep' => 3,
            'nice' => 0,
        ],
        // Historic archive work uses isolated queues. Calibration can widen the
        // CPU, single-GPU and remote-API stages independently without changing
        // the current weekly pipeline's worker allocation.
        'supervisor-historic-ffmpeg' => [
            'connection' => 'redis',
            'queue' => [$historicFfmpegQueue],
            'balance' => false,
            'minProcesses' => $historicFfmpegWorkers,
            'maxProcesses' => $historicFfmpegWorkers,
            'maxTime' => 86400,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 7200,
            'sleep' => 3,
            'nice' => 0,
        ],
        'supervisor-historic-whisper' => [
            'connection' => 'redis',
            'queue' => [$historicWhisperQueue],
            'balance' => false,
            'minProcesses' => $historicWhisperWorkers,
            'maxProcesses' => $historicWhisperWorkers,
            'maxTime' => 86400,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 7200,
            'sleep' => 3,
            'nice' => 0,
        ],
        'supervisor-historic-llm' => [
            'connection' => 'redis',
            'queue' => [$historicLlmQueue],
            'balance' => false,
            'minProcesses' => $historicLlmWorkers,
            'maxProcesses' => $historicLlmWorkers,
            'maxTime' => 86400,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 7200,
            'sleep' => 3,
            'nice' => 0,
        ],
        'supervisor-historic-orchestration' => [
            'connection' => 'redis',
            'queue' => [$historicOrchestrationQueue],
            'balance' => false,
            'minProcesses' => $historicOrchestrationWorkers,
            'maxProcesses' => $historicOrchestrationWorkers,
            'maxTime' => 86400,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 7200,
            'sleep' => 3,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-media' => [
                'minProcesses' => 2,
                'maxProcesses' => 2,
            ],
            'supervisor-historic-ffmpeg' => [],
            'supervisor-historic-whisper' => [],
            'supervisor-historic-llm' => [],
            'supervisor-historic-orchestration' => [],
        ],

        'local' => [
            'supervisor-media' => [],
            'supervisor-historic-ffmpeg' => [],
            'supervisor-historic-whisper' => [],
            'supervisor-historic-llm' => [],
            'supervisor-historic-orchestration' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
