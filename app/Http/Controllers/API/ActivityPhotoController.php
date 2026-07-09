<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityPhoto;
use Illuminate\Support\Facades\Storage;

class ActivityPhotoController extends Controller
{
    public function destroy(
        ActivityPhoto $activityPhoto
    ) {
        // ======================
        // DELETE FILE
        // ======================

        Storage::delete(
            $activityPhoto->photo
        );

        // ======================
        // DELETE DB
        // ======================

        $activityPhoto->delete();

        return response()->json([

            'message' => 'Photo deleted successfully',

        ]);
    }
}
