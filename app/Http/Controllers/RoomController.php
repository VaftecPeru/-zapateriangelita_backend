<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index()
    {
        return response()->json(Room::with('property')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'room_number' => 'required|string',
            'type' => 'required|string',
            'price' => 'required|numeric',
            'status' => 'string',
            'description' => 'nullable|string',
        ]);

        $room = Room::create($validated);
        return response()->json($room, 201);
    }

    public function show(Room $room)
    {
        return response()->json($room->load('property'));
    }

    public function update(Request $request, Room $room)
    {
        $validated = $request->validate([
            'property_id' => 'exists:properties,id',
            'room_number' => 'string',
            'type' => 'string',
            'price' => 'numeric',
            'status' => 'string',
            'description' => 'nullable|string',
        ]);

        $room->update($validated);
        return response()->json($room);
    }

    public function destroy(Room $room)
    {
        $room->delete();
        return response()->json(null, 204);
    }
}
