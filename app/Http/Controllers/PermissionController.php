<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
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
}
