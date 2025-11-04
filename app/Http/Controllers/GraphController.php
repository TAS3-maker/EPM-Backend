<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\User;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\ProjectResource;
use App\Models\PerformaSheet;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;  
use Illuminate\Support\Facades\Auth;


class GraphController extends Controller
{
  
public function GraphTotalWorkingHour(Request $request)
{
    $startDate = $request->input('start_date');
    $endDate = $request->input('end_date');

    $dataQuery = DB::table('performa_sheets')->select('id', 'data', 'status')->where('status', 'approved'); 
    $data = $dataQuery->get();

    $times = [];
    $totalBillableMinutes = 0;
    $totalNonBillableMinutes = 0;
    $totalInhouseMinutes = 0;

    foreach ($data as $row) {
        $decodedString = json_decode($row->data, true);
        if ($decodedString === null) {
            Log::warning("Invalid JSON format in data field for ID: {$row->id}");
            continue;
        }

        if (is_string($decodedString)) {
            $decodedString = json_decode($decodedString, true);
        }

        if (!isset($decodedString['date']) || !isset($decodedString['time'])) {
            Log::warning("Missing date or time in data field for ID: {$row->id}");
            continue;
        }

        $recordDate = $decodedString['date'];

        if (($startDate && strtotime($recordDate) < strtotime($startDate)) || ($endDate && strtotime($recordDate) > strtotime($endDate))) {
            continue;
        }

        $times[] = [
            'id' => $row->id,
            'time' => $decodedString['time'],
            'activity_type' => $decodedString['activity_type'] ?? 'Unknown',
            'date' => $decodedString['date'],
			'status' => $row->status,
        ];

        $timeParts = explode(':', $decodedString['time']);
        if (count($timeParts) !== 2) {
            Log::warning("Invalid time format for ID: {$row->id}, Time: {$decodedString['time']}");
            continue;
        }

        $hours = intval($timeParts[0]);
        $minutes = intval($timeParts[1]);
        $totalMinutes = ($hours * 60) + $minutes;

        $activityType = $decodedString['activity_type'] ?? 'Unknown';
		$statusType = $row->status;
		//$statusType = $decodedString['status'] ?? 'Unknown';
        if ($activityType === 'Billable') {
            $totalBillableMinutes += $totalMinutes;
        } elseif ($activityType === 'Non Billable') {
            $totalNonBillableMinutes += $totalMinutes;
        } else {
            $totalInhouseMinutes += $totalMinutes;
        }
    }

    // Convert total minutes back to HH:MM format
    $formattedBillableTime = sprintf('%02d:%02d', floor($totalBillableMinutes / 60), $totalBillableMinutes % 60);
    $formattedNonBillableTime = sprintf('%02d:%02d', floor($totalNonBillableMinutes / 60), $totalNonBillableMinutes % 60);
    $formattedInhouseTime = sprintf('%02d:%02d', floor($totalInhouseMinutes / 60), $totalInhouseMinutes % 60);

    return response()->json([
       'times' => $times,
        'total_billable_hours' => $formattedBillableTime,
        'total_nonbillable_hours' => $formattedNonBillableTime,
        'total_inhouse_hours' => $formattedInhouseTime
    ]);
}

public function GetWorkingHourByProject(Request $request)
{
    $projectId = $request->input('project_id');

    if (!$projectId) {
        return response()->json(['error' => 'Project ID is required'], 400);
    }

    $project = DB::table('projects')->where('id', $projectId)->first();

    if (!$project) {
        return response()->json(['error' => 'Project not found'], 404);
    }

    $dataQuery = DB::table('performa_sheets')
        ->select('id', 'data', 'status')
        ->where('status', 'approved')
        ->get();

    $totalBillableMinutes = 0;
    $totalNonBillableMinutes = 0;
    $totalInhouseMinutes = 0;

    foreach ($dataQuery as $row) {
        $decodedString = json_decode($row->data, true);
        if ($decodedString === null) {
            Log::warning("Invalid JSON format in data field for ID: {$row->id}");
            continue;
        }

        if (is_string($decodedString)) {
            $decodedString = json_decode($decodedString, true);
        }

        if (!isset($decodedString['time']) || !isset($decodedString['activity_type']) || !isset($decodedString['project_id'])) {
            Log::warning("Missing required fields in data field for ID: {$row->id}");
            continue;
        }

        // Check if project_id matches the given one
        if ($decodedString['project_id'] != $projectId) {
            continue;
        }

        $timeParts = explode(':', $decodedString['time']);
        if (count($timeParts) !== 2) {
            Log::warning("Invalid time format for ID: {$row->id}, Time: {$decodedString['time']}");
            continue;
        }

        $hours = intval($timeParts[0]);
        $minutes = intval($timeParts[1]);
        $totalMinutes = ($hours * 60) + $minutes;

        $activityType = strtolower($decodedString['activity_type']);
        if ($activityType === 'billable') {
            $totalBillableMinutes += $totalMinutes;
        } elseif ($activityType === 'non billable') {
            $totalNonBillableMinutes += $totalMinutes;
        } else {
            $totalInhouseMinutes += $totalMinutes;
        }
    }

    $formattedBillableTime = sprintf('%02d:%02d', floor($totalBillableMinutes / 60), $totalBillableMinutes % 60);
    $formattedNonBillableTime = sprintf('%02d:%02d', floor($totalNonBillableMinutes / 60), $totalNonBillableMinutes % 60);
    $formattedInhouseTime = sprintf('%02d:%02d', floor($totalInhouseMinutes / 60), $totalInhouseMinutes % 60);

    $totalWorkingMinutes = $totalBillableMinutes + $totalNonBillableMinutes + $totalInhouseMinutes;
    $formattedTotalWorkingTime = sprintf('%02d:%02d', floor($totalWorkingMinutes / 60), $totalWorkingMinutes % 60);

    return response()->json([
        'project_id' => $project->id,
        'project_name' => $project->project_name,
        'client_id' => $project->client_id,
        'sales_team_id' => $project->sales_team_id,
        'requirements' => $project->requirements,
        'deadline' => $project->deadline,
        'created_at' => $project->created_at,
        'updated_at' => $project->updated_at,
        'project_total_hours' => $project->total_hours,
        'total_working_hours' => $formattedTotalWorkingTime,
        'total_billable_hours' => $formattedBillableTime ?: '00:00',
        'total_nonbillable_hours' => $formattedNonBillableTime ?: '00:00',
        'total_inhouse_hours' => $formattedInhouseTime ?: '00:00',
    ]);
}

public function GetWeeklyWorkingHourByProject()
{
    $user = auth()->user(); 
    $startDate = date('Y-m-d', strtotime('-7 days'));
    $endDate = date('Y-m-d');

    $dataQuery = DB::table('performa_sheets')
        ->select('id', 'data', 'status', 'user_id')
        ->where('status', 'approved');

    if ($user->role_id == 7) {
        $dataQuery->where('user_id', $user->id);
    } elseif (!in_array($user->role_id, [1, 2, 3, 4])) {
        $dataQuery->where('user_id', $user->id);
    }
    $dataQuery = $dataQuery->get();
    $result = [];
    foreach ($dataQuery as $row) {
        $decodedData = json_decode($row->data, true);
        if ($decodedData === null) {
            Log::warning("Invalid JSON format in data field for ID: {$row->id}");
            continue;
        }
        if (is_string($decodedData)) {
            $decodedData = json_decode($decodedData, true);
        }
        if (!isset($decodedData['date'], $decodedData['time'], $decodedData['activity_type'])) {
            Log::warning("Missing required fields in data for ID: {$row->id}");
            continue;
        }
        $recordDate = $decodedData['date'];
        $activityType = $decodedData['activity_type'];
        $timeParts = explode(':', $decodedData['time']);
        if (count($timeParts) !== 2) {
            Log::warning("Invalid time format for ID: {$row->id}, Time: {$decodedData['time']}");
            continue;
        }
        $hours = intval($timeParts[0]);
        $minutes = intval($timeParts[1]);
        $totalMinutes = ($hours * 60) + $minutes;
        if (strtotime($recordDate) < strtotime($startDate) || strtotime($recordDate) > strtotime($endDate)) {
            continue;
        }
        if (!isset($result[$recordDate])) {
            $result[$recordDate] = [
                'date' => $recordDate,
                'total_hours' => 0,
                'total_billable' => 0,
                'total_non_billable' => 0,
                'total_inhouse' => 0,
            ];
        }
        $result[$recordDate]['total_hours'] += $totalMinutes;

        if ($activityType === 'Billable') {
            $result[$recordDate]['total_billable'] += $totalMinutes;
        } elseif ($activityType === 'Non Billable') {
            $result[$recordDate]['total_non_billable'] += $totalMinutes;
        } else {
            $result[$recordDate]['total_inhouse'] += $totalMinutes;
        }
    }
    foreach ($result as &$dayData) {
        $dayData['total_hours'] = sprintf('%02d:%02d', floor($dayData['total_hours'] / 60), $dayData['total_hours'] % 60);
        $dayData['total_billable'] = sprintf('%02d:%02d', floor($dayData['total_billable'] / 60), $dayData['total_billable'] % 60);
        $dayData['total_non_billable'] = sprintf('%02d:%02d', floor($dayData['total_non_billable'] / 60), $dayData['total_non_billable'] % 60);
        $dayData['total_inhouse'] = sprintf('%02d:%02d', floor($dayData['total_inhouse'] / 60), $dayData['total_inhouse'] % 60);
    }

    return response()->json(array_values($result));
}


public function GetTotalWorkingHourByEmploye()
{
    $userId = Auth::id(); 
    $dataQuery = DB::table('performa_sheets')
        ->select('id', 'data', 'status')
        ->where('status', 'approved')
        ->where('user_id', $userId) 
        ->get();

    $totalHours = 0;
    $totalBillable = 0;
    $totalNonBillable = 0;
    $totalInhouse = 0;

    foreach ($dataQuery as $row) {
        $decodedData = json_decode($row->data, true);
        if ($decodedData === null) {
            Log::warning("Invalid JSON format in data field for ID: {$row->id}");
            continue;
        }

        if (is_string($decodedData)) {
            $decodedData = json_decode($decodedData, true);
        }

        if (!isset($decodedData['time'], $decodedData['activity_type'])) {
            Log::warning("Missing required fields in data for ID: {$row->id}");
            continue;
        }

        $activityType = $decodedData['activity_type'];
        $timeParts = explode(':', $decodedData['time']);

        if (count($timeParts) !== 2) {
            Log::warning("Invalid time format for ID: {$row->id}, Time: {$decodedData['time']}");
            continue;
        }

        $hours = intval($timeParts[0]);
        $minutes = intval($timeParts[1]);
        $totalMinutes = ($hours * 60) + $minutes;

        $totalHours += $totalMinutes;

        if ($activityType === 'Billable') {
            $totalBillable += $totalMinutes;
        } elseif ($activityType === 'Non Billable') {
            $totalNonBillable += $totalMinutes;
        } else {
            $totalInhouse += $totalMinutes;
        }
    }

    return response()->json([
        'user_id' => $userId,
        'total_hours' => sprintf('%02d:%02d', floor($totalHours / 60), $totalHours % 60),
        'total_billable' => sprintf('%02d:%02d', floor($totalBillable / 60), $totalBillable % 60),
        'total_non_billable' => sprintf('%02d:%02d', floor($totalNonBillable / 60), $totalNonBillable % 60),
        'total_inhouse' => sprintf('%02d:%02d', floor($totalInhouse / 60), $totalInhouse % 60),
    ]);
}

public function GetTotalWeeklyWorkingHourByEmploye()
{
    $userId = Auth::id(); 

    $startDate = date('Y-m-d', strtotime('-6 days')); 
    $endDate = date('Y-m-d');

    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime("$startDate +$i days"));
        $dates[$date] = [
            'date' => $date,
            'total_hours' => '00:00',
            'total_billable' => '00:00',
            'total_non_billable' => '00:00',
            'total_inhouse' => '00:00',
        ];
    }

    $dataQuery = DB::table('performa_sheets')
        ->select('id', 'data', 'status')
        ->where('status', 'approved')
        ->where('user_id', $userId)
        ->get();

    foreach ($dataQuery as $row) {
        $decodedData = json_decode($row->data, true);
        if ($decodedData === null) {
            Log::warning("Invalid JSON format in data field for ID: {$row->id}");
            continue;
        }

        if (is_string($decodedData)) {
            $decodedData = json_decode($decodedData, true);
        }

        if (!isset($decodedData['date'], $decodedData['time'], $decodedData['activity_type'])) {
            Log::warning("Missing required fields in data for ID: {$row->id}");
            continue;
        }

        $recordDate = $decodedData['date'];
        $activityType = $decodedData['activity_type'];
        $timeParts = explode(':', $decodedData['time']);

        if (count($timeParts) !== 2) {
            Log::warning("Invalid time format for ID: {$row->id}, Time: {$decodedData['time']}");
            continue;
        }

        $hours = intval($timeParts[0]);
        $minutes = intval($timeParts[1]);
        $totalMinutes = ($hours * 60) + $minutes;

        if (!isset($dates[$recordDate])) {
            continue;
        }

        if ($dates[$recordDate]['total_hours'] === '00:00') {
            $dates[$recordDate]['total_hours'] = 0;
            $dates[$recordDate]['total_billable'] = 0;
            $dates[$recordDate]['total_non_billable'] = 0;
            $dates[$recordDate]['total_inhouse'] = 0;
        }

        $dates[$recordDate]['total_hours'] += $totalMinutes;
        if ($activityType === 'Billable') {
            $dates[$recordDate]['total_billable'] += $totalMinutes;
        } elseif ($activityType === 'Non Billable') {
            $dates[$recordDate]['total_non_billable'] += $totalMinutes;
        } else {
            $dates[$recordDate]['total_inhouse'] += $totalMinutes;
        }
    }

    foreach ($dates as &$dayData) {
        if (is_numeric($dayData['total_hours'])) {
            $dayData['total_hours'] = sprintf('%02d:%02d', floor($dayData['total_hours'] / 60), $dayData['total_hours'] % 60);
            $dayData['total_billable'] = sprintf('%02d:%02d', floor($dayData['total_billable'] / 60), $dayData['total_billable'] % 60);
            $dayData['total_non_billable'] = sprintf('%02d:%02d', floor($dayData['total_non_billable'] / 60), $dayData['total_non_billable'] % 60);
            $dayData['total_inhouse'] = sprintf('%02d:%02d', floor($dayData['total_inhouse'] / 60), $dayData['total_inhouse'] % 60);
        }
    }

    return response()->json(array_values($dates));
}

public function GetLastSixMonthsProjectCount()
{
    $today = now();
    $currentDay = $today->day; 

    $data = [];

    for ($i = 0; $i < 6; $i++) {
        $startDate = $today->copy()->subMonths($i + 1)->setDay($currentDay);
        $endDate = $today->copy()->subMonths($i)->setDay($currentDay);

        if ($startDate->day != $currentDay) {
            $startDate = $startDate->copy()->lastOfMonth();
        }
        if ($endDate->day != $currentDay) {
            $endDate = $endDate->copy()->lastOfMonth();
        }

        $totalProjects = DB::table('projects')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $data[] = [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'total_projects' => $totalProjects,
        ];
    }

    return response()->json($data);
}


}
