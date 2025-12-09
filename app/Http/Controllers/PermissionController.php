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
        try {
            $user = auth()->user();
            $user_id = $user->id;
            $user_role_id = $user->role_id;

            $permission = Permission::where('user_id', $user_id)->first();

            if (!$permission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permissions not found',
                ], 404);
            }

            $permissionArray = $permission->toArray();

            $permissionsAssoc = collect($permissionArray)
                ->except(['id', 'user_id', 'created_at', 'updated_at'])
                ->all();
             if ($user_role_id === 1) {

                $permission_of_all_users = Permission::where('user_id', '!=', 1)->get();
                return response()->json([
                    "success" => true,
                    "message" => "Fetched all permissions",
                    "id" => $permission->id,
                    "user_id" => $permission->user_id,
                    "permissions" => [
                        $permissionsAssoc
                    ],
                    "permissions_of_all_users" => $permission_of_all_users,
                    "created_at" => $permission->created_at,
                    "updated_at" => $permission->updated_at
                ]);
                } else {
                return response()->json([
                "success" => true,
                "message" => "Fetched all permissions",
                "id" => $permission->id,
                "user_id" => $permission->user_id,
                "permissions" => [
                    $permissionsAssoc
                ],
                "created_at" => $permission->created_at,
                "updated_at" => $permission->updated_at
                ]);
         }

        } catch (\Exception $e) {
            return ApiResponse::error('Internal Server Error!', [
                'error' => $e->getMessage()
            ], 500);
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
