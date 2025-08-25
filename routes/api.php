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