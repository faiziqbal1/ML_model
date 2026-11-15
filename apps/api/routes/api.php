<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyManagementController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DatasetController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrganizationInvitationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PredictionController;
use App\Http\Controllers\Api\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:6,1');
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/invitations', [OrganizationInvitationController::class, 'mine']);
    Route::post('/invitations/{invitation}/accept', [OrganizationInvitationController::class, 'accept']);
    Route::post('/invitations/{invitation}/reject', [OrganizationInvitationController::class, 'reject']);

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::patch('/organizations/{organization}', [CompanyManagementController::class, 'updateProfile']);
    Route::patch('/organizations/{organization}/settings', [CompanyManagementController::class, 'updateSettings']);
    Route::get('/organizations/{organization}/users', [CompanyManagementController::class, 'users']);
    Route::patch('/organizations/{organization}/memberships/{membership}', [CompanyManagementController::class, 'updateMembership']);
    Route::delete('/organizations/{organization}/memberships/{membership}', [CompanyManagementController::class, 'revokeMembership']);
    Route::get('/organizations/{organization}/permissions', [CompanyManagementController::class, 'permissions']);
    Route::get('/organizations/{organization}/invitations', [OrganizationInvitationController::class, 'index']);
    Route::post('/organizations/{organization}/invitations', [OrganizationInvitationController::class, 'store']);
    Route::delete('/organizations/{organization}/invitations/{invitation}', [OrganizationInvitationController::class, 'revoke']);
    Route::get('/organizations/{organization}/subscription', [OrganizationController::class, 'subscription']);
    Route::post('/organizations/{organization}/subscription', [OrganizationController::class, 'activateSubscription']);
    Route::get('/organizations/{organization}/entitlements', [OrganizationController::class, 'entitlements']);
    Route::post('/organizations/{organization}/usage', [OrganizationController::class, 'recordUsage']);
    Route::get('/organizations/{organization}/models', [PredictionController::class, 'models']);
    Route::get('/organizations/{organization}/models/{modelId}', [PredictionController::class, 'model']);
    Route::get('/organizations/{organization}/predictions', [PredictionController::class, 'history']);
    Route::post('/organizations/{organization}/predictions', [PredictionController::class, 'store']);
    Route::get('/organizations/{organization}/predictions/{predictionId}', [PredictionController::class, 'show']);
    Route::get('/organizations/{organization}/predictions/{predictionId}/export', [PredictionController::class, 'export']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::get('/projects/{project}/jobs', [ProjectController::class, 'jobs']);
    Route::post('/projects/{project}/train', [ProjectController::class, 'train']);

    Route::get('/datasets', [DatasetController::class, 'index']);
    Route::post('/datasets', [DatasetController::class, 'store']);
    Route::get('/datasets/{dataset}', [DatasetController::class, 'show']);

    Route::get('/plans', [OrganizationController::class, 'plans']);
    Route::get('/features', [OrganizationController::class, 'features']);
});
