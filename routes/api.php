<?php

use App\Http\Controllers\AccessoryAssignController;
use App\Http\Controllers\AccessoryCategoryController;
use App\Http\Controllers\AccessoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PerformaSheetController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\TagActivityController;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\CacheController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ClientMasterController;
use App\Http\Controllers\CommunicationTypeController;
use App\Http\Controllers\ProjectAccountController;
use App\Http\Controllers\ProjectMasterController;
use App\Http\Controllers\ProjectRelationController;
use App\Http\Controllers\ProjectSourceController;




Route::get('/storagelink', function() {
    Artisan::call('storage:link');
    return 'Storage link created!';
});

Route::post('/login', [AuthController::class, 'login']);
Route::get('/login', function () {
    return response()->json(['message' => 'Please log in to access this resource.'], 401);
})->name('login');
Route::post('/auth/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/auth/confirm-otp', [AuthController::class, 'confirmOtp']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
Route::get('/check-token', [UserController::class, 'checkToken']);
Route::middleware('jwt.auth')->post('/change-password', [AuthController::class, 'changePassword']);

Route::middleware('auth:api')->group(function () {
Route::get('/clearCache', [CacheController::class, 'clearAll']);
Route::get('/getalltl',[UserController::class,'get_all_tl']);
Route::delete('/deleteprofilepic',[UserController::class,'DeleteProfilepic']);
Route::get('/getmyprofile/{id}', [UserController::class, 'getMyProfile']);
Route::put('/users/{id}', [UserController::class, 'update']);
Route::post('/users', [UserController::class, 'store']);
Route::get('/users', [UserController::class, 'index']);
Route::get('/projectManager', [UserController::class, 'projectManger']);
Route::get('/users/{id}', [UserController::class, 'show']);
Route::get('/getfull_proileemployee/{id}', [UserController::class, 'GetFullProileEmployee']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);
Route::get('/getuser-Byteam', [UserController::class, 'getUserCountByTeam']);
Route::post('/import-users', [UserController::class, 'importUsers']);
Route::get('/get-team-members', [UserController::class, 'getTeamMembers']);
Route::apiResource('/teams', TeamController::class);
Route::apiResource('/roles', RoleController::class);
Route::apiResource('departments', DepartmentController::class);
Route::post('/logout', [AuthController::class, 'logout']);

// App => Http => Controllers => ClientController
Route::get('/clients', [ClientController::class, 'index']);
Route::post('/clients', [ClientController::class, 'store']);
Route::put('/clients/{id}', [ClientController::class, 'update']);
Route::delete('/clients/{id}', [ClientController::class, 'destroy']);
Route::post('/clients/import-csv', [ClientController::class, 'importCsv']);

// App => Http => Controllers => ProjectController
Route::get('/projects', [ProjectController::class, 'index']);
Route::get('/get-project-status-by-tl-and-pm', [ProjectController::class, 'get_project_status_by_tl_and_pm']);
Route::get('/view-project/{id}', [ProjectController::class, 'viewProjectData']);
Route::post('/projects', [ProjectController::class, 'store']);
Route::put('/projects/{id}', [ProjectController::class, 'update']);
Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);
Route::post('/assign-project-manager', [ProjectController::class, 'assignProjectToManager']);
Route::get('/assigned-all-projects', [ProjectController::class, 'getAssignedAllProjects']);
Route::get('/assigned-projects', [ProjectController::class, 'getAssignedProjects']);
Route::post('/assign-project-to-tl', [ProjectController::class, 'assignProjectToTL']);
Route::get('/tl-projects', [ProjectController::class, 'getTlProjects']);
Route::get('/user-projects', [ProjectController::class, 'getUserProjects']);
Route::get('/get-projectmanager-tl', [ProjectController::class, 'getProjectManagerTl']);
Route::get('/get-tl-employee', [ProjectController::class, 'getTlEmployee']);
Route::post('/assign-project-tl-to-employee', [ProjectController::class, 'assignProjectManagerProjectToEmployee']);
Route::post('/remove-project-managers', [ProjectController::class, 'removeProjectManagers']);
Route::delete('/remove-project-tl/{project_id}/{tl_ids}', [ProjectController::class, 'removeprojecttl']);
Route::delete('/remove-project-employee/{project_id}/{user_ids}', [ProjectController::class, 'removeprojectemployee']);
Route::get('/getfull-projectmananger-data', [ProjectController::class, 'GetFullProjectManangerData']);
Route::get('/total-departmentproject', [ProjectController::class, 'totaldepartmentProject']);
Route::get('/get-projectof-employee-assignby-projectmanager', [ProjectController::class, 'getProjectofEmployeeAssignbyProjectManager']);
Route::get('/employee-projects', [ProjectController::class, 'getemployeeProjects']);
//Route::post('/assign-project-managers', [ProjectController::class, 'getProjectEmployee']);


// App => Http => Controllers =>PerformaSheetController
Route::post('/add-performa-sheets', [PerformaSheetController::class, 'addPerformaSheets']);
Route::delete('/delete-performa-sheets', [PerformaSheetController::class, 'deletePerformaSheets']);
Route::post('/edit-performa-sheets', [PerformaSheetController::class, 'editPerformaSheets']);
Route::post('/get-approval-performa-sheets', [PerformaSheetController::class, 'approveRejectPerformaSheets']);
Route::middleware('auth:api')->group(function () {
Route::get('/get-performa-sheet', [PerformaSheetController::class, 'getUserPerformaSheets']);
Route::get('/get-all-performa-sheets', [PerformaSheetController::class, 'getAllPerformaSheets']);
Route::get('/get-performa-manager-emp', [PerformaSheetController::class, 'getPerformaManagerEmp']);
Route::post('/sink-performaapi', [PerformaSheetController::class, 'SinkPerformaAPI']);
Route::get('/get-weekly-performa-sheet', [PerformaSheetController::class, 'getUserWeeklyPerformaSheets']);
Route::get('/get-allusers-unfilled-performa-sheet', [PerformaSheetController::class, 'getAllUsersWithUnfilledPerformaSheets']);
Route::get('/get-missing-user-performa-sheet', [PerformaSheetController::class, 'getMissingUserPerformaSheets']);


// App => Http => Controllers =>LeaveController
Route::post('/add-leave', [LeaveController::class, 'Addleave']);
Route::get('/getall-leave-forhr', [LeaveController::class, 'getallLeavesForHr']);
Route::get('/getleaves-byemploye', [LeaveController::class, 'getLeavesByemploye']);
Route::get('/showmanager-leavesfor-teamemploye', [LeaveController::class, 'showmanagerLeavesForTeamemploye']);
Route::post('/approve-leave', [LeaveController::class, 'approveLeave']);
Route::get('/storage/leaves/{file}', function ($file) {
return response()->file(storage_path("app/public/leaves/$file"));
});


// App => Http => Controllers =>TaskController
Route::post('/add-task', [TaskController::class, 'AddTasks']);
Route::put('/getalltaskofprojectbyid/{id}', [TaskController::class, 'getAllTaskofProjectById']);
Route::get('/getproject/{id}', [ProjectController::class, 'getProjectById']);
Route::post('/get-emp-tasksby-project', [TaskController::class, 'getEmployeTasksbyProject']);
Route::post('/approve-task-ofproject', [TaskController::class, 'ApproveTaskofProject']);
Route::put('/edit-task/{id}', [TaskController::class, 'EditTasks']);
Route::delete('/delete-task/{id}', [TaskController::class, 'DeleteTasks']);
//Route::put('/projects/{id}', [ProjectController::class, 'update']);

// App => Http => Controllers =>GraphController
Route::post('/graph-total-workinghour', [GraphController::class, 'GraphTotalWorkingHour']);
Route::post('/get-workinghour-byproject', [GraphController::class, 'GetWorkingHourByProject']);
Route::get('/get-weekly-workinghour-byproject', [GraphController::class, 'GetWeeklyWorkingHourByProject']);
Route::get('/gettotal-workinghour-byemploye', [GraphController::class, 'GetTotalWorkingHourByEmploye']);
Route::get('/gettotal-weekly-workinghour-byemploye', [GraphController::class, 'GetTotalWeeklyWorkingHourByEmploye']);
Route::get('/get-lastsixmonths-projectcount', [GraphController::class, 'GetLastSixMonthsProjectCount']);

//App => Http => Controllers =>TagActivityController
Route::post('/addtagsactivity', [TagActivityController::class, 'AddActivityTag']);
Route::get('/getactivity-tag', [TagActivityController::class, 'GetActivityTag']);
Route::put('/updatetagsactivity/{id}', [TagActivityController::class, 'updateActivityTag']);
Route::post('/addtagsactivitys', [TagActivityController::class, 'AddActivityTags']);
Route::delete('/deletetagsactivitys/{id}', [TagActivityController::class, 'destroy']);

//App => Http => Controllers => AccessoryController
Route::post('/addaccessorycategory', [AccessoryController::class, 'addaccessorycategory']);
Route::get('/getaccessorycategory', [AccessoryController::class, 'getaccessorycategory']);
Route::get('/editaccessorycategory/{id}', [AccessoryController::class, 'editaccessorycategory']);
Route::put('/updateaccessorycategory/{id}', [AccessoryController::class, 'updateaccessorycategory']);
Route::delete('/deleteaccessorycategory/{id}', [AccessoryController::class, 'deleteaccessorycategory']);
Route::post('/addaccessory', [AccessoryController::class, 'addaccessory']);
Route::get('/allaccessory', [AccessoryController::class, 'allaccessory']);
Route::get('/getaccessory/{id}', [AccessoryController::class, 'getaccessory']);
Route::get('/editaccessory/{id}', [AccessoryController::class, 'editaccessory']);
Route::put('/updateaccessory/{id}', [AccessoryController::class, 'updateaccessory']);
Route::delete('/deleteaccessory/{id}', [AccessoryController::class, 'deleteaccessory']);
Route::post('/addaccessoryassign', [AccessoryController::class, 'addaccessoryassign']);
Route::get('/getaccessoryassign', [AccessoryController::class, 'getaccessoryassign']);
Route::get('/editaccessoryassign/{id}', [AccessoryController::class, 'editaccessoryassign']);
Route::put('/updateaccessoryassign/{id}', [AccessoryController::class, 'updateaccessoryassign']);
Route::delete('/deleteaccessoryassign/{id}', [AccessoryController::class, 'deleteaccessoryassign']);
//Route::get('/tagsactivity', [TagsActivityController::class, 'index']);
// Route::get('/countaccessory', [AccessoryController::class, 'countaccessory']);
Route::get('/get-permissions', [PermissionController::class, 'getPermissions']);
Route::post('/update-permissions', [PermissionController::class, 'store']);
Route::delete('/delete-all-permissions/{id}', [PermissionController::class, 'destroy']);
Route::apiResource('clients-master', ClientMasterController::class);
Route::apiResource('projects-master', ProjectMasterController::class);
Route::apiResource('communication-type', CommunicationTypeController::class);
Route::apiResource('project-accounts', ProjectAccountController::class);
Route::apiResource('project-sources', ProjectSourceController::class);
Route::apiResource('project-relations', ProjectRelationController::class);
});
});
