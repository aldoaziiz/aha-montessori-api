<?php

// Isolated MySQL integration check: never writes to application tables.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\API\TherapySessionController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

$worker = ($argv[1] ?? '') === '--worker';
$prefix = $worker ? $argv[2] : 'uat_p7_'.bin2hex(random_bytes(5)).'_';
if (! preg_match('/^uat_p7_[a-f0-9]{10}_$/', $prefix)) {
    throw new RuntimeException('Invalid isolated table prefix.');
}
$connectionName = config('database.default');
$configuration = config('database.connections.'.$connectionName);
if (($configuration['driver'] ?? '') !== 'mysql') {
    throw new RuntimeException('This test requires MySQL, not SQLite.');
}
$configuration['prefix'] = $prefix;
config(['database.connections.uat_concurrency' => $configuration, 'database.default' => 'uat_concurrency']);
DB::setDefaultConnection('uat_concurrency');

if ($worker) {
    [$mode, $id, $directory] = array_slice($argv, 3);
    auth()->setUser(new User(['id' => (int) $id, 'role' => 'admin']));
    $ready = false;
    $held = false;
    DB::listen(function ($event) use (&$ready, &$held, $directory, $id) {
        if (! $ready && str_contains($event->sql, 'registrations') && str_contains($event->sql, 'for update')) {
            $ready = true;
            file_put_contents($directory.'/ready-'.$id, 'ready');
            $deadline = microtime(true) + 15;
            while (! file_exists($directory.'/go')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Start barrier timeout.');
                }
                usleep(10000);
            }
        }
        if (! $held && str_contains($event->sql, 'program_category_session_times') && str_contains($event->sql, 'for update')) {
            $held = true;
            // Hold the winning slot lock so the competing transaction overlaps it.
            usleep(700000);
        }
    });
    $date = now()->addDay()->toDateString();
    $payload = match ($mode) {
        'generate' => ['registration_id' => (int) $id, 'start_date' => $date, 'end_date' => $date,
            'schedule_configs' => [['day' => now()->addDay()->dayOfWeek, 'session_time_id' => 1]]],
        'bulkStore' => ['registration_id' => (int) $id, 'sessions' => [['therapy_date' => $date, 'session_time_id' => 1]]],
        default => ['registration_id' => (int) $id, 'therapy_date' => $date, 'session_time_id' => 1,
            'start_time' => '08:00', 'end_time' => '09:30'],
    };
    try {
        $started = microtime(true);
        $response = app(TherapySessionController::class)->{$mode}(Request::create('/uat', 'POST', $payload));
        echo json_encode(['mode' => $mode, 'status' => $response->getStatusCode(),
            'body' => $response->getData(true), 'elapsed_ms' => round((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        echo json_encode(['mode' => $mode, 'error' => $exception->getMessage()]);
        exit(1);
    }
    exit(0);
}

$tables = ['registrations', 'programs', 'registration_programs', 'program_category_session_times', 'therapy_sessions'];
$directory = storage_path('app/private/'.$prefix);
mkdir($directory, 0777, true);
$report = ['prefix' => $prefix, 'cases' => []];
try {
    Schema::create('registrations', function (Blueprint $t) {
        $t->id(); $t->integer('total_session'); $t->date('session_started_at'); $t->date('session_expired_at'); $t->timestamps();
    });
    Schema::create('programs', function (Blueprint $t) { $t->id(); });
    Schema::create('registration_programs', function (Blueprint $t) {
        $t->integer('registration_id'); $t->integer('program_id'); $t->integer('price')->nullable(); $t->integer('learning_period_months')->nullable();
    });
    Schema::create('program_category_session_times', function (Blueprint $t) {
        $t->id(); $t->string('session_name'); $t->time('start_time'); $t->time('end_time'); $t->integer('capacity');
    });
    Schema::create('therapy_sessions', function (Blueprint $t) {
        $t->id(); $t->integer('registration_id'); $t->integer('therapist_id')->nullable();
        $t->integer('therapy_session_status_id'); $t->boolean('uses_session')->default(false);
        $t->date('therapy_date'); $t->time('start_time'); $t->time('end_time'); $t->text('notes')->nullable(); $t->timestamps();
        $t->index(['registration_id', 'uses_session'], 'reg_usage');
    });
    DB::table('program_category_session_times')->insert(['id' => 1, 'session_name' => 'UAT isolated slot',
        'start_time' => '08:00:00', 'end_time' => '09:30:00', 'capacity' => 1]);
    foreach ([1, 2] as $id) {
        DB::table('registrations')->insert(['id' => $id, 'total_session' => 10,
            'session_started_at' => now()->toDateString(), 'session_expired_at' => now()->addYear()->toDateString()]);
    }
    foreach ([['store', 'store'], ['generate', 'generate'], ['generate', 'store'], ['bulkStore', 'bulkStore'], ['store', 'bulkStore']] as $index => $modes) {
        DB::table('therapy_sessions')->delete();
        $caseDirectory = $directory.'/case-'.$index;
        mkdir($caseDirectory);
        $processes = [];
        try {
            foreach ($modes as $i => $mode) {
                $process = new Process([PHP_BINARY, __FILE__, '--worker', $prefix, $mode, (string) ($i + 1), $caseDirectory], base_path());
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (! file_exists($caseDirectory.'/ready-1') || ! file_exists($caseDirectory.'/ready-2')) {
                if (microtime(true) > $deadline || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    throw new RuntimeException('Workers failed before barrier: '.$processes[0]->getOutput().$processes[1]->getOutput().$processes[0]->getErrorOutput().$processes[1]->getErrorOutput());
                }
                usleep(10000);
            }
            file_put_contents($caseDirectory.'/go', 'go');
            $responses = [];
            foreach ($processes as $process) {
                $process->wait();
                $responses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($responses, 'status');
            sort($statuses);
            $count = DB::table('therapy_sessions')->count();
            $passed = $count === 1 && count($statuses) === 2 && $statuses[0] >= 200 && $statuses[0] < 300 && $statuses[1] === 422;
            $result = ['modes' => $modes, 'passed' => $passed, 'capacity' => 1, 'saved_sessions' => $count, 'responses' => $responses];
            $report['cases'][] = $result;
            echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) { $process->stop(1); }
            }
        }
    }
} finally {
    foreach (array_reverse($tables) as $table) {
        // Only the randomly prefixed tables belonging to this run can be dropped.
        Schema::dropIfExists($table);
    }
    $report['isolated_tables_removed'] = true;
    file_put_contents($directory.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo 'Report: '.$directory.'/report.json'.PHP_EOL;
}
exit(count(array_filter($report['cases'], fn ($case) => ! $case['passed'])) > 0 ? 1 : 0);
