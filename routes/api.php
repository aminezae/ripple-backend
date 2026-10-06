<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [\App\Http\Controllers\UserController::class, 'index']);
    Route::post('/messages', [\App\Http\Controllers\MessageController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/messages/{userId}', [\App\Http\Controllers\MessageController::class, 'index']);

    // New conversation endpoints
    Route::get('/conversations', [\App\Http\Controllers\ConversationController::class, 'index']);
    Route::post('/conversations', [\App\Http\Controllers\ConversationController::class, 'store']);
    Route::get('/conversations/{id}/messages', [\App\Http\Controllers\ConversationController::class, 'messages']);
    Route::post('/conversations/{id}/messages', [\App\Http\Controllers\ConversationController::class, 'sendMessage'])->middleware('throttle:30,1');
    Route::post('/conversations/{id}/voice-messages', [\App\Http\Controllers\ConversationController::class, 'sendVoiceMessage'])->middleware('throttle:30,1');
    Route::post('/conversations/{id}/file-messages', [\App\Http\Controllers\ConversationController::class, 'sendFileMessage'])->middleware('throttle:30,1');
    Route::get('/messages/{id}/download', [\App\Http\Controllers\ConversationController::class, 'downloadFile']);
    Route::post('/conversations/{id}/read', [\App\Http\Controllers\ConversationController::class, 'markAsRead']);
    Route::post('/conversations/{id}/pin', [\App\Http\Controllers\ConversationController::class, 'togglePin']);
    Route::post('/conversations/{id}/mute', [\App\Http\Controllers\ConversationController::class, 'toggleMute']);
    Route::post('/messages/{id}/reactions', [\App\Http\Controllers\ConversationController::class, 'toggleReaction']);
    Route::delete('/messages/{id}/reactions', [\App\Http\Controllers\ConversationController::class, 'deleteReaction']);
});

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/register', [\App\Http\Controllers\AuthController::class, 'register'])->middleware('throttle:5,1');
