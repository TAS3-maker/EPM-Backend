<?php

namespace App\Http\Controllers;

use App\Http\Helpers\ApiResponse;
use App\Models\LeaveCredit;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use League\Config\Exception\ValidationException;

class LeaveCreditController extends Controller
{
    public function index(Request $request)
    {
        $month = $request->month ?? now()->month;
        $year  = $request->year  ?? now()->year;
        $leaveCredits = LeaveCredit::with([
            'user:id,name',
            'user.leaves' => function ($query) use ($month, $year) {
                $query->where('status', 'Approved')
                    ->whereMonth('start_date', $month)
                    ->whereYear('start_date', $year);
            }
        ])->latest()->get();
        // Append calculated fields
        $leaveCredits->transform(function ($credit) use ($month, $year) {
            $monthlyLimit = $credit->paid_leaves ?? 0;

            // Count approved leave days for that month
            $approvedLeaveHours = (float) $credit->user->leaves->sum('total_hours');
            $approvedLeaveDays = (float) $credit->user->leaves->sum('deducted_days');
            /**working hours */
            $monthlyLimitHours = ($credit->paid_leaves ?? 0) * 8.5;
            $credit->leave_taken_hours = $approvedLeaveHours;
            $credit->remaining_paid_leave_hours = max(0, $monthlyLimitHours - $approvedLeaveHours);
            //Leave Taken (remaining paid leave for month)
            $credit->leave_taken = $approvedLeaveDays;
            $expectedWorkingDays = LeaveCreditService::getWorkingDaysInMonth(Carbon::today());
            /**working hours */
            $expectedWorkingHours = $expectedWorkingDays * 8.5;
            $credit->expected_working_hours = $expectedWorkingHours;
            $credit->worked_hours = max(0, $expectedWorkingHours - $approvedLeaveHours);
            $credit->expected_working_days = $expectedWorkingDays;
            $credit->approved_leave_hours = $approvedLeaveHours;
            $credit->paid = 0;
            $credit->unpaid = 0;
            if ($credit->employment_status === 'notice' && $credit->notice_start_date) {
                $credit->notice_end_date = Carbon::parse($credit->notice_start_date)
                    ->addDays($credit->notice_period_days)
                    ->toDateString();
            }
            return $credit;
        });

        return response()->json([
            'success' => true,
            'month'   => $month,
            'year'    => $year,
            'data'    => $leaveCredits
        ]);
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
            $leaveCredit = LeaveCredit::findOrFail($id);
            try {
                // Validate manually so we can inspect result
                $validatedData = $request->validate([
                    'user_id' => 'sometimes|exists:users,id',
                    'employment_status' => 'sometimes|in:provisional,appointed,notice',
                    'cycle_start_date' => 'sometimes|date',
                    'cycle_end_date'   => 'sometimes|date|after_or_equal:cycle_start_date',
                    'carry_forward_balance'    => 'sometimes|numeric|min:0',
                    'total_used'               => 'sometimes|numeric|min:0',
                    'provisional_leave_limit'  => 'sometimes|integer|min:0',
                    'provisional_leave_taken'  => 'sometimes|numeric|min:0',
                    'provisional_extended_months' => 'sometimes|integer|min:0',
                    'notice_start_date' => 'sometimes|nullable|date',
                    'paid_leaves' => 'sometimes|integer|min:0',
                    'bunch_time'  => 'sometimes|integer|min:1',
                    'provisional_days' => 'sometimes|integer|min:0',
                    'joining_date' => 'sometimes|date|before_or_equal:today',
                ]);
                if (empty($validatedData)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No data provided for update.'
                    ], 422);
                }
            } catch (ValidationException $e) {
                return ApiResponse::error('Validation Error', $e->errors(), 422);
            }
            // Update
            $leaveCredit->update($validatedData);

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
    public function generateLeaveCredits()
    {
        return DB::transaction(function () {
            $currentYear = now()->year;
            $currentMonth = now()->month;

            $cycleStart = Carbon::create($currentYear, 1, 1)->startOfDay();
            $cycleEnd   = Carbon::create($currentYear, 12, 31)->endOfDay();
            $activeUsers = User::where('is_active', 1)->get();

            $created = 0;
            $skipped = 0;

            foreach ($activeUsers as $user) {

                $credit = LeaveCredit::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'year' => $currentYear,
                        'month' => $currentMonth,
                        'employment_status' => 'appointed',
                        'cycle_start_date' => $cycleStart,
                        'cycle_end_date' => $cycleEnd,
                        'carry_forward_balance' => 0,
                        'total_used' => 0,
                        'provisional_extended_months' => 0,
                        'notice_start_date' => null,
                        'paid_leaves' => 1,
                        'bunch_time' => 3,
                        'bunch_payble_balance' => 0.0,
                        'provisional_days' => 0,
                        'joining_date' => $user->created_at,
                    ]
                );

                if ($credit->wasRecentlyCreated) {
                    $created++;
                }
            }
            return response()->json([
                'success' => true,
                'message' => 'Leave credits generated successfully',
                'year' => $currentYear,
                'created_count' => $created
            ]);
        });
    }
    public function processMonthlyLeaveCycle(Request $request)
    {
        $month = $request->month ?? now()->subMonth()->month;
        $year  = $request->year ?? now()->subMonth()->year;

        $credits = LeaveCredit::where('employment_status', 'appointed')->get();

        foreach ($credits as $credit) {

            $paidPerMonth = $credit->paid_leaves ?? 1;

            // Get leave taken for given month
            $taken = LeavePolicy::where('user_id', $credit->user_id)
                ->where('status', 'Approved')
                ->whereMonth('start_date', $month)
                ->whereYear('start_date', $year)
                ->sum('deducted_days');

            $previousCarry = $credit->carry_forward_balance ?? 0;

            $totalAvailable = $paidPerMonth + $previousCarry;

            if ($taken > 0) {
                $newCarry = max(0, $totalAvailable - $taken);
            } else {
                $newCarry = $previousCarry + $paidPerMonth;
            }

            // If carry hits 4 → move to bunch
            if ($newCarry >= $credit->bunch_time) {
                $credit->bunch_paid_balance = 4;
                $credit->carry_forward_balance = 0;
            } else {
                $credit->carry_forward_balance = $newCarry;
            }

            $credit->save();
        }

        return response()->json([
            'success' => true,
            'data' => $credit,
            'processed_month' => $month,
            'processed_year' => $year
        ]);
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
