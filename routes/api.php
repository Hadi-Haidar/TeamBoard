<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\BoardController;
use App\Http\Controllers\User\BoardMemberController;
use App\Http\Controllers\User\ListController;
use App\Http\Controllers\User\TaskController;
use App\Http\Controllers\User\CommentController;
use App\Http\Controllers\User\AttachmentController;
// Add this middleware to routes that need verified email
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    // Protected routes that require verified email
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

// Auth routes

// Register routes
Route::post('/register', [RegisterController::class, 'register']);
// Verification routes
Route::post('/verify-email', [VerificationController::class, 'verifyEmail']);
Route::post('/resend-verification', [VerificationController::class, 'resendVerificationCode']);
// Route::post('/verification-status', [VerificationController::class, 'checkVerificationStatus']);
// Login routes
Route::post('/login', [LoginController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [LoginController::class, 'logout']);
// Forgot Password and Reset Password routes
Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');
Route::get('reset-password', [ResetPasswordController::class, 'showResetForm'])->name('password.reset.form');
Route::post('reset-password', [ResetPasswordController::class, 'reset']) ->name('password.reset');
// Google Authentication routes
Route::get('/auth/google', [GoogleController::class, 'redirectToGoogle']);
// Google OAuth callback needs session middleware for web authentication
Route::middleware(['web'])->get('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);

// User Profile routes (requires authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/user/confirm-email-change', [ProfileController::class, 'confirmEmailChange']);
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
});

// Board Management routes (requires authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/boards', [BoardController::class, 'index']);
    Route::post('/boards', [BoardController::class, 'store']);
    Route::get('/boards/{id}', [BoardController::class, 'show']);
    Route::put('/boards/{id}', [BoardController::class, 'update']);
    Route::delete('/boards/{id}', [BoardController::class, 'destroy']);
});

// Board Member Management routes (requires authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/boards/{boardId}/members', [BoardMemberController::class, 'index']);
    Route::post('/boards/{boardId}/members', [BoardMemberController::class, 'invite']);
    Route::put('/boards/{boardId}/members/{memberId}', [BoardMemberController::class, 'update']);
    Route::delete('/boards/{boardId}/members/{memberId}', [BoardMemberController::class, 'destroy']);
});
// these routes for accepting and declining invitations (they don't need auth since token provides authentication)
Route::get('/invitations/{token}', [BoardMemberController::class, 'showInvitation']);
Route::post('/invitations/{token}/accept', [BoardMemberController::class, 'acceptInvitation']);
Route::post('/invitations/{token}/decline', [BoardMemberController::class, 'declineInvitation']);

// List Management routes (requires authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/boards/{boardId}/lists', [ListController::class, 'index']);
    Route::post('/boards/{boardId}/lists', [ListController::class, 'store']);
    Route::get('/lists/{listId}', [ListController::class, 'show']);
    Route::put('/lists/{listId}', [ListController::class, 'update']);
    Route::delete('/lists/{listId}', [ListController::class, 'destroy']);
    Route::put('/lists/{listId}/position', [ListController::class, 'updatePosition']); 
});

// Task Management routes (requires authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/lists/{listId}/tasks', [TaskController::class, 'index']);
    Route::post('/lists/{listId}/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{taskId}', [TaskController::class, 'show']);
    Route::put('/tasks/{taskId}', [TaskController::class, 'update']);
    Route::delete('/tasks/{taskId}', [TaskController::class, 'destroy']);
    Route::put('/tasks/{taskId}/move', [TaskController::class, 'move']);
    Route::put('/tasks/{taskId}/position', [TaskController::class, 'updatePosition']);
    Route::put('/tasks/{taskId}/assign', [TaskController::class, 'assign']);
});

// Comment Management routes (requires authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/tasks/{taskId}/comments', [CommentController::class, 'index']);      // Get all comments
    Route::post('/tasks/{taskId}/comments', [CommentController::class, 'store']);     // Create comment (REAL-TIME)
    Route::put('/comments/{commentId}', [CommentController::class, 'update']);        // Update comment (REAL-TIME)
    Route::delete('/comments/{commentId}', [CommentController::class, 'destroy']);    // Delete comment (REAL-TIME)
});

// Attachment Management routes (requires authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/tasks/{taskId}/attachments', [AttachmentController::class, 'index']);
    Route::post('/tasks/{taskId}/attachments', [AttachmentController::class, 'store']);
    Route::get('/attachments/{attachmentId}', [AttachmentController::class, 'show']);
    Route::get('/attachments/{attachmentId}/download', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('/attachments/{attachmentId}', [AttachmentController::class, 'destroy']);
});