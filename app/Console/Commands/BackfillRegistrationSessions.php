<?php

namespace App\Console\Commands;

use App\Models\TherapySession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BackfillRegistrationSessions extends Command
{
    protected $signature = 'aha:backfill-registration-sessions
                            {--apply : Apply the backfill; otherwise only preview changes}';

    protected $description = 'Fill missing registration session totals and validity dates, and align attendance usage';

    public function handle(): int
    {
        try {
            $result = DB::transaction(function () {
                // Keep the snapshot and updates consistent with scheduling transactions.
                $registrations = DB::table('registrations')->orderBy('id')->lockForUpdate()->get();
                $programs = DB::table('programs')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $links = DB::table('registration_programs')->orderBy('registration_id')->lockForUpdate()->get()->groupBy('registration_id');
                $sessions = DB::table('therapy_sessions')->orderBy('id')->lockForUpdate()->get();
                $changes = ['registrations' => [], 'therapy_sessions' => []];

                foreach ($registrations as $registration) {
                    $after = [];
                    if ($registration->total_session === null) {
                        $registrationPrograms = $links->get($registration->id, collect());
                        if ($registrationPrograms->isEmpty()) {
                            throw new RuntimeException("Registration {$registration->id}: no program; cannot infer Total Session.");
                        }
                        $total = 0;
                        foreach ($registrationPrograms as $link) {
                            $program = $programs->get($link->program_id);
                            if (! $program || (int) $program->session_count <= 0 || (int) $link->learning_period_months <= 0) {
                                throw new RuntimeException("Registration {$registration->id}: missing/invalid session count or learning period.");
                            }
                            $total += (int) $program->session_count * (int) $link->learning_period_months;
                        }
                        $after['total_session'] = $total;
                    }

                    if ($registration->session_started_at === null) {
                        if (! $registration->created_at || str_starts_with($registration->created_at, '0000-')) {
                            throw new RuntimeException("Registration {$registration->id}: missing/invalid created_at.");
                        }
                        $after['session_started_at'] = Carbon::parse($registration->created_at)->toDateString();
                    }
                    $start = $after['session_started_at'] ?? $registration->session_started_at;
                    if ($registration->session_expired_at === null) {
                        // Match Registration::syncSessionEntitlement, including leap-year behavior.
                        $after['session_expired_at'] = Carbon::parse($start)->addYear()->toDateString();
                    }
                    $end = $after['session_expired_at'] ?? $registration->session_expired_at;
                    if (Carbon::parse($end)->lt(Carbon::parse($start))) {
                        throw new RuntimeException("Registration {$registration->id}: expiry precedes start date.");
                    }
                    if ($after !== []) {
                        $changes['registrations'][] = [
                            'id' => $registration->id,
                            'before' => array_intersect_key((array) $registration, $after),
                            'after' => $after,
                        ];
                    }
                }

                foreach ($sessions as $session) {
                    $usesSession = TherapySession::usesSessionForStatus((int) $session->therapy_session_status_id);
                    if ($session->uses_session === null || (bool) $session->uses_session !== $usesSession) {
                        $changes['therapy_sessions'][] = [
                            'id' => $session->id,
                            'before' => ['uses_session' => $session->uses_session],
                            'after' => ['uses_session' => $usesSession],
                        ];
                    }
                }

                $backup = null;
                if ($this->option('apply') && ($changes['registrations'] !== [] || $changes['therapy_sessions'] !== [])) {
                    $directory = storage_path('app/private/session-backfill');
                    File::ensureDirectoryExists($directory);
                    $backup = $directory.'/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
                    $snapshot = json_encode([
                        'created_at' => now()->toIso8601String(),
                        'database' => DB::connection()->getDatabaseName(),
                        'description' => 'Pre-update values and intended changes; transaction completion is reported by the command.',
                        'changes' => $changes,
                    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                    if (File::put($backup, $snapshot, true) === false) {
                        throw new RuntimeException('Could not write backup; no changes applied.');
                    }

                    foreach ($changes as $table => $rows) {
                        foreach ($rows as $row) {
                            // Query builder deliberately preserves updated_at and avoids attendance/model events.
                            DB::table($table)->where('id', $row['id'])->update($row['after']);
                        }
                    }
                }

                return ['changes' => $changes, 'backup' => $backup];
            });
        } catch (\Throwable $exception) {
            $this->error('Backfill aborted; database changes rolled back. '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('apply') ? 'Backfill applied.' : 'Preview only; no database changes.');
        foreach ($result['changes'] as $table => $rows) {
            $this->line($table.': '.count($rows).' row(s)');
            foreach ($rows as $row) {
                $this->line('  #'.$row['id'].' '.json_encode($row['after']));
            }
        }
        if ($result['backup']) {
            $this->line('Backup: '.$result['backup']);
        }

        return self::SUCCESS;
    }
}
