<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    // Get permissions by user
    public function getPermissions()
    {
        try{
            $user = auth()->user();
            $user_id = $user->id;
            $permission = array();
            $permission = Permission::where('user_id', $user_id)->first();
            return response()->json([
                'success' => true,
                'message' => 'Fetched all permissions',
                'data' => $permission,
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error('Internal Server Error!', ['error' => $e->getMessage()], 500);
        }
    }
    public function store(Request $request)
    {
        $data = $request->all();

        $permission = Permission::updateOrCreate(
            ['user_id' => $data['user_id']],
            $data
        );

        return ApiResponse::success('User created successfully', new PermissionResource($permission), 201);
    }
    public function destroy($id)
    {
        $permission = Permission::where('user_id', $id)->first();
        if (!$permission) {
            return response()->json(['message' => 'Permissions not found'], 404);
        }

        $permission->delete();
        return response()->json([
            'success' => true,
            'message' => 'Permissions deleted successfully'
        ]);
    }
}
