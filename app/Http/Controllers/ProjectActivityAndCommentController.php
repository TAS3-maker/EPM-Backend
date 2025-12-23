<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectActivityAndCommentResource;
use App\Models\ProjectActivityAndComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\PerformaSheet;
use App\Services\ActivityService;


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
            // 'user_id' => 'required|integer',
            'task_id' => 'nullable|string',
            'type' => 'required|string',
            'description' => 'nullable|string',
            'attachments' => 'nullable',
        ]);
        $user = auth()->user();
        $user_id = $user->id;
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 200);
        }

        if (!$request->project_id || !$user_id) {
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
                'user_id' => $user_id,
                'task_id' => $request->task_id,
                'type' => $request->type,
                'description' => $request->description,
                'attachments' => $attachmentValue,
            ]);
            if (!$request->type == 'activity') {
                ActivityService::log([
                    'project_id' => $request->project_id,
                    'type' => 'activity',
                    'description' => $request->type . ' added by ' . auth()->user()->name,
                ]);
            }
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
        )->where('type', $request->type)->latest()->get();

        if ($activities->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No data found',
                'data' => [],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'data' => ProjectActivityAndCommentResource::collection($activities),
        ], 200);
    }

    // public function update(Request $request, $id = null)
    // {
    //     if (!$id) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'ID is required',
    //         ], 404);
    //     }

    //     $activity = ProjectActivityAndComment::find($id);

    //     if (!$activity) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Record not found',
    //         ], 404);
    //     }

    //     $validator = Validator::make($request->all(), [
    //         'project_id' => 'required|integer',
    //         // 'user_id' => 'required|integer',
    //         'task_id' => 'nullable|integer',
    //         'type' => 'required|string',
    //         'description' => 'nullable|string',
    //         'attachments' => 'nullable',
    //     ]);
    //     $user = auth()->user();
    //     $user_id = $user->id;
    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation failed',
    //             'errors' => $validator->errors(),
    //         ], 404);
    //     }

    //     try {

    //         if ($request->hasFile('attachments')) {

    //             $file = $request->file('attachments');
    //             $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];

    //             if (!in_array($file->getClientOriginalExtension(), $allowedExtensions)) {
    //                 return response()->json([
    //                     'success' => false,
    //                     'message' => 'Invalid attachment type',
    //                 ], 404);
    //             }

    //             if ($activity->attachments && !filter_var($activity->attachments, FILTER_VALIDATE_URL)) {
    //                 if (Storage::disk('public')->exists($activity->attachments)) {
    //                     Storage::disk('public')->delete($activity->attachments);
    //                 }
    //             }

    //             if (!Storage::disk('public')->exists('project_attachments')) {
    //                 Storage::disk('public')->makeDirectory('project_attachments');
    //             }

    //             $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
    //             $file->storeAs('project_attachments', $filename, 'public');

    //             $activity->attachments = 'project_attachments/' . $filename;
    //         } elseif ($request->attachments && filter_var($request->attachments, FILTER_VALIDATE_URL)) {

    //             if ($activity->attachments && !filter_var($activity->attachments, FILTER_VALIDATE_URL)) {
    //                 if (Storage::disk('public')->exists($activity->attachments)) {
    //                     Storage::disk('public')->delete($activity->attachments);
    //                 }
    //             }

    //             $activity->attachments = $request->attachments;
    //         }

    //         $activity->project_id = $request->project_id ?? $activity->project_id;
    //         $activity->user_id = $user_id;
    //         $activity->task_id = $request->task_id ?? $activity->task_id;
    //         $activity->type = $request->type ?? $activity->type;
    //         $activity->description = $request->description ?? $activity->description;

    //         $activity->save();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Activity updated successfully',
    //             'data' => new ProjectActivityAndCommentResource($activity),
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Something went wrong',
    //             'error' => $e->getMessage(),
    //         ], 404);
    //     }
    // }



    public function update(Request $request, $id)
    {
        $activity = ProjectActivityAndComment::find($id);

        if (!$activity) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found',
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'project_id' => 'required|integer',
            'task_id' => 'nullable|string',
            'type' => 'required|string',

            'description' => 'sometimes|nullable|string',
            'attachments' => 'sometimes|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 200);
        }

        try {


            $activity->project_id = $request->project_id;
            $activity->task_id = $request->task_id;
            $activity->type = $request->type;

            $activity->user_id = auth()->id();

            if ($request->has('description')) {
                $activity->description = $request->description;
            }

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

                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('project_attachments', $filename, 'public');

                $activity->attachments = 'project_attachments/' . $filename;

            } elseif ($request->has('attachments') && filter_var($request->attachments, FILTER_VALIDATE_URL)) {

                if ($activity->attachments && !filter_var($activity->attachments, FILTER_VALIDATE_URL)) {
                    if (Storage::disk('public')->exists($activity->attachments)) {
                        Storage::disk('public')->delete($activity->attachments);
                    }
                }

                $activity->attachments = $request->attachments;
            }

            $activity->save();

            ActivityService::log([
                'project_id' => $request->project_id,
                'type' => 'activity',
                'description' => $request->type . ' updated by ' . auth()->user()->name,
            ]);

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

    public function GetAllComments(Request $request)
    {
        $taskId = $request->task_id;
        if (!$taskId) {
            return response()->json([
                'success' => false,
                'message' => 'Task id is required',
            ], 404);
        }

        $timeline = collect();

        $performaSheets = PerformaSheet::whereIn('status', ['approved', 'pending'])->get();

        $narrations = $performaSheets->map(function ($sheet) use ($taskId) {

            $data = json_decode($sheet->data, true);

            if (!$data || !isset($data['task_id']) || $data['task_id'] != $taskId) {
                return null;
            }

            return [
                'message' => $data['narration'] ?? null,
                'created_at' => $sheet->created_at
            ];
        })->filter();

        $comments = ProjectActivityAndComment::where('task_id', $taskId)
            ->latest()->where('task_id', $taskId)->where('type', 'comment')
            ->get()
            ->map(function ($comment) {
                return [
                    'description' => $comment->description ?? null,
                    'created_at' => $comment->created_at,
                ];
            });

        $timeline = $timeline
            ->merge($comments)
            ->merge($narrations)
            ->sortByDesc(function ($item) {
                return \Carbon\Carbon::parse($item['created_at'])->timestamp;
            })
            ->values();

        if ($timeline->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No comments or narrations found',
                'data' => [],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Timeline fetched successfully',
            'data' => $timeline
        ], 200);
    }

}
