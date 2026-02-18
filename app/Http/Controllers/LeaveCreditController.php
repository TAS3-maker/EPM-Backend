<?php

namespace App\Http\Controllers;

use App\Models\LeaveCredit;
use Illuminate\Http\Request;

class LeaveCreditController extends Controller
{
    public function index()
    {
        return response()->json(
            LeaveCredit::with('user')->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'paid_leaves' => 'required|integer|min:0',
            'bunch_time' => 'required|integer|min:1',
            'provisional_days' => 'required|integer|min:0',
            'joining_date' => 'required|date',
        ]);

        $leave = LeaveCredit::create($data);

        return response()->json([
            'message' => 'Leave credit created',
            'data' => $leave
        ], 201);
    }

    public function show($id)
    {
        $leaveCredit = LeaveCredit::with('user')->findOrFail($id);

        return response()->json($leaveCredit);
    }

    public function update(Request $request, $id)
    {
        try {
            $input = $request->json()->all();

            if (empty($input)) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            if (empty($input)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data received',
                    'debug' => $input
                ], 422);
            }

            $leaveCredit = LeaveCredit::findOrFail($id);

            // Validate manually so we can inspect result
            $validator = validator($input, [
                'user_id' => 'sometimes|exists:users,id',
                'paid_leaves' => 'sometimes|integer|min:0',
                'bunch_time' => 'sometimes|integer|min:1',
                'provisional_days' => 'sometimes|integer|min:0',
                'joining_date' => 'sometimes|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'debug_input' => $input
                ], 422);
            }

            $data = $validator->validated();

            if (empty($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid fields provided',
                    'debug_input' => $input
                ], 422);
            }

            // Update
            $leaveCredit->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Leave credit updated successfully',
                'data' => $leaveCredit->fresh()
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function destroy($id)
    {
        $leaveCredit = LeaveCredit::findOrFail($id);
        $leaveCredit->delete();

        return response()->json([
            'message' => 'Leave credit deleted'
        ]);
    }
}
