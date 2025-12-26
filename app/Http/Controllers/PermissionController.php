<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\Request;
use App\Models\User;

class PermissionController extends Controller
{
    public function getPermissions()
    {
        try {
            $authUser = auth()->user();
            $authUserPermission = Permission::where('user_id', $authUser->id)->first();

            if (!$authUserPermission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permissions not found',
                ], 404);
            }
            $permissionsAssoc = collect($authUserPermission->toArray())
                ->except(['id', 'user_id', 'created_at', 'updated_at'])
                ->all();

            return response()->json([
                "success" => true,
                "message" => "Fetched all permissions",
                "id" => $authUserPermission->id,
                "user_id" => $authUserPermission->user_id,
                "permissions" => [$permissionsAssoc],
                "created_at" => $authUserPermission->created_at,
                "updated_at" => $authUserPermission->updated_at
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error('Internal Server Error!', [
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getPermissionsAllUsers()
    {
        try {
            $authUser = auth()->user();
            if ($authUser->role_id == 1) {

                $predefined_permission = [
                    "dashboard" => "0",
                    "permission" => "0",
                    "employee_management" => "0",
                    "roles" => "0",
                    "department" => "0",
                    "team" => "0",
                    "clients" => "0",
                    "projects" => "0",
                    "assigned_projects_inside_projects_assigned" => "0",
                    "unassigned_projects_inside_projects_assigned" => "0",
                    "performance_sheets" => "0",
                    "pending_sheets_inside_performance_sheets" => "0",
                    "manage_sheets_inside_performance_sheets" => "0",
                    "unfilled_sheets_inside_performance_sheets" => "0",
                    "manage_leaves" => "0",
                    "activity_tags" => "0",
                    "leaves" => "0",
                    "teams" => "0",
                    "leave_management" => "0",
                    "project_management" => "0",
                    "assigned_projects_inside_project_management" => "0",
                    "unassigned_projects_inside_project_management" => "0",
                    "performance_sheet" => "0",
                    "performance_history" => "0",
                    "projects_assigned" => "0",
                    "project_master" => "0",
                    "client_master" => "0",
                    "project_source" => "0",
                    "communication_type" => "0",
                    "account_master" => "0",
                    "notes_management" => "0",
                ];

                $users = User::with('permission')
                    ->where('id', '!=', 1)
                    ->get();

                $final_data = $users->map(function ($user) use ($predefined_permission) {

                    $permissions = $user->permission
                        ? collect($user->permission->toArray())->except([
                            'id',
                            'user_id',
                        ])->toArray()
                        : $predefined_permission;

                    return [
                        "user_id" => $user->id,
                        "user_name" => $user->name,
                        "user_email" => $user->email,
                        "user_employee_id" => $user->employee_id,
                        "permissions" => $permissions
                    ];
                });

                return response()->json([
                    "success" => true,
                    "message" => "Fetched all users permissions",
                    "permissions_of_users" => $final_data,
                ]);
            }
            return response()->json([
                "success" => true,
                "message" => "You do not have permissions to access",
                "permissions_of_users" => []
            ]);
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
