<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PublicRegistrationSettingsController extends Controller
{
    private const SETTING_KEY = 'public_registration_enabled';

    public function publicStatus()
    {
        return response()->json([
            'is_active' => AppSetting::enabled(self::SETTING_KEY),
        ]);
    }

    public function show()
    {
        $this->requireAdmin();

        return response()->json([
            'data' => [
                'configured' => Schema::hasTable('app_settings'),
                'is_active' => AppSetting::enabled(self::SETTING_KEY),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->requireAdmin();

        if (! Schema::hasTable('app_settings')) {
            return response()->json([
                'message' => 'App settings have not been configured. Apply the provided database setup query first.',
            ], 503);
        }

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $setting = AppSetting::updateOrCreate(
            ['setting_key' => self::SETTING_KEY],
            [
                'setting_value' => $validated['is_active'] ? '1' : '0',
                'setting_group' => 'registration',
                'value_type' => 'boolean',
                'description' => 'Controls whether the public registration form accepts submissions.',
            ]
        );

        return response()->json([
            'message' => 'Public registration setting updated.',
            'data' => [
                'configured' => true,
                'is_active' => filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN),
            ],
        ]);
    }

    private function requireAdmin(): void
    {
        abort_unless(auth()->user()?->role === 'admin', 403, 'Forbidden');
    }
}
