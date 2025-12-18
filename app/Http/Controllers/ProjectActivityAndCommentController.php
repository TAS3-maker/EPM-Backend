<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectActivityAndCommentResource;
use App\Models\ProjectActivityAndComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ProjectActivityAndCommentController extends Controller
{

    public function show($id = null)
    {
        if (!$id) {
            return response()->json([
                'success' => false,
                'message' => 'ID is required',
            ], 200);
        }

        $activity = ProjectActivityAndComment::find($id);

        if (!$activity) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found',
            ], 200);
        }

        return response()->json([
            'success' => true,
            'data' => new ProjectActivityAndCommentResource($activity),
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|integer',
            'user_id' => 'required|integer',
            'type' => 'required|string',
            'description' => 'nullable|string',
            'attachments' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 200);
        }

        if (!$request->project_id || !$request->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Project ID or User ID is missing',
            ], 200);
        }

        try {
            $attachmentValue = null;

            if ($request->hasFile('attachments')) {

                $file = $request->file('attachments');

                $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];

                if (!in_array($file->getClientOriginalExtension(), $allowedExtensions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid attachment type',
                    ], 200);
                }

                if (!Storage::disk('public')->exists('project_attachments')) {
                    Storage::disk('public')->makeDirectory('project_attachments');
                }

                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('project_attachments', $filename, 'public');

                $attachmentValue = 'project_attachments/' . $filename;
            } elseif ($request->attachments && filter_var($request->attachments, FILTER_VALIDATE_URL)) {
                $attachmentValue = $request->attachments;
            }


            $activity = ProjectActivityAndComment::create([
                'project_id' => $request->project_id,
                'user_id' => $request->user_id,
                'type' => $request->type,
                'description' => $request->description,
                'attachments' => $attachmentValue,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Activity created successfully',
                'data' => new ProjectActivityAndCommentResource($activity),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ], 200);
        }
    }



    public function index(Request $request)
    {
        if (!$request->project_id) {
            return response()->json([
                'success' => false,
                'message' => 'Project ID is required',
            ], 200);
        }

        $activities = ProjectActivityAndComment::where(
            'project_id',
            $request->project_id
        )->latest()->get();

        if ($activities->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No activity found',
                'data' => [],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'data' => ProjectActivityAndCommentResource::collection($activities),
        ], 200);
    }

    public function update(Request $request, $id = null)
    {
        if (!$id) {
            return response()->json([
                'success' => false,
                'message' => 'ID is required',
            ], 200);
        }

        $activity = ProjectActivityAndComment::find($id);

        if (!$activity) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found',
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'project_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'type' => 'nullable|string',
            'description' => 'nullable|string',
            'attachments' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 200);
        }

        try {

            if ($request->hasFile('attachments')) {

                $file = $request->file('attachments');
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];

                if (!in_array($file->getClientOriginalExtension(), $allowedExtensions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid attachment type',
                    ], 200);
                }

                if ($activity->attachments && !filter_var($activity->attachments, FILTER_VALIDATE_URL)) {
                    if (Storage::disk('public')->exists($activity->attachments)) {
                        Storage::disk('public')->delete($activity->attachments);
                    }
                }

                if (!Storage::disk('public')->exists('project_attachments')) {
                    Storage::disk('public')->makeDirectory('project_attachments');
                }

                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('project_attachments', $filename, 'public');

                $activity->attachments = 'project_attachments/' . $filename;
            } elseif ($request->attachments && filter_var($request->attachments, FILTER_VALIDATE_URL)) {

                if ($activity->attachments && !filter_var($activity->attachments, FILTER_VALIDATE_URL)) {
                    if (Storage::disk('public')->exists($activity->attachments)) {
                        Storage::disk('public')->delete($activity->attachments);
                    }
                }

                $activity->attachments = $request->attachments;
            }

            $activity->project_id = $request->project_id ?? $activity->project_id;
            $activity->user_id = $request->user_id ?? $activity->user_id;
            $activity->type = $request->type ?? $activity->type;
            $activity->description = $request->description ?? $activity->description;

            $activity->save();

            return response()->json([
                'success' => true,
                'message' => 'Activity updated successfully',
                'data' => new ProjectActivityAndCommentResource($activity),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ], 200);
        }
    }


    public function destroy($id = null)
    {
        if (!$id) {
            return response()->json([
                'success' => false,
                'message' => 'ID is required',
            ], 200);
        }

        $activity = ProjectActivityAndComment::find($id);

        if (!$activity) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found',
            ], 200);
        }

        $activity->delete();

        return response()->json([
            'success' => true,
            'message' => 'Record deleted successfully',
        ], 200);
    }
}
