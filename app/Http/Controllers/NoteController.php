<?php

namespace App\Http\Controllers;

use App\Models\Note;
use Illuminate\Http\Request;
use App\Http\Resources\NoteResource;
use Illuminate\Support\Facades\Validator;


class NoteController extends Controller
{
    public function index()
    {
        $notes = Note::all();
        return NoteResource::collection($notes);
    }

    public function store(Request $request)
    {
        if (!$request->filled('notes')) {
            return response()->json([
                'success' => false,
                'message' => 'Notes field is required',
            ], 200); 
        }

        $data = [
            'notes' => str_replace('"', "'", $request->notes),
        ];

        $note = Note::create($data);

        return new NoteResource($note);
    }


    public function show($id)
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found',
            ], 200); 
        }

        return new NoteResource($note);
    }


    public function update(Request $request, $id)
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found',
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 200);
        }

        $data = $validator->validated();
        $data['notes'] = str_replace('"', "'", $data['notes']);

        $note->update($data);

        return new NoteResource($note);
    }


    public function destroy($id)
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found',
            ], 200);
        }

        $note->delete();

        return response()->json([
            'success' => true,
            'message' => 'Note deleted successfully',
        ], 200);
    }

}
