<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Role;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\RoleResource;

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
                'name' => 'required|string|max:255|unique:roles'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error('Validation Error', $e->errors(), 422);
        }

        $role = Role::create([
            'name' => $request->name,
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
                'name' => 'required|string|max:255|unique:roles,name,' . $id
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error('Validation Error', $e->errors(), 422);
        }

        $role->update([
            'name' => $request->name,
        ]);

        return ApiResponse::success('Role updated successfully', $role);
    }

    // public function destroy($id)
    // {
    //     $role = Role::find($id);

    //     if (!$role) {
    //         return ApiResponse::error('Role not found', [], 404);
    //     }

    //     $role->delete();
    //     return ApiResponse::success('Role deleted successfully');
    // }


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
