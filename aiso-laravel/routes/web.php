<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AiAdminController;
use App\Http\Controllers\Api\AiLogController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\BaleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\ForceController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\PlanningController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
 * One server: the JSON API lives under /api (web middleware group, so the session cookie and CSRF protection apply),
 * every other path is the React single-page application.
 *
 * Role names: OWNER, MANAGER, USER_1..3 ("executors"). The "management" group is OWNER and MANAGER.
 */

Route::get('/login', [SpaController::class, 'index'])->name('login');

// The user management moved into the human resources panel; old bookmarks land there.
Route::get('/owner/users', [DepartmentController::class, 'legacyUsers'])->name('users.index');
Route::get('/audit', [DepartmentController::class, 'legacyAudit'])->name('audit.index');
Route::get('/import', [DepartmentController::class, 'legacyImports'])->name('imports.index');

// A department panel is a page of the single-page application; the id is the department's.
Route::view('/departments/{id}', 'app')->whereNumber('id')->name('departments.show');

Route::prefix('api')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/vendor-otp', [AuthController::class, 'vendorOtp']);

    // ---- software vendor console: answers 404 to everyone without a vendor session (see EnsureVendor) ----
    Route::prefix('vendor')->middleware('vendor')->group(function () {
        Route::get('me', [VendorController::class, 'me']);
        Route::post('logout', [VendorController::class, 'logout']);

        Route::get('company', [VendorController::class, 'overview']);
        Route::put('company', [VendorController::class, 'saveCompany']);
        Route::put('license/period', [VendorController::class, 'savePeriod']);
        Route::post('license/extend', [VendorController::class, 'extend']);
        Route::put('license/rules', [VendorController::class, 'saveRules']);
        Route::post('license/suspend', [VendorController::class, 'suspend']);
        Route::post('license/resume', [VendorController::class, 'resume']);
        Route::get('events', [VendorController::class, 'events']);

        Route::get('owner', [VendorController::class, 'owner']);
        Route::put('owner', [VendorController::class, 'setOwner']);
        Route::post('owner/reset-password', [VendorController::class, 'resetOwnerPassword']);
        Route::get('users', [VendorController::class, 'users']);
        Route::post('users', [VendorController::class, 'createUser']);
        Route::post('users/{id}/reset-password', [VendorController::class, 'resetUserPassword']);
        Route::patch('users/{id}', [VendorController::class, 'setActive']);

        Route::get('settings', [VendorController::class, 'settings']);
        Route::put('settings', [VendorController::class, 'saveSettings']);
    });

    Route::middleware(['auth', 'license', 'suspension'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // ---- own inbox (every signed-in user) ----
        Route::get('notifications', [DashboardController::class, 'myNotifications']);
        Route::post('notifications/read-all', [DashboardController::class, 'readAll']);
        Route::post('notifications/{id}/read', [DashboardController::class, 'read']);

        // ---- AI assistant chat (every signed-in user) ----
        // one question can take several AI calls, so the limit is low
        Route::post('chat', [ChatController::class, 'send'])->middleware('throttle:10,1');
        // an Excel file for the assistant: previewed by the import pipeline; importing it is a proposal to confirm
        Route::post('chat/upload', [ChatController::class, 'upload'])->middleware('throttle:10,1');
        Route::post('chat/actions/{id}/confirm', [ChatController::class, 'confirmAction'])->middleware('throttle:20,1');
        Route::post('chat/actions/{id}/cancel', [ChatController::class, 'cancelAction']);
        Route::get('chat/conversations', [ChatController::class, 'conversations']);
        Route::get('chat/conversations/{id}', [ChatController::class, 'conversation']);
        Route::delete('chat/conversations/{id}', [ChatController::class, 'destroy']);

        // ---- own Bale connection (every signed-in user) ----
        Route::get('me/bale', [BaleController::class, 'status']);
        Route::post('me/bale-link-code', [BaleController::class, 'linkCode']);
        Route::delete('me/bale', [BaleController::class, 'unlink']);

        // ---- operations: reading and comments (executors see only their own) ----
        Route::get('operations/{id}', [OperationController::class, 'detail']);
        Route::post('operations/{id}/comment', [OperationController::class, 'comment']);

        // ---- executive users: their own tasks ----
        Route::middleware('can:tasks.receive')->group(function () {
            Route::get('my/tasks', [OperationController::class, 'myTasks']);
            Route::post('operations/{id}/start', [OperationController::class, 'start']);
            Route::post('operations/{id}/progress', [OperationController::class, 'progress']);
            Route::post('operations/{id}/complete', [OperationController::class, 'complete']);
            Route::post('operations/{id}/block', [OperationController::class, 'block']);
            Route::get('dashboard/user', [DashboardController::class, 'user']);
        });

        // ---- department work: the owner, or a member whose department role holds the permission (DepartmentService) ----
        Route::get('departments/menu', [DepartmentController::class, 'menu']);
        Route::get('departments/{id}/imports', [DepartmentController::class, 'imports']);
        Route::get('departments/{id}/audit', [DepartmentController::class, 'audit']);
        Route::get('departments/{id}', [DepartmentController::class, 'show']);
        Route::patch('departments/{id}/resources/{resourceId}', [DepartmentController::class, 'updateResource']);
        Route::post('departments/{id}/suggestions', [DepartmentController::class, 'submitSuggestion']);

        // ---- owner only: the system, the company's roles, the assistant's policies, and the AI agents ----
        Route::middleware('owner')->group(function () {
            Route::get('roles', [RoleController::class, 'index']);
            Route::post('roles', [RoleController::class, 'store']);
            Route::patch('roles/{key}', [RoleController::class, 'update']);
            Route::delete('roles/{key}', [RoleController::class, 'destroy']);

            Route::patch('settings', [AdminController::class, 'update']);
            Route::put('settings/llm', [AdminController::class, 'llm']);
            Route::put('settings/bale', [AdminController::class, 'bale']);
            Route::post('settings/bale/check', [AdminController::class, 'baleCheck']);
            Route::put('admin/system-status', [AdminController::class, 'systemStatus']);
            Route::post('admin/reset', [AdminController::class, 'reset']);
            Route::post('admin/demo-data', [AdminController::class, 'demoData']);
            Route::get('dashboard/owner', [DashboardController::class, 'owner']);

            // ---- what the assistant may do, and the requests waiting for the owner ----
            Route::get('ai/approvals', [AiAdminController::class, 'approvals']);
            Route::post('ai/approvals/{id}/approve', [AiAdminController::class, 'approve']);
            Route::post('ai/approvals/{id}/reject', [AiAdminController::class, 'reject']);
            Route::get('ai/policies', [AiAdminController::class, 'policies']);
            // what the assistant was given and what it answered, step by step (read only)
            Route::get('ai/runs', [AiLogController::class, 'runs']);
            Route::get('ai/runs/{id}', [AiLogController::class, 'run'])->whereNumber('id');
            Route::get('ai/prompt', [AiLogController::class, 'prompt']);
            Route::put('ai/policies', [AiAdminController::class, 'savePolicies']);

            // ---- AI agents (virtual users), in the human resources workspace ----
            Route::get('agents', [AgentController::class, 'index']);
            Route::post('agents', [AgentController::class, 'store']);
            Route::patch('agents/{id}', [AgentController::class, 'update']);
            Route::get('agents/{id}/tasks', [AgentController::class, 'tasks']);
            Route::post('agents/{id}/tasks', [AgentController::class, 'giveTask'])->middleware('throttle:10,1');
        });

        // ---- users: changing accounts (users.manage; the list itself is users.view below) ----
        Route::middleware('can:users.manage')->group(function () {
            Route::post('users', [UserController::class, 'create']);
            Route::patch('users/{id}', [UserController::class, 'update']);
            Route::delete('users/{id}', [UserController::class, 'destroy']);
            Route::post('users/{id}/reset-password', [UserController::class, 'resetPassword']);
            // the roles a person can be given, for the user form
            Route::get('roles/assignable', [RoleController::class, 'assignable']);
        });

        // ---- departments: the structure, members and what each department role may do (departments.manage) ----
        Route::middleware('can:departments.manage')->group(function () {
            Route::get('departments', [DepartmentController::class, 'index']);
            Route::post('departments', [DepartmentController::class, 'store']);
            Route::patch('departments/{id}', [DepartmentController::class, 'update']);
            Route::put('departments/{id}/members', [DepartmentController::class, 'members']);
            Route::put('departments/{id}/permissions', [DepartmentController::class, 'permissions']);
            Route::put('departments/{id}/resources', [DepartmentController::class, 'assignResources']);
        });

        // ---- the team list (users.view) ----
        Route::middleware('can:users.view')->group(function () {
            Route::get('users', [UserController::class, 'list']);
        });

        // ---- production: reading the workspace (production.view) ----
        Route::middleware('can:production.view')->group(function () {
            Route::get('projects', [ProjectController::class, 'list']);
            Route::get('scheduling/overview', [ProjectController::class, 'overview']);
            Route::get('scheduling/forces', [ForceController::class, 'list']);
            Route::get('operations', [OperationController::class, 'list']);
            Route::get('assignments/latest', [AssignmentController::class, 'latest']);
            Route::get('assignments/runs', [AssignmentController::class, 'runs']);
            Route::get('planning/plan', [PlanningController::class, 'plan']);
            Route::get('planning/goals', [PlanningController::class, 'goals']);
            Route::get('planning/export', [PlanningController::class, 'export']);
            Route::get('import/template', [ImportController::class, 'template']);
            Route::get('import/history', [ImportController::class, 'history']);
            Route::get('settings', [AdminController::class, 'settings']);
            Route::get('dashboard/manager', [DashboardController::class, 'manager']);
            Route::get('reports/users', [DashboardController::class, 'userPerformance']);
            Route::get('reports/export', [DashboardController::class, 'export']);
            Route::get('audit', [DashboardController::class, 'audit']);
            Route::get('notifications/failed', [DashboardController::class, 'failed']);
            Route::get('department-suggestions', [DepartmentController::class, 'suggestions']);
        });

        // ---- production: changing it (production.manage) ----
        Route::middleware('can:production.manage')->group(function () {
            Route::put('projects/priorities', [ProjectController::class, 'priorities']);
            Route::patch('projects/{id}', [ProjectController::class, 'update']);
            Route::post('scheduling/preview', [ProjectController::class, 'preview']);
            Route::post('scheduling/forces/{operationId}/preview', [ForceController::class, 'preview']);
            Route::put('scheduling/forces/{operationId}', [ForceController::class, 'apply']);
            Route::delete('scheduling/forces/{operationId}', [ForceController::class, 'remove']);
            Route::post('scheduling/reoptimize', [ForceController::class, 'reoptimize']);

            Route::post('operations/{id}/unblock', [OperationController::class, 'unblock']);
            Route::post('operations/{id}/cancel', [OperationController::class, 'cancel']);
            Route::post('operations/{id}/approve', [OperationController::class, 'approve']);
            Route::post('operations/{id}/unassign', [OperationController::class, 'unassign']);
            Route::post('operations/{id}/assign', [AssignmentController::class, 'manual']);

            Route::post('assignments/suggest', [AssignmentController::class, 'suggest']);
            Route::post('assignments/approve', [AssignmentController::class, 'approve']);
            Route::post('assignments/reject', [AssignmentController::class, 'reject']);

            Route::post('planning/submit', [PlanningController::class, 'submit']);
            Route::post('planning/from-excel', [PlanningController::class, 'fromExcel']);
            Route::put('planning/goal', [PlanningController::class, 'setGoal']);

            Route::post('import', [ImportController::class, 'upload']);
            Route::put('settings/assignment-mode', [AdminController::class, 'assignmentMode']);

            // ---- department suggestions, decided on the manager dashboard ----
            Route::post('department-suggestions/{id}/decide', [DepartmentController::class, 'decide']);
        });
    });

    // unknown API paths answer as JSON, never with the single-page application
    Route::any('{any}', fn () => response()->json(['message' => 'Not found'], 404))->where('any', '.*');
});

// Single-page application: every other path (React Router handles the screens).
Route::get('/{any?}', [SpaController::class, 'index'])->where('any', '.*');
