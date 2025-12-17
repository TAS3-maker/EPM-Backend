<?php

namespace App\Http\Controllers;

use App\Models\Note;
use Illuminate\Http\Request;
use App\Http\Resources\NoteResource;

class NoteController extends Controller
{
    // GET /notes
    public function index()
    {
        $notes = Note::all();
        return NoteResource::collection($notes);
    }

    // POST /notes
    public function store(Request $request)
    {
        $data = $request->validate([
            'notes' => 'required|string',
        ]);

        $note = Note::create($data);

        return new NoteResource($note);
    }

    // GET /notes/{id}
    public function show(Note $note)
    {
        return new NoteResource($note);
    }

    // PUT/PATCH /notes/{id}
    public function update(Request $request, Note $note)
    {
        $data = $request->validate([
            'notes' => 'required|string',
        ]);

        $note->update($data);

        return new NoteResource($note);
    }

    // DELETE /notes/{id}
    public function destroy(Note $note)
    {
        $note->delete();
        return response()->json(['message' => 'Note deleted successfully']);
    }
}
