<?php

namespace App\Console\Commands;

use App\Models\Program;
use App\Models\Registration;
use App\Models\RegistrationProgram;
use Illuminate\Console\Command;

class MigrateRegistrationPrograms extends Command
{
    protected $signature = 'aha:migrate-registration-programs';

    protected $description =
        'Move registration.program_id into registration_programs table';

    public function handle()
    {
        $count = 0;

        Registration::query()
            ->whereNotNull('program_id')
            ->chunk(100, function ($registrations) use (&$count) {

                foreach ($registrations as $registration) {

                    $exists = RegistrationProgram::where(
                        'registration_id',
                        $registration->id
                    )
                        ->where(
                            'program_id',
                            $registration->program_id
                        )
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $program = Program::find(
                        $registration->program_id
                    );

                    if (! $program) {
                        continue;
                    }

                    RegistrationProgram::create([
                        'registration_id' => $registration->id,

                        'program_id' => $program->id,

                        'price' => $program->price,
                    ]);

                    $count++;
                }
            });

        $this->info(
            "{$count} registration programs migrated."
        );

        return self::SUCCESS;
    }
}
