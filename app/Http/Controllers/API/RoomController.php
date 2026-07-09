<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        $query = Room::with([
            'status:id,name',
        ]);

        // SEARCH
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('description', 'like', '%'.$request->search.'%');
            });
        }

        return $query->paginate($request->per_page ?? 10);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $room = Room::with(['status'])
            ->create($request->all());

        return response()->json($room, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $room = Room::find($id);

        if (! $room) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($room);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $room = Room::find($id);

        if (! $room) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $room->update($request->all());

        return response()->json($room);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $room = Room::with(['status'])
            ->find($id);

        if (! $room) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $room->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
