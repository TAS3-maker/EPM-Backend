<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TagsActivity;
use Illuminate\Support\Facades\DB;
use App\Http\Helpers\ApiResponse; 
use Illuminate\Validation\ValidationException; 

class TagActivityController extends Controller
{
    /**
     * Store a new activity tag.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function AddActivityTag(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|unique:tagsactivity,name',
            ]);

            $tag = TagsActivity::create(['name' => $validatedData['name']]);

            return ApiResponse::success('Tag added successfully', $tag, 201);
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation failed', $e->errors(), 422);
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to add tag: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Get all activity tags.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function GetActivityTag()
    {
        try {
            $tags = DB::table('tagsactivity')->orderBy('id', 'DESC')->get();
            return ApiResponse::success('Activity tags retrieved successfully', $tags, 200);
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to retrieve tags: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Update an existing activity tag.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateActivityTag(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:tagsactivity,name,' . $id,
            ]);

            $tag = DB::table('tagsactivity')->where('id', $id)->first();

            if (!$tag) {
                return ApiResponse::error('Tag not found', [], 404);
            }

            DB::table('tagsactivity')->where('id', $id)->update([
                'name' => $validatedData['name'],
                'updated_at' => now()
            ]);

            $updatedTag = DB::table('tagsactivity')->where('id', $id)->first();

            return ApiResponse::success('Tag updated successfully', $updatedTag, 200);
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation failed', $e->errors(), 422);
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to update tag: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Remove an activity tag.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $tag = TagsActivity::find($id); 

            if (!$tag) {
                return ApiResponse::error('Tag not found', [], 404);
            }

            $tag->delete();
            return ApiResponse::success('Tag deleted successfully', [], 200); 
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to delete tag: ' . $e->getMessage(), [], 500);
        }
    }
}
