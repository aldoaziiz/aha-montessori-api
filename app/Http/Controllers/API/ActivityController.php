<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityMedia;
use App\Models\Child;
use App\Models\ProgramCategorySessionTime;
use App\Models\TherapySession;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ActivityController extends Controller
{
    private function forbidGuardian()
    {
        if (auth()->user()->role === 'guardian') {
            abort(403, 'Forbidden');
        }
    }

    public function index(Request $request)
    {
        $user = auth()->user();

        $perPage = (int) $request->input(
            'per_page',
            10
        );

        $perPage = max(
            1,
            min($perPage, 20)
        );

        $search = trim(
            (string) $request->input(
                'search',
                ''
            )
        );

        $query = Activity::with([
            'contentType:id,name',
            'programCategory:id,name',
            'programCategorySessionTime:id,session_name,start_time,end_time',
            'staff:id,name',
            'children:id,name,nickname',
            'media',
        ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // ======================
        // GUARDIAN FILTER
        // ======================

        if ($user->role === 'guardian') {
            $guardian = $user->guardian;

            $childIds = $guardian
                ->children()
                ->pluck('children.id');

            $query->whereHas(
                'children',
                function ($q) use ($childIds) {
                    $q->whereIn(
                        'children.id',
                        $childIds
                    );
                }
            );
        }

        // ======================
        // SEARCH CHILD
        // ======================

        if ($search !== '') {
            $query->whereHas(
                'children',
                function ($q) use ($search) {
                    $q->where(
                        'name',
                        'like',
                        '%'.$search.'%'
                    );
                }
            );
        }

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $this->forbidGuardian();

        $validated = $request->validate([

            'program_category_id' => [
                'required',
                'exists:program_categories,id',
            ],

            'activity_content_type_id' => [
                'required',
                'exists:activity_content_types,id',
            ],

            'therapy_date' => [
                'required',
                'date',
            ],

            'program_category_session_time_id' => [
                'required',
                Rule::exists(
                    'program_category_session_times',
                    'id'
                )->where(function ($query) use ($request) {
                    $query->where(
                        'program_category_id',
                        $request->input('program_category_id')
                    );
                }),
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'child_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'child_ids.*' => [
                'required',
                'integer',
                'exists:children,id',
            ],

            'photos' => [
                'nullable',
                'array',
                'max:10',
            ],

            'photos.*' => [
                'image',
                'max:5120',
            ],

            'video' => [
                'nullable',
                'file',
                'mimetypes:video/mp4,video/quicktime,video/x-msvideo',
                'max:102400',
            ],

        ]);

        $children = collect(
            $validated['child_ids']
        )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $matchedChildCount = Child::query()
            ->whereIn('id', $children)
            ->where(
                'program_category_id',
                $validated['program_category_id']
            )
            ->where('status_id', 1)
            ->count();

        if ($matchedChildCount !== $children->count()) {
            return response()->json([
                'message' => 'Selected children do not match the activity program category or are inactive.',
            ], 422);
        }

        $description = trim(
            $validated['description'] ?? ''
        );

        if (
            $description === '' &&
            ! $request->hasFile('video') &&
            ! $request->hasFile('photos')
        ) {
            return response()->json([
                'message' => 'Description, photo, or video is required.',
            ], 422);
        }

        $disk = Storage::disk(
            config('filesystems.default')
        );

        $uploadedMedia = [];
        $sortOrder = 1;

        try {

            // ======================
            // UPLOAD PHOTOS FIRST
            // ======================

            if ($request->hasFile('photos')) {

                foreach ($request->file('photos') as $photo) {

                    $fileName =
                        Str::random(40).
                        '.'.
                        $photo->getClientOriginalExtension();

                    /*
                     * Ambil metadata sebelum file dipindahkan.
                     */
                    $mimeType = $photo->getMimeType();
                    $fileSize = $photo->getSize();

                    $path = $disk->putFileAs(
                        'montessori/activities',
                        $photo,
                        $fileName
                    );

                    if (! $path) {
                        throw new \RuntimeException(
                            'Failed to upload activity photo.'
                        );
                    }

                    $uploadedMedia[] = [
                        'media_type' => 'photo',
                        'mime_type' => $mimeType,
                        'file_name' => $fileName,
                        'file_path' => $path,
                        'file_size' => $fileSize,
                        'sort_order' => $sortOrder++,
                    ];
                }
            }

            // ======================
            // UPLOAD VIDEO FIRST
            // ======================

            if ($request->hasFile('video')) {

                $video = $request->file('video');

                $fileName =
                    Str::random(40).
                        '.'.
                        $video->getClientOriginalExtension();

                $mimeType = $video->getMimeType();
                $fileSize = $video->getSize();

                $path = $disk->putFileAs(
                    'montessori/activities',
                    $video,
                    $fileName
                );

                if (! $path) {
                    throw new \RuntimeException(
                        'Failed to upload activity video.'
                    );
                }

                $uploadedMedia[] = [
                    'media_type' => 'video',
                    'mime_type' => $mimeType,
                    'file_name' => $fileName,
                    'file_path' => $path,
                    'file_size' => $fileSize,
                    'sort_order' => $sortOrder++,
                ];
            }

            // ======================
            // DATABASE TRANSACTION
            // ======================

            DB::transaction(function () use (
                $validated,
                $children,
                $description,
                $uploadedMedia
            ) {
                $activity = Activity::create([
                    'activity_content_type_id' => $validated['activity_content_type_id'],

                    'program_category_id' => $validated['program_category_id'],

                    'therapy_date' => $validated['therapy_date'],

                    'program_category_session_time_id' => $validated[
                            'program_category_session_time_id'
                        ],

                    'staff_id' => auth()->user()->staff->id,

                    'description' => $description !== ''
                            ? $description
                            : null,
                ]);

                $activity
                    ->children()
                    ->attach($children->all());

                $this->recalculateAttendanceStatuses(
                    $activity->therapy_date,
                    $activity->program_category_session_time_id,
                    $children
                );

                /*
                 * File sudah berhasil diunggah.
                 * Di dalam transaksi kita hanya membuat record DB.
                 */
                foreach ($uploadedMedia as $media) {
                    ActivityMedia::create([
                        'activity_id' => $activity->id,
                        ...$media,
                    ]);
                }
            });

        } catch (\Throwable $exception) {

            /*
             * Upload atau transaksi DB gagal.
             * Bersihkan seluruh file baru yang sempat tersimpan.
             */
            foreach ($uploadedMedia as $media) {

                $filePath =
                    $media['file_path'] ?? null;

                if (! $filePath) {
                    continue;
                }

                try {
                    if ($disk->exists($filePath)) {
                        $disk->delete($filePath);
                    }
                } catch (\Throwable $cleanupException) {
                    /*
                     * Jangan menutupi exception utama apabila
                     * proses cleanup storage juga gagal.
                     */
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Activity created successfully.',
        ]);
    }

    public function show(Activity $activity)
    {
        $this->forbidGuardian();

        $activity->load([

            'children',

            'media',

            'programCategory',

            'contentType',

            'programCategorySessionTime',

        ]);

        return response()->json([

            'data' => $activity,

        ]);
    }

    public function update(
        Request $request,
        Activity $activity
    ) {
        $this->forbidGuardian();

        $activity->load([
            'children',
            'media',
            'programCategorySessionTime',
        ]);

        $validated = $request->validate([

            'description' => [
                'nullable',
                'string',
            ],

            'child_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'child_ids.*' => [
                'required',
                'integer',
                'exists:children,id',
            ],

            'removed_media_ids' => [
                'nullable',
                'array',
            ],

            'removed_media_ids.*' => [
                'integer',

                Rule::exists(
                    'activity_media',
                    'id'
                )->where(function ($query) use ($activity) {

                    $query->where(
                        'activity_id',
                        $activity->id
                    );

                }),
            ],

            'photos' => [
                'nullable',
                'array',
            ],

            'photos.*' => [
                'image',
                'max:5120',
            ],

            'video' => [
                'nullable',
                'file',
                'mimetypes:video/mp4,video/quicktime,video/x-msvideo',
                'max:102400',
            ],

        ]);

        /*
         * Pastikan seluruh child masih berasal dari
         * Program Category milik Activity.
         */
        $newChildIds = collect(
            $validated['child_ids']
        )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $matchedChildCount = Child::query()

            ->whereIn(
                'id',
                $newChildIds
            )

            ->where(
                'program_category_id',
                $activity->program_category_id
            )

            ->count();

        if (
            $matchedChildCount !==
            $newChildIds->count()
        ) {

            return response()->json([
                'message' => 'Selected children do not match the activity program category.',
            ], 422);

        }

        $removedMediaIds = collect(
            $validated['removed_media_ids'] ?? []
        )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        /*
         * Periksa jumlah foto setelah update.
         */
        $remainingPhotoCount = $activity
            ->media
            ->where('media_type', 'photo')
            ->whereNotIn(
                'id',
                $removedMediaIds
            )
            ->count();

        $newPhotoCount = count(
            $request->file('photos', [])
        );

        if (
            $remainingPhotoCount +
            $newPhotoCount >
            10
        ) {

            return response()->json([
                'message' => 'Maximum 10 photos are allowed.',
            ], 422);

        }

        /*
         * Pastikan setelah update Activity masih memiliki
         * description, photo, atau video.
         */
        $remainingMedia = $activity
            ->media
            ->whereNotIn(
                'id',
                $removedMediaIds
            );

        $description = trim(
            $validated['description'] ?? ''
        );

        $hasDescription =
        $description !== '';

        $hasRemainingPhotos = $remainingMedia
            ->where('media_type', 'photo')
            ->isNotEmpty();

        $hasRemainingVideo = $remainingMedia
            ->where('media_type', 'video')
            ->isNotEmpty();

        $hasNewPhotos =
            $request->hasFile('photos');

        $hasNewVideo =
            $request->hasFile('video');

        if (
            ! $hasDescription &&
            ! $hasRemainingPhotos &&
            ! $hasRemainingVideo &&
            ! $hasNewPhotos &&
            ! $hasNewVideo
        ) {

            return response()->json([
                'message' => 'Description, photo, or video is required.',
            ], 422);

        }

        if (! $activity->programCategorySessionTime) {

            return response()->json([
                'message' => 'Activity session information was not found.',
            ], 422);

        }

        $disk = Storage::disk(
            config('filesystems.default')
        );

        /*
         * Gabungkan media yang dipilih untuk dihapus
         * dengan video lama jika ada video pengganti.
         */
        $mediaIdsToDelete = $removedMediaIds;

        if ($request->hasFile('video')) {
            $existingVideoIds = $activity
                ->media
                ->where('media_type', 'video')
                ->pluck('id');

            $mediaIdsToDelete = $mediaIdsToDelete
                ->merge($existingVideoIds)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        /*
         * Simpan path file lama.
         * File fisik baru dihapus setelah transaksi berhasil.
         */
        $oldFilePaths = $activity
            ->media
            ->whereIn('id', $mediaIdsToDelete)
            ->pluck('file_path')
            ->filter()
            ->unique()
            ->values();

        /*
         * Tentukan sort order berdasarkan media yang tetap ada.
         */
        $remainingExistingMedia = $activity
            ->media
            ->whereNotIn('id', $mediaIdsToDelete);

        $sortOrder = (
            $remainingExistingMedia->max('sort_order')
            ?? 0
        ) + 1;

        $uploadedMedia = [];

        try {

            // ======================
            // UPLOAD NEW PHOTOS
            // ======================

            if ($request->hasFile('photos')) {
                foreach (
                    $request->file('photos') as $photo
                ) {
                    $fileName =
                        Str::random(40).
                        '.'.
                        $photo->getClientOriginalExtension();

                    /*
                     * Ambil metadata sebelum upload.
                     */
                    $mimeType = $photo->getMimeType();
                    $fileSize = $photo->getSize();

                    $path = $disk->putFileAs(
                        'montessori/activities',
                        $photo,
                        $fileName
                    );

                    if (! $path) {
                        throw new \RuntimeException(
                            'Failed to upload activity photo.'
                        );
                    }

                    $uploadedMedia[] = [
                        'media_type' => 'photo',
                        'mime_type' => $mimeType,
                        'file_name' => $fileName,
                        'file_path' => $path,
                        'file_size' => $fileSize,
                        'sort_order' => $sortOrder++,
                    ];
                }
            }

            // ======================
            // UPLOAD NEW VIDEO
            // ======================

            if ($request->hasFile('video')) {
                $video = $request->file('video');

                $fileName =
                    Str::random(40).
                    '.'.
                    $video->getClientOriginalExtension();

                $mimeType = $video->getMimeType();
                $fileSize = $video->getSize();

                $path = $disk->putFileAs(
                    'montessori/activities',
                    $video,
                    $fileName
                );

                if (! $path) {
                    throw new \RuntimeException(
                        'Failed to upload activity video.'
                    );
                }

                $uploadedMedia[] = [
                    'media_type' => 'video',
                    'mime_type' => $mimeType,
                    'file_name' => $fileName,
                    'file_path' => $path,
                    'file_size' => $fileSize,
                    'sort_order' => $sortOrder++,
                ];
            }
            DB::transaction(function () use (
                $description,
                $activity,
                $newChildIds,
                $mediaIdsToDelete,
                $uploadedMedia
            ) {
                $oldChildIds = $activity
                    ->children
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id);

                // ======================
                // UPDATE ACTIVITY
                // ======================

                $activity->update([
                    'description' => $description !== ''
                            ? $description
                            : null,
                ]);

                // ======================
                // SYNC CHILDREN
                // ======================

                $activity
                    ->children()
                    ->sync($newChildIds);

                // ======================
                // RECALCULATE ATTENDANCE
                // ======================

                $affectedChildIds = $oldChildIds
                    ->merge($newChildIds)
                    ->unique()
                    ->values();

                $this->recalculateAttendanceStatuses(
                    $activity->therapy_date,
                    $activity
                        ->program_category_session_time_id,
                    $affectedChildIds
                );

                // ======================
                // DELETE OLD MEDIA RECORDS
                // ======================

                if ($mediaIdsToDelete->isNotEmpty()) {
                    $activity
                        ->media()
                        ->whereIn(
                            'id',
                            $mediaIdsToDelete
                        )
                        ->delete();
                }

                // ======================
                // CREATE NEW MEDIA RECORDS
                // ======================

                foreach ($uploadedMedia as $media) {
                    ActivityMedia::create(
                        array_merge(
                            [
                                'activity_id' => $activity->id,
                            ],
                            $media
                        )
                    );
                }
            });
        } catch (\Throwable $exception) {

            /*
             * Upload atau transaksi database gagal.
             * Bersihkan file baru yang sempat tersimpan.
             */
            foreach ($uploadedMedia as $media) {
                $filePath =
                    $media['file_path'] ?? null;

                if (! $filePath) {
                    continue;
                }

                try {
                    if ($disk->exists($filePath)) {
                        $disk->delete($filePath);
                    }
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        /*
         * Database sudah berhasil commit.
         * Sekarang file lama aman untuk dihapus.
         */
        foreach ($oldFilePaths as $filePath) {
            try {
                if ($disk->exists($filePath)) {
                    $deleted = $disk->delete(
                        $filePath
                    );

                    if (! $deleted) {
                        report(
                            new \RuntimeException(
                                "Failed to delete old activity media: {$filePath}"
                            )
                        );
                    }
                }
            } catch (\Throwable $cleanupException) {
                /*
                 * Jangan membatalkan update yang sudah commit.
                 * Kegagalan ini hanya menghasilkan orphan file,
                 * bukan record database yang rusak.
                 */
                report($cleanupException);
            }
        }

        return response()->json([
            'message' => 'Activity updated successfully.',
        ]);
    }

    public function destroy(Activity $activity)
    {
        $this->forbidGuardian();

        $activity->load([
            'media',
            'children',
        ]);

        $childIds = $activity
            ->children
            ->pluck('id');

        $therapyDate =
            $activity->therapy_date;

        $sessionTimeId =
            $activity->program_category_session_time_id;

        if (! $sessionTimeId) {
            return response()->json([
                'message' => 'Activity session information was not found.',
            ], 422);
        }

        /*
         * Simpan lokasi file sebelum record media dihapus.
         */
        $filePaths = $activity
            ->media
            ->pluck('file_path')
            ->filter()
            ->values();

        DB::transaction(function () use (
            $activity,
            $childIds,
            $therapyDate,
            $sessionTimeId
        ) {
            /*
             * Hapus seluruh data database terlebih dahulu.
             */
            $activity->media()->delete();
            $activity->children()->detach();
            $activity->delete();

            /*
             * Activity sudah tidak ada saat status attendance
             * dihitung ulang.
             */
            $this->recalculateAttendanceStatuses(
                $therapyDate,
                $sessionTimeId,
                $childIds
            );
        });

        /*
         * Database transaction sudah berhasil.
         * Baru hapus file fisik.
         */
        $disk = Storage::disk(
            config('filesystems.default')
        );

        foreach ($filePaths as $filePath) {
            if ($disk->exists($filePath)) {
                $disk->delete($filePath);
            }
        }

        return response()->json([
            'message' => 'Activity deleted successfully.',
        ]);
    }

    public function downloadMedia(ActivityMedia $activityMedia)
    {
        $user = auth()->user();

        if ($user->role === 'guardian') {
            $guardian = $user->guardian;

            $canDownload = $guardian && $activityMedia
                ->activity()
                ->whereHas(
                    'children',
                    function ($query) use ($guardian) {
                        $query->whereHas(
                            'guardians',
                            function ($guardianQuery) use ($guardian) {
                                $guardianQuery->where(
                                    'guardians.id',
                                    $guardian->id
                                );
                            }
                        );
                    }
                )
                ->exists();

            if (! $canDownload) {
                abort(403, 'Forbidden');
            }
        }

        $disk = Storage::disk(
            config('filesystems.default')
        );

        if (! $disk->exists($activityMedia->file_path)) {
            abort(404, 'Activity media file was not found.');
        }

        return $disk->download(
            $activityMedia->file_path,
            $activityMedia->file_name ?: 'activity-media-'.$activityMedia->id
        );
    }

    public function children(Request $request)
    {
        $request->validate([
            'program_category_id' => [
                'required',
                'exists:program_categories,id',
            ],
        ]);

        $children = Child::where('status_id', 1)
            ->whereHas(
                'latestRegistration',
                function ($query) use ($request) {

                    $query->where(
                        'program_category_id',
                        $request->program_category_id
                    );

                }
            )
            ->select([
                'id',
                'name',
                'nickname',
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $children,
        ]);
    }

    private function recalculateAttendanceStatuses(
        string $therapyDate,
        int $sessionTimeId,
        Collection $childIds
    ): void {
        $childIds = $childIds
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($childIds->isEmpty()) {
            return;
        }

        $sessionTime = ProgramCategorySessionTime::find(
            $sessionTimeId
        );

        if (! $sessionTime) {
            return;
        }

        /*
         * Cari anak yang masih tercantum dalam minimal
         * satu Activity lain pada tanggal dan sesi yang sama.
         */
        $completedChildIds = Activity::query()
            ->whereDate(
                'therapy_date',
                $therapyDate
            )
            ->where(
                'program_category_session_time_id',
                $sessionTimeId
            )
            ->whereHas(
                'children',
                function ($query) use ($childIds) {
                    $query->whereIn(
                        'children.id',
                        $childIds
                    );
                }
            )
            ->with([
                'children' => function ($query) use ($childIds) {
                    $query
                        ->select('children.id')
                        ->whereIn(
                            'children.id',
                            $childIds
                        );
                },
            ])
            ->get()
            ->flatMap(
                fn ($activity) => $activity->children->pluck('id')
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $scheduledChildIds = $childIds
            ->diff($completedChildIds)
            ->values();

        /*
         * Anak yang masih punya Activity:
         * Completed.
         */
        if ($completedChildIds->isNotEmpty()) {
            TherapySession::query()
                ->whereDate(
                    'therapy_date',
                    $therapyDate
                )
                ->where(
                    'start_time',
                    $sessionTime->start_time
                )
                ->where(
                    'end_time',
                    $sessionTime->end_time
                )
                ->whereHas(
                    'registration',
                    function ($query) use (
                        $completedChildIds
                    ) {
                        $query->whereIn(
                            'child_id',
                            $completedChildIds
                        );
                    }
                )
                ->update([
                    'therapy_session_status_id' => 2,
                ]);
        }

        /*
         * Anak yang tidak lagi punya Activity:
         * Scheduled.
         */
        if ($scheduledChildIds->isNotEmpty()) {
            TherapySession::query()
                ->whereDate(
                    'therapy_date',
                    $therapyDate
                )
                ->where(
                    'start_time',
                    $sessionTime->start_time
                )
                ->where(
                    'end_time',
                    $sessionTime->end_time
                )
                ->whereHas(
                    'registration',
                    function ($query) use (
                        $scheduledChildIds
                    ) {
                        $query->whereIn(
                            'child_id',
                            $scheduledChildIds
                        );
                    }
                )
                ->update([
                    'therapy_session_status_id' => 1,
                ]);
        }
    }
}
