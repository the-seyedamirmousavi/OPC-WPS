<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BaleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ForceController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
 * One server: the JSON API lives under /api (web middleware group, so the session cookie and CSRF protection apply),
 * every other path is the React single-page application.
 *
 * Role names: OWNER, MANAGER, USER_1..3 ("executors"). The "management" group is OWNER and MANAGER.
 */

Route::get('/login', [SpaController::class, 'index'])->name('login');

Route::prefix('api')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth', 'suspension'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // ---- own inbox (every signed-in user) ----
        Route::get('notifications', [DashboardController::class, 'myNotifications']);
        Route::post('notifications/read-all', [DashboardController::class, 'readAll']);
        Route::post('notifications/{id}/read', [DashboardController::class, 'read']);

        // ---- own Bale connection (every signed-in user) ----
        Route::get('me/bale', [BaleController::class, 'status']);
        Route::post('me/bale-link-code', [BaleController::class, 'linkCode']);
        Route::delete('me/bale', [BaleController::class, 'unlink']);

        // ---- operations: reading and comments (executors see only their own) ----
        Route::get('operations/{id}', [OperationController::class, 'detail']);
        Route::post('operations/{id}/comment', [OperationController::class, 'comment']);

        // ---- executive users: their own tasks ----
        Route::middleware('role:USER_1,USER_2,USER_3')->group(function () {
            Route::get('my/tasks', [OperationController::class, 'myTasks']);
            Route::post('operations/{id}/start', [OperationController::class, 'start']);
            Route::post('operations/{id}/progress', [OperationController::class, 'progress']);
            Route::post('operations/{id}/complete', [OperationController::class, 'complete']);
            Route::post('operations/{id}/block', [OperationController::class, 'block']);
            Route::get('dashboard/user', [DashboardController::class, 'user']);
        });

        // ---- owner only ----
        Route::middleware('role:OWNER')->group(function () {
            Route::post('users', [UserController::class, 'create']);
            Route::patch('users/{id}', [UserController::class, 'update']);
            Route::post('users/{id}/reset-password', [UserController::class, 'resetPassword']);
            Route::patch('settings', [AdminController::class, 'update']);
            Route::put('admin/system-status', [AdminController::class, 'systemStatus']);
            Route::post('admin/reset', [AdminController::class, 'reset']);
            Route::post('admin/demo-data', [AdminController::class, 'demoData']);
            Route::get('dashboard/owner', [DashboardController::class, 'owner']);
        });

        // ---- management: owner and manager ----
        Route::middleware('role:OWNER,MANAGER')->group(function () {
            Route::get('users', [UserController::class, 'list']);

            Route::get('projects', [ProjectController::class, 'list']);
            Route::put('projects/priorities', [ProjectController::class, 'priorities']);
            Route::patch('projects/{id}', [ProjectController::class, 'update']);
            Route::post('scheduling/preview', [ProjectController::class, 'preview']);
            Route::get('scheduling/overview', [ProjectController::class, 'overview']);

            Route::get('scheduling/forces', [ForceController::class, 'list']);
            Route::post('scheduling/forces/{operationId}/preview', [ForceController::class, 'preview']);
            Route::put('scheduling/forces/{operationId}', [ForceController::class, 'apply']);
            Route::delete('scheduling/forces/{operationId}', [ForceController::class, 'remove']);
            Route::post('scheduling/reoptimize', [ForceController::class, 'reoptimize']);

            Route::get('operations', [OperationController::class, 'list']);
            Route::post('operations/{id}/unblock', [OperationController::class, 'unblock']);
            Route::post('operations/{id}/cancel', [OperationController::class, 'cancel']);
            Route::post('operations/{id}/approve', [OperationController::class, 'approve']);
            Route::post('operations/{id}/unassign', [OperationController::class, 'unassign']);
            Route::post('operations/{id}/assign', [AssignmentController::class, 'manual']);

            Route::post('assignments/suggest', [AssignmentController::class, 'suggest']);
            Route::get('assignments/latest', [AssignmentController::class, 'latest']);
            Route::get('assignments/runs', [AssignmentController::class, 'runs']);
            Route::post('assignments/approve', [AssignmentController::class, 'approve']);
            Route::post('assignments/reject', [AssignmentController::class, 'reject']);

            Route::post('import', [ImportController::class, 'upload']);
            Route::get('import/template', [ImportController::class, 'template']);
            Route::get('import/history', [ImportController::class, 'history']);

            Route::get('settings', [AdminController::class, 'settings']);
            Route::put('settings/assignment-mode', [AdminController::class, 'assignmentMode']);

            Route::get('dashboard/manager', [DashboardController::class, 'manager']);
            Route::get('reports/users', [DashboardController::class, 'userPerformance']);
            Route::get('reports/export', [DashboardController::class, 'export']);
            Route::get('audit', [DashboardController::class, 'audit']);
            Route::get('notifications/failed', [DashboardController::class, 'failed']);
        });
    });

    // unknown API paths answer as JSON, never with the single-page application
    Route::any('{any}', fn () => response()->json(['message' => 'Not found'], 404))->where('any', '.*');
});

// Single-page application: every other path (React Router handles the screens).
Route::get('/{any?}', [SpaController::class, 'index'])->where('any', '.*');
