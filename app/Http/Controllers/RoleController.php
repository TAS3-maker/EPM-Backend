<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Role;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::all();
        return ApiResponse::success('Roles fetched successfully', RoleResource::collection($roles));
    }

    public function show($id)
    {
        $role = Role::find($id);

        if (!$role) {
            return ApiResponse::error('Role not found', [], 404);
        }

        return ApiResponse::success('Role details fetched successfully', new RoleResource($role));
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:roles',
                'role_label' => 'nullable|string|max:255',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error('Validation Error', $e->errors(), 422);
        }

        $roles_permissions = [
            "team" => "0",
            "roles" => "0",
            "teams" => "0",
            "leaves" => "0",
            "clients" => "0",
            "projects" => "0",
            "dashboard" => "0",
            "department" => "0",
            "permission" => "0",
            "activity_tags" => "0",
            "manage_leaves" => "0",
            "leave_management" => "0",
            "performance_sheet" => "0",
            "projects_assigned" => "0",
            "performance_sheets" => "0",
            "project_management" => "0",
            "employee_management" => "0",
            "performance_history" => "0",
            "manage_sheets_inside_performance_sheets" => "0",
            "pending_sheets_inside_performance_sheets" => "0",
            "unfilled_sheets_inside_performance_sheets" => "0",
            "assigned_projects_inside_projects_assigned" => "0",
            "assigned_projects_inside_project_management" => "0",
            "unassigned_projects_inside_projects_assigned" => "0",
            "unassigned_projects_inside_project_management" => "0",
            "project_master" => "0",
            "client_master" => "0",
            "project_source" => "0",
            "communication_type" => "0",
            "account_master" => "0",
            "notes_management" => "0",
            "team_reporting" => "0",
            "leave_reporting" => "0",
            "previous_sheets" => "0",
            "offline_hours" => "0",
            "standup_sheet" => "0",
            "sheet_reporting" => "0",
            "master_reporting" => "0",
        ];

        $role = Role::create([
            'name' => $request->name,
            'roles_permissions' => $roles_permissions,
            'role_label' => $request->role_label,
        ]);

        return ApiResponse::success('Role created successfully', $role, 201);
    }

    public function update(Request $request, $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return ApiResponse::error('Role not found', [], 404);
        }
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:roles,name,' . $id,
                'roles_permissions' => 'nullable',
                'role_label' => 'nullable|string|max:255',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error('Validation Error', $e->errors(), 422);
        }
        DB::beginTransaction();
        try {
            // $role->update([
            //     'name' => $request->name,
            //     'roles_permissions' => $request->roles_permissions,
            // ]);
            $role_data = [
                'name' => $validatedData['name'],
                'role_label' => $validatedData['role_label'],
            ];

            if (
                array_key_exists('roles_permissions', $validatedData)
                && $validatedData['roles_permissions'] !== null
            ) {
                $role_data['roles_permissions'] = $validatedData['roles_permissions'];
            }

            $role->update($role_data);

            if (!$request->roles_permissions) {
                DB::commit();
                return ApiResponse::success('Role updated successfully', $role);
            }
            $newPermissions = $request->roles_permissions;
            $users = User::whereJsonContains('role_id', $role->id)
                ->where('is_active', 1)
                ->get();

            $permissionColumns = [
                'dashboard',
                'permission',
                'permissions',
                'employee_management',
                'roles',
                'department',
                'team',
                'clients',
                'projects',
                'assigned_projects_inside_projects_assigned',
                'unassigned_projects_inside_projects_assigned',
                'performance_sheets',
                'pending_sheets_inside_performance_sheets',
                'manage_sheets_inside_performance_sheets',
                'unfilled_sheets_inside_performance_sheets',
                'manage_leaves',
                'activity_tags',
                'leaves',
                'teams',
                'leave_management',
                'project_management',
                'assigned_projects_inside_project_management',
                'unassigned_projects_inside_project_management',
                'performance_sheet',
                'performance_history',
                'projects_assigned',
                'project_master',
                'client_master',
                'project_source',
                'communication_type',
                'account_master',
                'notes_management',
                'team_reporting',
                'leave_reporting',
                'previous_sheets',
                'offline_hours',
                'standup_sheet',
                'sheet_reporting',
                'master_reporting',
            ];
            foreach ($users as $user) {
                $permission = Permission::where('user_id', $user->id)->first();
                if (!$permission) {
                    continue;
                }
                $updateData = [];
                foreach ($permissionColumns as $column) {
                    $existing = (int) $permission->$column;
                    $new = (int) ($newPermissions[$column] ?? 0);
                    if ($existing === 2) {
                        $final = 2;
                    } elseif ($existing === 1) {
                        $final = ($new === 0) ? 1 : $new;
                    } else {
                        $final = $new;
                    }
                    $updateData[$column] = (string) $final;
                }
                $permission->update($updateData);
            }
            DB::commit();
            return ApiResponse::success('Role updated successfully', $role);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Role update error: ' . $e->getMessage());
            return ApiResponse::error(
                'An unexpected error occurred.',
                ['general' => $e->getMessage()],
                500
            );
        }
    }

    public function destroy($id)
    {
        $role = Role::find($id);
        if (!$role) {
            return ApiResponse::error('Role not found', [], 404);
        }
        if ($role->users()->exists()) {
            return ApiResponse::error('Cannot delete: Role is assigned to users.', [], 400);
        }
        $role->delete();
        return ApiResponse::success('Role deleted successfully');
    }

}
