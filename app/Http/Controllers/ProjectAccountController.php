<?php

namespace App\Http\Controllers;

use App\Models\ProjectAccount;
use App\Http\Resources\ProjectAccountResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Numeric;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class ProjectAccountController extends Controller
{
    public function index(Request $request)
    {
        $sourceId = $request->source_id;

        $accounts = ProjectAccount::where('source_id', $sourceId)->get();

        if ($accounts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found',
                'data' => []
            ], 200); 
        }

        return response()->json([
            'success' => true,
            'message' => 'Account fetched successfully',
            'data' => $accounts
        ], 200);
    }


    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source_id' => 'required|integer|exists:project_source,id',
            'account_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 200);
        }

        $account = ProjectAccount::create([
            'account_name' => $request->account_name,
            'source_id' => $request->source_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Project account created successfully',
            'data' => new ProjectAccountResource($account)
        ], 200);
    }


    public function show($id)
    {
        $account = ProjectAccount::find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found',
                'data' => null
            ], 200); // keep 200 if frontend expects it
        }

        return response()->json([
            'success' => true,
            'message' => 'Account fetched successfully',
            'data' => new ProjectAccountResource($account)
        ], 200);
    }


    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'source_id' => 'required|integer|exists:project_source,id',
            'account_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 200);
        }

        $account = ProjectAccount::find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found',
                'data' => null
            ], 200);
        }

        $account->update([
            'source_id' => $request->source_id,
            'account_name' => $request->account_name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Account updated successfully',
            'data' => new ProjectAccountResource($account)
        ], 200);
    }


    public function destroy($id)
    {
        $account = ProjectAccount::find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found',
                'data' => null
            ], 200);
        }

        $account->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project account deleted successfully'
        ], 200);
    }

}
