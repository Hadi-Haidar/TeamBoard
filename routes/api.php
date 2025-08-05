<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\Auth\GoogleController;

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
Route::post('/verification-status', [VerificationController::class, 'checkVerificationStatus']);
// Login routes
Route::post('/login', [LoginController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [LoginController::class, 'logout']);
// Forgot Password and Reset Password routes
Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');
Route::post('reset-password', [ResetPasswordController::class, 'reset']) ->name('password.reset');
// Google Authentication routes
Route::get('/auth/google', [GoogleController::class, 'redirectToGoogle']);
// Route::post('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);
//for testing
Route::get('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);
