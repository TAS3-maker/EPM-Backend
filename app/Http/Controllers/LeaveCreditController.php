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
                $query->where('status', 'Approved')->where('employment_period', 'appointed')
                    ->whereMonth('start_date', $month)
                    ->whereYear('start_date', $year);
            }
        ])->latest()->get();
        // Append calculated fields
        $leaveCredits->transform(function ($credit) use ($month, $year) {

            // Count approved leave days for that month
            $approvedLeaveHours = (float) $credit->user->leaves->sum('total_hours');
            $approvedLeaveDays = (float) $credit->user->leaves->sum('deducted_days');
            /**working hours */
            $monthlyLimitHours = ($credit->paid_leaves ?? 0) * 8.5;
            $credit->leave_taken_hours = $approvedLeaveHours;
            $credit->remaining_paid_leave_hours = max(0, $monthlyLimitHours - $approvedLeaveHours);
            //Leave Taken (remaining paid leave for month)
            $credit->deducted_days = $approvedLeaveDays;
            $expectedWorkingDays = LeaveCreditService::getWorkingDaysInMonth(Carbon::today());
            /**working hours */
            $expectedWorkingHours = $expectedWorkingDays * 8.5;
            $credit->expected_working_hours = $expectedWorkingHours;
            $credit->worked_hours = max(0, $expectedWorkingHours - $approvedLeaveHours);
            $credit->expected_working_days = $expectedWorkingDays;

            if ($approvedLeaveHours <= $monthlyLimitHours) {
                $credit->paid_hours = $approvedLeaveHours;
                $credit->unpaid_hours = 0;
            } else {
                $unpaidCalculation = max(0, $approvedLeaveHours - $monthlyLimitHours - $credit->carry_forward_balance);
                $credit->paid_hours = $approvedLeaveHours - $unpaidCalculation;
                $credit->unpaid_hours = $unpaidCalculation;
            }

            if ($credit->employment_status === 'notice' && $credit->notice_start_date) {
                $startDate = Carbon::parse($credit->notice_start_date);

                $basePeriodDays = (int) ($credit->notice_period_days ?? 0);

                // Initial notice end date (without extension)
                $initialEndDate = $startDate->copy()->addDays($basePeriodDays);

                // Get approved leaves during notice period
                $noticeLeaves = $credit->user->leaves()
                    ->where('status', 'Approved')
                    ->whereBetween('start_date', [
                        $startDate->toDateString(),
                        $initialEndDate->toDateString()
                    ])
                    ->get();

                // Total deducted days during notice
                $totalDeductedDays = (float) $noticeLeaves->sum('deducted_days');

                // Convert to whole number (HR rule)
                $extensionDays = (int) floor($totalDeductedDays);

                // Final recalculated notice period
                $finalNoticePeriod = $basePeriodDays + $extensionDays;

                $credit->notice_end_date = $startDate
                    ->copy()
                    ->addDays($finalNoticePeriod)
                    ->toDateString();
            } else {
                $credit->notice_end_date = null;
            }
            $credit->provisional_end_date = $credit->joining_date
                ? Carbon::parse($credit->joining_date)
                ->addDays($credit->provisional_days ?? 0)
                ->addDays(30 * ($credit->provisional_extended_months ?? 0))
                ->toDateString()
                : null;

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
                        'provisional_days' => 90,
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
                'month' => $currentMonth,
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

            $monthlyPaid = (float) ($credit->paid_leaves ?? 0);
            $previousCarry = (float) ($credit->carry_forward_balance ?? 0);
            $bunchLimit = (float) ($credit->bunch_time ?? 12);

            // Total approved leave taken in that month
            $taken = (float) LeavePolicy::where('user_id', $credit->user_id)
                ->where('status', 'Approved')
                ->whereMonth('start_date', $month)
                ->whereYear('start_date', $year)
                ->sum('deducted_days');

            // Total available leave for month
            $totalAvailable = $monthlyPaid + $previousCarry;

            // Remaining after deduction
            $remaining = max(0, $totalAvailable - $taken);

            // If carry hits bunch limit → move to bunch balance
            if ($remaining >= $bunchLimit) {

                $credit->bunch_payble_balance =
                    ($credit->bunch_payble_balance ?? 0) + $bunchLimit;

                $credit->carry_forward_balance = $remaining - $bunchLimit;
            } else {

                $credit->carry_forward_balance = $remaining;
            }

            $credit->total_used = $taken;

            $credit->month = $month;
            $credit->year  = $year;

            $credit->save();
        }

        return response()->json([
            'success' => true,
            'processed_month' => $month,
            'processed_year'  => $year
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
