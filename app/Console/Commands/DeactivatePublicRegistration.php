<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DeactivatePublicRegistration extends Command
{
    protected $signature = 'aha:deactivate-public-registration';

    protected $description = 'Deactivate the public registration form';

    public function handle(): int
    {
        if (! Schema::hasTable('app_settings')) {
            $this->error('The app_settings table does not exist.');

            return self::FAILURE;
        }

        AppSetting::updateOrCreate(
            ['setting_key' => 'public_registration_enabled'],
            [
                'setting_value' => '0',
                'setting_group' => 'registration',
                'value_type' => 'boolean',
                'description' => 'Controls whether the public registration form accepts submissions.',
            ]
        );

        $this->info('Public registration is now inactive.');

        return self::SUCCESS;
    }
}
