<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectActivityAndCommentResource;
use App\Models\ProjectActivityAndComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\PerformaSheet;
use App\Models\User;
use App\Services\ActivityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

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

    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'project_id' => 'required|integer',
    //         // 'user_id' => 'required|integer',
    //         'task_id' => 'nullable|string',
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
    //         ], 200);
    //     }

    //     if (!$request->project_id || !$user_id) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Project ID or User ID is missing',
    //         ], 200);
    //     }

    //     try {
    //         $attachmentValue = null;

    //         if ($request->hasFile('attachments')) {

    //             $file = $request->file('attachments');

    //             $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];

    //             if (!in_array($file->getClientOriginalExtension(), $allowedExtensions)) {
    //                 return response()->json([
    //                     'success' => false,
    //                     'message' => 'Invalid attachment type',
    //                 ], 200);
    //             }

    //             if (!Storage::disk('public')->exists('project_attachments')) {
    //                 Storage::disk('public')->makeDirectory('project_attachments');
    //             }

    //             $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
    //             $file->storeAs('project_attachments', $filename, 'public');

    //             $attachmentValue = 'project_attachments/' . $filename;
    //         } elseif ($request->attachments && filter_var($request->attachments, FILTER_VALIDATE_URL)) {
    //             $attachmentValue = $request->attachments;
    //         }


    //         $activity = ProjectActivityAndComment::create([
    //             'project_id' => $request->project_id,
    //             'user_id' => $user_id,
    //             'task_id' => $request->task_id,
    //             'type' => $request->type,
    //             'description' => $request->description,
    //             'attachments' => $attachmentValue,
    //         ]);
    //         if ($request->type != 'activity') {
    //             ActivityService::log([
    //                 'project_id' => $request->project_id,
    //                 'type' => 'activity',
    //                 'description' => $request->type . ' added by ' . auth()->user()->name,
    //             ]);
    //         }

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Activity created successfully',
    //             'data' => new ProjectActivityAndCommentResource($activity),
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Something went wrong',
    //             'error' => $e->getMessage(),
    //         ], 200);
    //     }
    // }



    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|integer',
            // 'user_id' => 'required|integer',
            'task_id' => 'nullable|string',
            'type' => 'required|string',
            'description' => 'nullable|string',
            'attachments' => 'nullable'
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

                if (!$file->isValid()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'File upload failed',
                    ], 200);
                }

                $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];

                if (!in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid attachment type',
                    ], 200);
                }

                if (!file_exists(public_path('storage'))) {
                    Artisan::call('storage:link');
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

            if ($request->type != 'activity') {
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


    // public function index(Request $request)
    // {
    //     if (!$request->project_id) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Project ID is required',
    //         ], 200);
    //     }

    //     $activities = ProjectActivityAndComment::with('user')
    //         ->where('project_id', $request->project_id)
    //         ->when($request->type, function ($query) use ($request) {
    //             $query->where('type', $request->type);
    //         })
    //         ->get();

    //     if ($activities->isEmpty()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'No data found',
    //             'data' => [],
    //         ], 200);
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'data' => ProjectActivityAndCommentResource::collection($activities),
    //     ], 200);
    // }


    public function index(Request $request)
    {
        if (!$request->project_id) {
            return response()->json([
                'success' => false,
                'message' => 'Project ID is required',
            ], 200);
        }

        $perPage = $request->per_page ?? 20;
        $activitiesQuery = ProjectActivityAndComment::with('user')
            ->where('project_id', $request->project_id)
            ->when($request->type, function ($query) use ($request) {
                $query->where('type', $request->type);
            })
            ->orderBy('id', 'DESC');
        $activities = $activitiesQuery->paginate($perPage);

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
            'pagination' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
            ]
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

    // public function GetAllComments(Request $request)
    // {
    //     $taskId = $request->task_id;

    //     if (!$taskId) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Task id is required',
    //         ], 404);
    //     }

    //     $timeline = collect();

    //     $performaSheets = PerformaSheet::whereIn('status', ['approved', 'pending', 'backdated'])->get();

    //     $narrations = $performaSheets->map(function ($sheet) use ($taskId) {

    //         $data = json_decode($sheet->data, true);

    //         if (!$data || ($data['task_id'] ?? null) != $taskId) {
    //             return null;
    //         }

    //         $user = User::where('id', $sheet->user_id)
    //             ->where('is_active', 1)
    //             ->first();


    //         return [
    //             'message' => $data['narration'] ?? null,
    //             'time' => $data['time'] ?? null,
    //             'user' => $user?->name,
    //             'created_at' => Carbon::parse($sheet->created_at)->format('d-m-Y H:i:s'),
    //         ];
    //     })->filter();

    //     $comments = ProjectActivityAndComment::with('user')
    //         ->where('task_id', $taskId)
    //         ->where('type', 'comment')
    //         ->latest()
    //         ->get()
    //         ->map(function ($comment) {
    //             return [
    //                 'message' => $comment->description ?? null,
    //                 'user' => $comment->user?->name,
    //                 'created_at' => $comment->created_at,
    //             ];
    //         });

    //     $timeline = $timeline
    //         ->merge($comments)
    //         ->merge($narrations)
    //         ->sortByDesc(fn($item) => \Carbon\Carbon::parse($item['created_at'])->timestamp)
    //         ->values();

    //     if ($timeline->isEmpty()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'No comments or narrations found',
    //             'data' => [],
    //         ], 200);
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Timeline fetched successfully',
    //         'data' => $timeline
    //     ], 200);
    // }



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

        $perPage = $request->per_page ?? 10;

        $comments = DB::table('project_activity_and_comments as pac')
            ->join('users as u', 'u.id', '=', 'pac.user_id')
            ->select(
                'pac.description as message',
                DB::raw('NULL as time'),
                'u.name as user',
                DB::raw("DATE(pac.created_at) as created_at"),
                DB::raw("'comment' as source_type")
            )
            ->where('pac.task_id', $taskId)
            ->where('pac.type', 'comment');

        $narrations = DB::table('performa_sheets as ps')
            ->join('users as u', function ($join) {
                $join->on('u.id', '=', 'ps.user_id')
                    ->where('u.is_active', 1);
            })
            ->select(
                DB::raw("
            JSON_UNQUOTE(
                JSON_EXTRACT(
                    JSON_UNQUOTE(ps.data),
                    '$.narration'
                )
            ) as message
        "),
                DB::raw("
            JSON_UNQUOTE(
                JSON_EXTRACT(
                    JSON_UNQUOTE(ps.data),
                    '$.time'
                )
            ) as time
        "),
                'u.name as user',
                DB::raw("DATE(ps.created_at) as created_at"),
                DB::raw("'narration' as source_type")
            )
            ->whereIn('ps.status', ['approved', 'pending', 'backdated'])
            ->whereRaw("
        CAST(
            JSON_UNQUOTE(
                JSON_EXTRACT(
                    JSON_UNQUOTE(ps.data),
                    '$.task_id'
                )
            ) AS UNSIGNED
        ) = ?
    ", [$taskId]);

        $timeline = $comments->unionAll($narrations);

        $results = DB::query()
            ->fromSub($timeline, 'timeline')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        if ($results->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No comments or narrations found',
                'data' => [],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Timeline fetched successfully',
            'data' => $results->items(),
            'pagination' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
            ]
        ], 200);
    }
}
