<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\GoogleController;
use App\Http\Controllers\InfluencerController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\CollaborationController;
use App\Http\Controllers\CollaborationRequestController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/register/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/register/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('password/send-otp', [AuthController::class, 'sendOtpForResetPassword']);
Route::post('password/verify-password-otp', [AuthController::class, 'verifyPasswordOtp']);
Route::post('password/reset', [AuthController::class, 'resetPassword']);

Route::post('auth/google/register', [GoogleController::class, 'googleLogin']);

Route::get('/run-artisan-command', function () {
    Artisan::call('storage:link');

    return response()->json(['message' => 'Command executed successfully']);
});

Route::middleware('web')->group(function () {
    //google authentication
    Route::get('auth/redirect/google', [GoogleController::class, 'redirectToGoogle']);
    Route::get('auth/callback/google', [GoogleController::class, 'handleGoogleCallback']);

    //instagram authentication
    Route::get('auth/instagram', [SocialAuthController::class, 'redirectToProvider']);
    Route::get('auth/instagram/callback', [SocialAuthController::class, 'handleProviderCallback']);
    Route::post('auth/instagram/deauthorize', [SocialAuthController::class, 'deauthorize']);
    Route::post('auth/instagram/delete', [SocialAuthController::class, 'handleDeletionRequest']);
});

Route::middleware('auth:sanctum')->group(function () {
    // logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // get categories
    Route::get('/categories', [HomeController::class, 'categories']);

    // search
    Route::get('/search', [HomeController::class, 'search']);

    // trending influencers
    Route::get('/trending-influencers', [InfluencerController::class, 'getTrendingInfluencers']);
    // top collaboratorated influencers
    Route::get('/top-collaborated-influencers', [InfluencerController::class, 'getTopCollaboratedInfluencers']);
    // suggested collaborations
    Route::get('/suggested-collaborations-by-category', [HomeController::class, 'getCollaborationsForInfluencersByCategory']);

    //get top brands by collaboration count
    Route::get('/top-brands', [HomeController::class, 'getTopBrands']);

    // Influencer routes
    Route::get('/influencer', [InfluencerController::class, 'index']);
    Route::post('/influencer/update', [InfluencerController::class, 'update']);
    Route::delete('/influencers/{influencer}', [InfluencerController::class, 'destroy']);
    // get influencers details
    Route::get('/influencers/{influencer}', [InfluencerController::class, 'show']);
    // Get influencers by category
    Route::post('/influencers/category', [HomeController::class, 'getInfluencersByCategory']);
    //get all influencers
    Route::get('/influencers/all', [HomeController::class, 'AllInfluencers']);

    // Brand routes
    Route::get('/brand', [BrandController::class, 'index']);
    Route::post('/brand/update', [BrandController::class, 'update']);
    Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);
    //get brand details
    Route::get('/brands/{brand}', [BrandController::class, 'show']);
    // Get brands by category
    Route::post('/brands/category', [HomeController::class, 'getBrandsByCategory']);
    //get all brands
    Route::get('/brands/all', [HomeController::class, 'AllBrands']);

    // Post routes
    Route::post('/posts/store', [PostController::class, 'store']);
    Route::get('/posts/{user_id}', [PostController::class, 'getPostsByUser']);
    Route::post('/posts/{post}/like/add', [PostController::class, 'likePost']);
    Route::get('/posts/{post}/likes', [PostController::class, 'getLikes']);
    Route::delete('/posts/{post}/delete', [PostController::class, 'destroy']);

    // Routes for brands to create and manage collaborations
    Route::post('collaboration/create', [CollaborationController::class, 'createCollaboration']);
    // fetch influencers interested in a collaboration
    Route::get('collaborations/{collaborationId}/interested-influencers', [CollaborationController::class, 'fetchInterestedInfluencers']);
    // fetch influencers interested in all collaborations
    Route::get('collaborations/interested-influencers-for-all-collaborations', [CollaborationController::class, 'fetchInterestedInfluencersForAllCollaborations']);
    // accept an influencer for a collaboration
    Route::post('collaborations/accept-influencer/{collaborationRequestId}', [CollaborationController::class, 'acceptInfluencer']);
    // delete a collaboration
    Route::delete('collaborations/{collaborationId}/delete', [CollaborationController::class, 'deleteCollaboration']);
    // get all collaborations for a brand
    Route::get('/collaborations', [BrandController::class, 'getCollaborations']);
    // invite an influencer for a collaboration
    Route::post('/collaborations/invite', [CollaborationRequestController::class, 'inviteInfluencerForCollaboration']);
    // complete a collaboration
    Route::post('/collaborations/{collaborationId}/complete', [CollaborationController::class, 'completeCollaboration']);
    // mark an influencer as interested in a collaboration
    Route::post('/collaborations/mark-interested', [CollaborationRequestController::class, 'markInterestedForCollaboration']);

    // Routes for influencers to view and update their collaboration requests
    Route::get('collaboration-requests', [CollaborationController::class, 'fetchCollaborationRequests']);
    Route::post('collaboration-requests/{collaborationRequestId}/status', [CollaborationController::class, 'updateCollaborationStatus']);

    // get collaborations by user
    Route::get('/collaborations/{userId}', [CollaborationController::class, 'getCollaborationsByUserId']);


    // Chat routes
    Route::prefix('chats')->group(function () {
        // Send a new message
        Route::post('/message/send/{recipientId}', [ChatController::class, 'sendMessageBetweenUsers']);

        Route::post('/{chatId}/message', [ChatController::class, 'sendMessage']);

        // Reply to a message
        Route::post('/{chatId}/message/{messageId}', [ChatController::class, 'sendMessage']);

        // Fetch chat history
        Route::get('/{chatId}/messages/history', [ChatController::class, 'fetchChatHistory']);

        // Mark messages as read
        Route::post('/{chatId}/mark-messages-as-read', [ChatController::class, 'markMessagesAsRead']);

        // Delete a message
        Route::delete('/{chatId}/message/{messageId}/delete', [ChatController::class, 'deleteMessage']);
    });

});

Route::get('/create-symlink', function () {
    symlink('/home/apptest.zenerom.com/circuitil/storage/app/public', '/home/apptest.zenerom.com/public_html/storage');
    return response()->json(['message' => 'Symlink created successfully']);
});

Route::get('/encrypt-messages', [ChatController::class, 'encryptMessagesField']);
