<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\TherapySession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ActivityController extends Controller
{
    private function forbidGuardian()
    {
        if (
            auth()->user()->role ===
            'guardian'
        ) {

            abort(
                403,
                'Forbidden'
            );

        }
    }

    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Activity::with([
            'photos',
            'therapySession.registration.child',
            'therapySession.registration.programs',
            'therapySession.therapist',
        ])
            ->join(
                'therapy_sessions',
                'activities.therapy_session_id',
                '=',
                'therapy_sessions.id'
            )
            ->orderByDesc(
                'therapy_sessions.therapy_date'
            )
            ->orderByDesc(
                'therapy_sessions.start_time'
            )
            ->select('activities.*');

        // ======================
        // GUARDIAN FILTER
        // ======================

        if ($user->role === 'guardian') {

            $guardian =
                $user->guardian;

            $childIds =
                $guardian
                    ->children()
                    ->pluck('children.id');

            $query->whereHas(
                'therapySession.registration',
                function ($q) use ($childIds) {

                    $q->whereIn(
                        'child_id',
                        $childIds
                    );

                }
            );

        }

        // ======================
        // THERAPIST FILTER
        // ======================

        if ($user->role === 'therapist') {

            $query->whereHas(
                'therapySession',
                function ($q) use ($user) {

                    $q->where(
                        'therapist_id',
                        $user->staff->id
                    );

                }
            );

        }

        // ======================
        // SEARCH CHILD
        // ======================

        if ($request->search) {

            $query->whereHas(
                'therapySession.registration.child',
                function ($q) use ($request) {

                    $q->where(
                        'name',
                        'like',
                        '%'.$request->search.'%'
                    );
                }
            );
        }

        return response()->json(
            $query->paginate(
                $request->per_page ?? 10
            )
        );
    }

    public function store(Request $request)
    {
        $this->forbidGuardian();

        // ======================
        // VALIDATION
        // ======================

        $request->validate([

            'therapy_session_id' => [
                'required',
                'exists:therapy_sessions,id',
                'unique:activities,therapy_session_id',
            ],

            'caption' => 'nullable|string',

            'video' => [
                'nullable',
                'file',
                'mimetypes:video/mp4,video/quicktime,video/x-msvideo',
                'max:102400',
            ],

            'photos.*' => 'nullable|image|max:5120',

        ]);

        // ======================
        // SESSION STATUS
        // ======================

        $therapySession = TherapySession::findOrFail(
            $request->therapy_session_id
        );

        if ($therapySession->therapy_session_status_id === 3) {

            return response()->json([
                'message' => 'Cannot create activity for Alpha session.',
            ], 422);
        }

        // ======================
        // MINIMUM CONTENT
        // ======================

        if (
            ! $request->caption &&
            ! $request->hasFile('video') &&
            ! $request->hasFile('photos')
        ) {

            return response()->json([
                'message' => 'Caption, photo, or video is required.',
            ], 422);
        }

        // ======================
        // UPLOAD VIDEO
        // ======================

        $videoPath = null;

        if ($request->hasFile('video')) {

            $videoPath = $request
                ->file('video')
                ->store(
                    'activities/videos'
                );
        }

        // ======================
        // CREATE ACTIVITY
        // ======================

        $activity = Activity::create([

            'therapy_session_id' => $request->therapy_session_id,

            'caption' => $request->caption,

            'video' => $videoPath,

        ]);

        $activity->therapySession()->update([
            'therapy_session_status_id' => 2,
        ]);

        // ======================
        // UPLOAD PHOTOS
        // ======================

        if ($request->hasFile('photos')) {

            foreach (
                $request->file('photos') as $photo
            ) {

                $photoPath = $photo->store(
                    'activities/photos'
                );

                ActivityPhoto::create([

                    'activity_id' => $activity->id,

                    'photo' => $photoPath,

                ]);
            }
        }

        // ======================
        // RESPONSE
        // ======================

        return response()->json([
            'message' => 'Activity created successfully',

            'data' => $activity->load([
                'photos',
                'therapySession.registration.child',
                'therapySession.registration.programs',
                'therapySession.therapist',
            ]),
        ]);
    }

    public function destroy(Activity $activity)
    {
        $this->forbidGuardian();

        // ======================
        // THERAPIST OWNERSHIP
        // ======================

        if (
            auth()->user()->role ===
            'therapist'
        ) {

            if (

                $activity
                    ->therapySession
                    ->therapist_id

                !==

                auth()->user()
                    ->staff
                    ->id

            ) {

                abort(
                    403,
                    'Forbidden'
                );

            }

        }

        // ======================
        // DELETE PHOTOS
        // ======================

        foreach ($activity->photos as $photo) {

            Storage::delete($photo->photo);
        }

        // ======================
        // DELETE VIDEO
        // ======================

        if ($activity->video) {

            Storage::delete($activity->video);
        }

        // ======================
        // UPDATE SESSION STATUS
        // ======================

        $activity->therapySession()->update([
            'therapy_session_status_id' => 1,
        ]);

        // ======================
        // DELETE ACTIVITY
        // ======================

        $activity->delete();

        return response()->json([
            'message' => 'Activity deleted successfully',
        ]);
    }

    public function show(Activity $activity)
    {
        return response()->json([

            'data' => $activity->load([
                'photos',
                'therapySession.registration.child',
                'therapySession.registration.programs',
                'therapySession.therapist',
            ]),

        ]);
    }

    public function update(Request $request, Activity $activity)
    {

        $this->forbidGuardian();

        // ======================
        // VALIDATION
        // ======================

        $request->validate([

            'caption' => 'nullable|string',

            'video' => [
                'nullable',
                'file',
                'mimetypes:video/mp4,video/quicktime,video/x-msvideo',
                'max:102400',
            ],

            'photos.*' => 'nullable|image|max:5120',

        ]);

        // ======================
        // SESSION STATUS
        // ======================

        $therapySession = $activity->therapySession;

        if ($therapySession->therapy_session_status_id === 3) {

            return response()->json([
                'message' => 'Cannot update activity for Alpha session.',
            ], 422);
        }

        if (
            auth()->user()->role ===
            'therapist'
        ) {

            if (

                $activity
                    ->therapySession
                    ->therapist_id

                !==

                auth()->user()
                    ->staff
                    ->id

            ) {

                abort(403);

            }

        }

        // ======================
        // UPDATE VIDEO
        // ======================

        $videoPath = $activity->video;

        if ($request->hasFile('video')) {

            // DELETE OLD VIDEO

            if ($activity->video) {

                Storage::delete($activity->video);
            }

            // STORE NEW VIDEO

            $videoPath = $request
                ->file('video')
                ->store(
                    'activities/videos'
                );
        }

        // ======================
        // UPDATE ACTIVITY
        // ======================

        $activity->update([

            'caption' => $request->caption,

            'video' => $videoPath,

        ]);

        // ======================
        // ADD NEW PHOTOS
        // ======================

        if ($request->hasFile('photos')) {

            foreach (
                $request->file('photos') as $photo
            ) {

                $photoPath = $photo->store(
                    'activities/photos'
                );

                ActivityPhoto::create([

                    'activity_id' => $activity->id,

                    'photo' => $photoPath,

                ]);
            }
        }

        // ======================
        // RESPONSE
        // ======================

        return response()->json([

            'message' => 'Activity updated successfully',

            'data' => $activity->load([

                'photos',

                'therapySession.registration.child',

                'therapySession.registration.programs',

                'therapySession.therapist',

            ]),

        ]);
    }

    public function deleteVideo(Activity $activity)
    {

        $this->forbidGuardian();

        // ======================
        // THERAPIST OWNERSHIP
        // ======================

        if (
            auth()->user()->role ===
            'therapist'
        ) {

            if (

                $activity
                    ->therapySession
                    ->therapist_id

                !==

                auth()->user()
                    ->staff
                    ->id

            ) {

                abort(
                    403,
                    'Forbidden'
                );

            }

        }

        // ======================
        // DELETE FILE
        // ======================

        if ($activity->video) {

            Storage::delete($activity->video);
        }

        // ======================
        // UPDATE DB
        // ======================

        $activity->update([
            'video' => null,
        ]);

        return response()->json([

            'message' => 'Video deleted successfully',

        ]);
    }
}
