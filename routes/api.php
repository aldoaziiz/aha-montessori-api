<?php

use App\Http\Controllers\API\ActivityController;
use App\Http\Controllers\API\ActivityPhotoController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BillingController;
use App\Http\Controllers\API\ChildController;
use App\Http\Controllers\API\ChildGuardianController;
use App\Http\Controllers\API\CityController;
use App\Http\Controllers\API\ClinicController;
use App\Http\Controllers\API\GuardianController;
use App\Http\Controllers\API\GuardianRoleController;
use App\Http\Controllers\API\MasterDataController;
use App\Http\Controllers\API\PayerController;
use App\Http\Controllers\API\ProgramController;
use App\Http\Controllers\API\PublicRegistrationController;
use App\Http\Controllers\API\RegistrationController;
use App\Http\Controllers\API\RoomController;
use App\Http\Controllers\API\SchoolClassController;
use App\Http\Controllers\API\SchoolController;
use App\Http\Controllers\API\SchoolEducationController;
use App\Http\Controllers\API\StaffController;
use App\Http\Controllers\API\StaffRoleController;
use App\Http\Controllers\API\TherapySessionController;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// ======================
// TEST
// ======================

Route::get('/test', function () {

    return response()->json([
        'message' => 'API jalan bro',
    ]);

});

Route::get('/ping', function () {

    return response()->json([
        'ok' => true,
    ]);

});

Route::get('/db-ping', function () {

    DB::select('SELECT 1');

    return response()->json([
        'ok' => true,
    ]);

});

// ======================
// PUBLIC
// ======================

// AUTH

Route::post(
    '/login',
    [AuthController::class, 'login']
);

// PUBLIC LOOKUP

Route::get(
    '/master-data',
    [MasterDataController::class, 'index']
);

Route::get(
    '/guardian-roles',
    [GuardianRoleController::class,
        'index']
);

Route::get(
    '/programs',
    [ProgramController::class,
        'index']
);

Route::get(
    '/payers',
    [PayerController::class,
        'index']
);

Route::get(
    '/cities',
    [CityController::class,
        'index']
);

Route::get(
    '/schools',
    [SchoolController::class,
        'index']
);

Route::get(
    '/school-classes',
    [SchoolClassController::class,
        'index']
);

Route::get(
    '/school-educations',
    [SchoolEducationController::class,
        'index']
);

Route::get(
    '/rooms',
    [RoomController::class,
        'index']
);

Route::get(
    '/public/billings/{token}',
    [BillingController::class, 'publicShowByToken']
);

Route::post(
    '/public/billings/{token}/upload-receipt',
    [BillingController::class, 'uploadReceiptByToken']
);

// PUBLIC UPLOAD

Route::post(
    '/invoice-upload/{token}',
    [RegistrationController::class,
        'uploadReceiptByToken']
)->middleware('throttle:10,1');

Route::get(
    '/invoice-upload/{token}',
    [RegistrationController::class,
        'invoiceByToken']
);

// PUBLIC REGISTRATION
Route::post(
    '/public-registrations',
    [PublicRegistrationController::class, 'store']
)->middleware('throttle:10,1');

// ======================
// PROTECTED
// ======================

Route::middleware([
    'auth:sanctum',
    'daily.session',
])->group(function () {

    // ======================
    // AUTH
    // ======================

    Route::get(
        '/me',
        [AuthController::class, 'me']
    );

    Route::post(
        '/logout',
        [AuthController::class, 'logout']
    );

    Route::post(
        '/registrations/{registration}/generate-invoice-link',
        [RegistrationController::class,
            'generateInvoiceLink']
    );

    Route::put(
        '/change-password',
        [AuthController::class, 'changePassword']
    );

    // ======================
    // MASTER DATA
    // ======================

    Route::apiResource(
        'children',
        ChildController::class
    );

    Route::apiResource(
        'guardians',
        GuardianController::class
    );

    Route::apiResource(
        'staff',
        StaffController::class
    );

    Route::apiResource(
        'staff-roles',
        StaffRoleController::class
    );

    Route::apiResource(
        'schools',
        SchoolController::class
    );

    Route::apiResource(
        'school-classes',
        SchoolClassController::class
    );

    Route::apiResource(
        'school-educations',
        SchoolEducationController::class
    );

    Route::apiResource(
        'clinics',
        ClinicController::class
    );

    Route::apiResource(
        'payers',
        PayerController::class
    );

    Route::apiResource(
        'programs',
        ProgramController::class
    );

    Route::patch(
        'programs/{program}/activate',
        [ProgramController::class, 'activate']
    );

    Route::patch(
        'programs/{program}/deactivate',
        [ProgramController::class, 'deactivate']
    );

    Route::apiResource(
        'guardian-roles',
        GuardianRoleController::class
    );

    Route::apiResource(
        'cities',
        CityController::class
    );

    Route::apiResource(
        'rooms',
        RoomController::class
    );

    // ======================
    // TRANSACTIONS
    // ======================

    Route::apiResource(
        'registrations',
        RegistrationController::class
    );

    Route::post(
        '/therapy-sessions/generate',
        [TherapySessionController::class, 'generate']
    );

    Route::get(
        'therapy-sessions/availability',
        [TherapySessionController::class, 'availability']
    );

    Route::put(
        'therapy-sessions/{id}/allow-late-activity',
        [TherapySessionController::class, 'allowLateActivity']
    );

    Route::patch(
        'therapy-sessions/{therapySession}/mark-alpha',
        [TherapySessionController::class, 'markAlpha']
    );

    Route::apiResource(
        'therapy-sessions',
        TherapySessionController::class
    );

    Route::apiResource(
        'activities',
        ActivityController::class
    );

    Route::post(
        '/billings/{id}/approve',
        [BillingController::class, 'approve']
    );

    Route::post(
        '/billings/{id}/reject',
        [BillingController::class, 'reject']
    );

    Route::get(
        '/billings/{id}/pdf',
        [BillingController::class, 'downloadPdf']
    );

    // ======================
    // REGISTRATION
    // ======================

    Route::get(
        '/registrations/{id}',
        [RegistrationController::class, 'show']
    );

    Route::post(
        '/registrations/{id}/upload-receipt',
        [RegistrationController::class, 'uploadReceipt']
    );

    Route::put(
        '/registrations/{id}/mark-paid',
        [RegistrationController::class, 'markPaid']
    );

    Route::get(
        '/registration-edit-master-data/{id}',
        [RegistrationController::class,
            'editMasterData']
    );

    Route::post(
        '/registrations/{id}/generate-billing',
        [BillingController::class, 'generateBilling']
    );

    Route::get(
        '/billings/{id}',
        [BillingController::class, 'show']
    );

    Route::post(
        '/billings/{id}/cancel',
        [BillingController::class, 'cancel']
    );

    // ======================
    // ACTIVITY
    // ======================

    Route::delete(
        '/activity-photos/{activityPhoto}',
        [ActivityPhotoController::class, 'destroy']
    );

    Route::delete(
        '/activities/{activity}/video',
        [ActivityController::class, 'deleteVideo']
    );

    // ======================
    // CHILD GUARDIANS
    // ======================

    Route::post(
        '/children/{child}/guardians',
        [ChildGuardianController::class, 'store']
    );

    Route::delete(
        '/children/{child}/guardians/{guardian}',
        [ChildGuardianController::class, 'destroy']
    );

});
