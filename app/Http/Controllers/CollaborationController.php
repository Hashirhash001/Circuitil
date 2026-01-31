<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Chat;
use App\Models\User;
use App\Models\Brand;
use App\Models\Influencer;
use Illuminate\Http\Request;
use App\Models\Collaboration;
use App\Models\ChatParticipant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\Notification;
use App\Models\CollaborationRequest;
use App\Services\NotificationService;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CollaborationController extends Controller
{
    // Create a new collaboration
    public function createCollaboration(Request $request, NotificationService $notificationService, PushNotificationService $pushNotificationService)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Check if the user is a brand
        if ($user->role !== 'brand') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Validate the request, including image
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|array', // Expecting multiple categories
            'category.*' => 'required|integer|exists:categories,id', // Validate that each category exists
            'description' => 'nullable|string',
            'amount' => 'required|numeric',
            'end_date' => ['required', 'date_format:d-m-Y', function ($attribute, $value, $fail) {
                $endDate = Carbon::createFromFormat('d-m-Y', $value);
                if ($endDate->isPast()) {
                    $fail('The end date must be a future date.');
                }
            }],
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Handle the image upload
        $data = $validator->validated();

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('collaboration_images', 'public');
            $data['image'] = $imagePath;
        }

        // Get the brand_id for the authenticated user
        $brand = Brand::where('user_id', $user->id)->first();
        if (!$brand) {
            return response()->json(['error' => 'Brand not found'], 404);
        }
        $data['brand_id'] = $brand->id;

        // Make sure the category is stored as a JSON string
        $data['category'] = json_encode($data['category']);

        // Create the collaboration
        $collaboration = Collaboration::create($data);

        // Decode categories before looping
        $categories = json_decode($data['category'], true);

        // Loop through each category and find influencers
        foreach ($categories as $categoryId) {
            $influencers = Influencer::whereJsonContains('category', $categoryId) // Match influencers who have this category
                ->where('collab_value', '<=', $data['amount'])
                ->get();

            // Create a collaboration request for each influencer
            foreach ($influencers as $influencer) {
                // Check if the collaboration request already exists
                $existingRequest = CollaborationRequest::where('collaboration_id', $collaboration->id)
                    ->where('influencer_id', $influencer->id)
                    ->first();

                if (!$existingRequest) {
                    $collaborationRequest = CollaborationRequest::create([
                        'collaboration_id' => $collaboration->id,
                        'influencer_id' => $influencer->id,
                        'status' => 1, // pending
                    ]);

                    // Notify the influencer about the new collaboration
                    $notificationService->createNotification(
                        $influencer->user_id,
                        'collaboration_suggestion',
                        [
                            'message' => "You may be interested in the collaboration " . strtoupper($collaboration->name) . ", check it out.",
                            'collaboration_id' => $collaboration->id,
                            'brand_id' => $collaboration->brand->id,
                            'collaboration_request_id' => $collaborationRequest->id,
                            'collaboration_request_status' => $collaborationRequest->status,
                            'collaboration_image' => $collaboration->image
                        ]
                    );

                    // Prepare data for push notification
                    $pushNotificationData = [
                        'title' => "New Collaboration Opportunity",
                        'body' => "You may be interested in the collaboration " . strtoupper($collaboration->name) . ".",
                        'image' => $collaboration->image, // Assuming image is the collaboration image
                        'data' => [
                            'collaboration_id' => $collaboration->id,
                            'collaboration_request_id' => $collaborationRequest->id,
                            'message' => "You may be interested in the collaboration '{$collaboration->name}', check it out.",
                        ]
                    ];

                    $fcmTokens = $influencer->user->fcmTokens->pluck('fcm_token')->toArray();
                    $pushNotificationService->sendPushNotification($fcmTokens, $pushNotificationData);
                }
            }
        }

        return response()->json(['success' => true, 'collaboration' => $collaboration]);
    }

    // Fetch collaboration requests for an influencer
    public function fetchCollaborationRequests()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'influencer') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Fetch influencer by user ID
        $influencer = Influencer::where('user_id', $user->id)->first();

        if (!$influencer) {
            return response()->json(['error' => 'Influencer profile not found'], 404);
        }

        // Fetch collaboration requests for the influencer
        $requests = CollaborationRequest::with('collaboration.brand.user') // Eager load brand relationship
            ->where('influencer_id', $influencer->id)
            ->whereIn('status', [1, 3, 4])
            ->orderBy('created_at', 'desc')
            ->whereHas('collaboration', function ($query) {
                $query->whereDate(DB::raw("STR_TO_DATE(end_date, '%d-%m-%Y')"), '>=', now());
            })
            ->get()
            ->map(function ($request) {
                return [
                    'id' => $request->id,
                    'collaboration_id' => $request->collaboration_id,
                    'influencer_id' => $request->influencer_id,
                    'status' => $request->status,
                    'deleted_at' => $request->deleted_at,
                    'created_at' => $request->created_at,
                    'updated_at' => $request->updated_at,
                    'collaboration' => [
                        'id' => $request->collaboration->id,
                        'user_id' => $request->collaboration->brand->user->id,
                        'brand_id' => $request->collaboration->brand_id,
                        'brand_name' => $request->collaboration->brand->name,
                        'name' => $request->collaboration->name,
                        'image' => $request->collaboration->image,
                        'category' => $request->collaboration->category,
                        'description' => $request->collaboration->description,
                        'amount' => $request->collaboration->amount,
                        'end_date' => $request->collaboration->end_date,
                        'deleted_at' => $request->collaboration->deleted_at,
                        'created_at' => $request->collaboration->created_at,
                        'updated_at' => $request->collaboration->updated_at,
                    ],
                ];
            });

        return response()->json(['collaborations' => $requests]);
    }

    // Update collaboration request status by influencer
    public function updateCollaborationStatus(Request $request, $collaborationRequestId, PushNotificationService $pushNotificationService)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'influencer') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Validate request status
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:2,6', // 2 = interested, 6 = rejected
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $collaborationRequest = CollaborationRequest::find($collaborationRequestId);

        if (!$collaborationRequest) {
            return response()->json(['success' => false, 'error' => 'Collaboration request not found'], 404);
        }

        // Fetch influencer by user ID
        $influencer = Influencer::where('user_id', $user->id)->first();

        if (!$influencer) {
            return response()->json(['error' => 'Influencer profile not found'], 404);
        }

        // Check if the authenticated influencer is the owner of the collaboration request
        if ($collaborationRequest->influencer_id !== $influencer->id) {
            return response()->json(['error' => 'You are not authorized to update this collaboration request'], 403);
        }

        // Fetch the related collaboration
        $collaboration = Collaboration::find($collaborationRequest->collaboration_id);

        if (!$collaboration) {
            return response()->json(['error' => 'Collaboration not found'], 404);
        }

        // Check if the collaboration end_date has passed
        $currentDate = now();
        if ($currentDate->gt($collaboration->end_date)) {
            return response()->json(['error' => 'Cannot update status: Collaboration has ended'], 400);
        }

        // Update the status of the collaboration request
        $updateSuccess = $collaborationRequest->update(['status' => $request->status]);

        $notification = Notification::where('user_id', $user->id)
        // ->where('type', 'collaboration_suggestion')
        ->where('data', 'like', '%"collaboration_request_id":' . $collaborationRequestId . '%')
        ->first();

        if ($notification) {
            // Decode existing notification data
            $notificationData = json_decode($notification->data, true);

            // Update the status field in the notification data
            $notificationData['collaboration_request_status'] = $request->status;

            // Save the updated notification data
            $notification->update([
                'data' => json_encode($notificationData),
            ]);
        }

        // Return response based on whether the update was successful
        if ($updateSuccess) {
            $statusMessages = [
                2 => 'Collaboration request marked as interested.',
                6 => 'Collaboration request marked as rejected.',
            ];

            // Only send notification if the status is '2' (interested)
            if ($request->status == 2) {
                // Notify the brand (collaboration owner) about the status change
                $notificationService = new NotificationService();

                $notificationService->createNotification(
                    $collaboration->brand->user_id, // The brand owner's user ID
                    'collaboration_interest', // Notification type
                    [
                        'message' => strtoupper($influencer->name). " has expressed interest for your " .strtoupper($collaboration->name). " collaboration.",
                        'collaboration_id' => $collaboration->id,
                        'collaboration_image' => $collaboration->image,
                        'collaboration_request_id' => $collaborationRequest->id,
                        'influencer_id' => $influencer->id,
                        'brand_id' => $collaboration->brand->user_id,
                        'status' => $request->status,
                    ]
                );

                // Prepare push notification data
                $pushNotificationData = [
                    'title' => "Collaboration Interest",
                    'body' => "{$influencer->name} has expressed interest in your collaboration '{$collaboration->name}'",
                    'image' => $collaboration->image, // Assuming image is the collaboration image
                    'data' => [
                        'collaboration_id' => $collaboration->id,
                        'collaboration_request_id' => $collaborationRequest->id,
                        'influencer_id' => $influencer->id,
                        'status' => $request->status,
                        'message' => "{$influencer->name} has expressed interest for your '{$collaboration->name}' collaboration.",
                    ]
                ];

                $fcmTokens = $collaboration->brand->user->fcmTokens->pluck('fcm_token')->toArray();
                $pushNotificationService->sendPushNotification($fcmTokens, $pushNotificationData);

            }

            return response()->json([
                'success' => true,
                'message' => $statusMessages[$request->status] ?? 'Status updated successfully'
            ]);
        } else {
            return response()->json(['error' => 'Failed to update collaboration request status'], 500);
        }

        return response()->json(['success' => true]);
    }

    // Fetch interested influencers for a collaboration
    public function fetchInterestedInfluencers($collaborationId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'brand') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $collaboration = Collaboration::find($collaborationId);

        if (!$collaboration) {
            return response()->json(['success' => true, 'message' => 'Collaboration not found']);
        }

        $interestedInfluencers = CollaborationRequest::with('influencer')
            ->where('collaboration_id', $collaborationId)
            ->where(function ($query) {
                $query->where('status', 2)  // 2 = interested
                      ->orWhere('status', 3) // 3 = invited
                      ->orWhere('status', 4) // 4 = accepted
                      ->orWhere('status', 5); // 5 = completed
            })
            ->orderBy('created_at', 'desc')
            ->get();

        if ($interestedInfluencers->isEmpty()) {
            return response()->json(['success' => true, 'message' => 'No interested or accepted influencers found']);
        }

        return response()->json(['success' => true, 'influencers' => $interestedInfluencers]);
    }

    // Accept an influencer for a collaboration
    public function acceptInfluencer(Request $request, $collaborationRequestId, PushNotificationService $pushNotificationService)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Find the brand associated with the authenticated user
        $brand = Brand::where('user_id', $user->id)->first();

        if (!$brand) {
            return response()->json(['success' => false, 'error' => 'Brand profile not found'], 404);
        }

        // Validate the request
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:4,6', // 4 = accepted or 6 = rejected
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'error' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Ensure the user is a brand
        if ($user->role !== 'brand') {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        // Find the collaboration request
        $collaborationRequest = CollaborationRequest::find($collaborationRequestId);

        if (!$collaborationRequest) {
            return response()->json(['success' => false, 'error' => 'Collaboration request not found'], 404);
        }

        // Fetch the associated collaboration
        $collaboration = Collaboration::find($collaborationRequest->collaboration_id);

        if (!$collaboration) {
            return response()->json(['success' => false, 'error' => 'Collaboration not found'], 404);
        }

        // Ensure the collaboration was created by the authenticated brand
        if ($collaboration->brand_id !== $brand->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized: You are not the owner of this collaboration'], 403);
        }

        // Check if the collaboration end_date has passed
        $currentDate = now();
        if ($currentDate->gt($collaboration->end_date)) {
            return response()->json(['success' => false, 'error' => 'Cannot update status: Collaboration has ended'], 400);
        }

        // Ensure that no other collaboration request has already been accepted for this collaboration
        // $existingAcceptedRequest = CollaborationRequest::where('collaboration_id', $collaboration->id)
        //     ->where('status', 4) // Status 3 means accepted
        //     ->first();

        // if ($existingAcceptedRequest && $existingAcceptedRequest->id !== $collaborationRequestId) {
        //     return response()->json(['success' => false, 'error' => 'This collaboration already has an accepted influencer.'], 400);
        // }

         // Check if the influencer has shown interest or been invited (status = 2, 3)
        if (!in_array($collaborationRequest->status, [2, 3])) {
            return response()->json([
                'success' => false,
                'error' => 'Only requests with interested or invited influencers can be accepted or rejected'
            ], 403);
        }

        // Update the status of the collaboration request
        $collaborationRequest->update($data);

        $notification = Notification::where('user_id', $brand->user_id)
        // ->where('type', 'collaboration_interest')
        ->where('data', 'like', '%"collaboration_request_id":' . $collaborationRequestId . '%')
        ->first();

        if ($notification) {
            // Decode existing notification data
            $notificationData = json_decode($notification->data, true);

            // Update the status field in the notification data
            $notificationData['collaboration_request_status'] = $data['status'];

            // Save the updated notification data
            $notification->update([
                'data' => json_encode($notificationData),
            ]);
        }

        // Initiate a chat if accepted
        if ($data['status'] == 4) {
            // Check if a chat already exists between the brand and influencer
            $influencerId = $collaborationRequest->influencer->user_id; // Assuming this exists in your relation
            $existingChat = Chat::whereHas('participants', function ($query) use ($brand, $influencerId) {
                $query->whereIn('user_id', [$brand->user_id, $influencerId]);
            })
            ->get()
            ->filter(function ($chat) use ($brand, $influencerId) {
                $participantIds = $chat->participants->pluck('user_id')->toArray();
                return in_array($brand->user_id, $participantIds) && in_array($influencerId, $participantIds);
            })->first();


            if (!$existingChat) {
                // Create a new chat
                $chat = Chat::create([
                    'created_by' => $user->id,
                ]);

                // Add brand and influencer as participants
                ChatParticipant::create([
                    'chat_id' => $chat->id,
                    'user_id' => $brand->user_id, // Brand
                ]);

                ChatParticipant::create([
                    'chat_id' => $chat->id,
                    'user_id' => $influencerId, // Influencer
                ]);
            }

            // Send notification to the influencer about the acceptance
            $notificationService = new NotificationService();
            $notificationService->createNotification(
                $influencerId,
                'collaboration_acceptance', // Notification type
                [
                    'message' => "Your request to collaborate on " . strtoupper($collaboration->name) . " has been accepted by " . strtoupper($brand->name) . ".",
                    'collaboration_id' => $collaboration->id,
                    'collaboration_request_id' => $collaborationRequest->id,
                    'collaboration_image' => $collaboration->image,
                    'brand_id' => $collaboration->brand->id,
                ]
            );

            // Prepare push notification data for the influencer
            $pushNotificationData = [
                'title' => "Collaboration Acceptance",
                'body' => "Your request to collaborate on " . strtoupper($collaboration->name) . " has been accepted by " . strtoupper($brand->name) . ".",
                'image' => $collaboration->image,
                'data' => [
                    'collaboration_id' => $collaboration->id,
                    'collaboration_request_id' => $collaborationRequest->id,
                    'influencer_id' => $influencerId,
                    'status' => 4, // Accepted status
                    'message' => "Your request to collaborate on " . strtoupper($collaboration->name) . " has been accepted.",
                ]
            ];

            // Send push notification to the influencer
            $fcmTokens = $collaborationRequest->influencer->user->fcmTokens->pluck('fcm_token')->toArray();
            $pushNotificationService->sendPushNotification($fcmTokens, $pushNotificationData);
        }

        // Return a response based on the new status
        $message = $data['status'] == 4 ? 'Collaboration request accepted.' : 'Collaboration request rejected.';

        return response()->json(['success' => true, 'message' => $message]);
    }

    // Fetch interested influencers for all collaborations by a brand(brand notification)
    public function fetchInterestedInfluencersForAllCollaborations()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'brand') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Fetch the brand information based on the authenticated user
        $brand = Brand::where('user_id', $user->id)->first();

        if (!$brand) {
            return response()->json(['success' => false, 'message' => 'Brand not found']);
        }

        // Fetch all collaborations created by the brand
        $collaborations = Collaboration::where('brand_id', $brand->id)->get();

        if ($collaborations->isEmpty()) {
            return response()->json(['success' => true, 'message' => 'No collaborations found']);
        }

        // Fetch interested influencers for all collaborations
        $interestedInfluencers = CollaborationRequest::with('influencer', 'collaboration')
            ->whereIn('collaboration_id', $collaborations->pluck('id')) // Get collaboration IDs
            ->where('status', 2) // 2 = interested
            ->orderBy('created_at', 'desc')
            ->get();

        if ($interestedInfluencers->isEmpty()) {
            return response()->json(['success' => true, 'message' => 'No interested influencers found']);
        }

        return response()->json(['success' => true, 'interested_influencers' => $interestedInfluencers]);
    }

    public function deleteCollaboration($id)
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Find the collaboration by ID
        $collaboration = Collaboration::find($id);

        if (!$collaboration) {
            return response()->json(['error' => 'Collaboration not found'], 404);
        }

        // Ensure the authenticated user is the brand that created the collaboration
        $brand = Brand::where('user_id', $user->id)->first();
        if (!$brand || $collaboration->brand_id !== $brand->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Delete any related collaboration requests
        CollaborationRequest::where('collaboration_id', $collaboration->id)->delete();

        // Delete notifications related to this collaboration
        Notification::whereJsonContains('data->collaboration_id', $collaboration->id)->delete();

        // Delete the collaboration
        $collaboration->delete();

        return response()->json(['success' => true, 'message' => 'Collaboration deleted successfully']);
    }

    public function getCollaborationsByUserId($userId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Fetch the user by the provided userId
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Initialize variables
        $collaborations = collect();

        if ($user->role === 'brand') {
            // For brands, fetch all collaborations
            $collaborations = $user->brand->collaborations ?? collect();
        } elseif ($user->role === 'influencer') {
            // Validate influencer profile
            $influencer = $user->influencer;
            if (!$influencer) {
                return response()->json(['error' => 'Influencer profile not found for this user'], 404);
            }

            // Fetch collaborations that have been accepted or completed
            $collaborations = Collaboration::whereHas('collaborationRequests', function ($query) use ($influencer) {
                $query->where('influencer_id', $influencer->id)
                      ->whereIn('status', [4, 5]); // Accepted or Completed
            })->get();
        } else {
            return response()->json(['error' => 'Invalid user role'], 403);
        }

        // Map through collaborations and add additional details
        $collaborationsWithDetails = $collaborations->map(function ($collaboration) {
            $currentDate = now();
            $endDate = $collaboration->end_date;
            $hasEnded = $endDate && $currentDate->greaterThanOrEqualTo($endDate);

            // Check if this specific collaboration has completed requests
            $hasCompletedCollaboration = $collaboration->collaborationRequests()
                ->where('status', 5) // Completed
                ->exists();

            // Retrieve the first accepted collaboration request
            $acceptedRequest = $collaboration->collaborationRequests()
                ->where('status', 4) // Accepted
                ->with('influencer')
                ->first();

            $influencer = $acceptedRequest ? $acceptedRequest->influencer : null;

            return $collaboration->toArray() + [
                'has_ended' => $hasEnded,
                'has_completed_collaboration' => $hasCompletedCollaboration,
                'influencer' => $influencer ? [
                    'id' => $influencer->id,
                    'name' => $influencer->name,
                    'category' => $influencer->category,
                    'about' => $influencer->about,
                    'profile_photo' => $influencer->profile_photo,
                    'collab_value' => $influencer->collab_value,
                    'social_media_links' => json_decode($influencer->social_media_links, true),
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'collaborations' => $collaborationsWithDetails
        ]);
    }

    // Complete a collaboration
    public function completeCollaboration($collaborationRequestId, PushNotificationService $pushNotificationService)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Find the brand associated with the authenticated user
        $brand = Brand::where('user_id', $user->id)->first();

        if (!$brand) {
            return response()->json(['success' => false, 'error' => 'Brand profile not found'], 404);
        }

        // Ensure the user is a brand
        if ($user->role !== 'brand') {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        // Find the collaboration request
        $collaborationRequest = CollaborationRequest::find($collaborationRequestId);

        if (!$collaborationRequest) {
            return response()->json(['success' => false, 'error' => 'Collaboration request not found'], 404);
        }

        // Fetch the associated collaboration
        $collaboration = Collaboration::find($collaborationRequest->collaboration_id);

        if (!$collaboration) {
            return response()->json(['success' => false, 'error' => 'Collaboration not found'], 404);
        }

        // Ensure the collaboration was created by the authenticated brand
        if ($collaboration->brand_id !== $brand->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized: You are not the owner of this collaboration'], 403);
        }

        // Ensure the collaboration request was accepted before completing it
        if ($collaborationRequest->status !== 4) { // 4 = accepted
            return response()->json([
                'success' => false,
                'error' => 'Only accepted collaboration requests can be marked as completed'
            ], 403);
        }

        // Check if the collaboration request is already completed
        if ($collaborationRequest->status === 5) { // 5 = completed
            return response()->json([
                'success' => false,
                'error' => 'This collaboration request is already marked as completed.'
            ], 400);
        }

        // Update the status to 'completed' (status 5)
        $collaborationRequest->update(['status' => 5]);

        // Update the status of the collaboration itself
        $collaboration->update(['status' => '5']);

        $notification = Notification::where('user_id', $brand->user_id)
        // ->where('type', 'collaboration_interest')
        ->where('data', 'like', '%"collaboration_request_id":' . $collaborationRequestId . '%')
        ->first();

        if ($notification) {
            // Decode existing notification data
            $notificationData = json_decode($notification->data, true);

            // Update the status field in the notification data
            $notificationData['collaboration_request_status'] = 5;

            // Save the updated notification data
            $notification->update([
                'data' => json_encode($notificationData),
            ]);
        }

        $notificationService = new NotificationService();
        $notificationService->createNotification(
            $collaborationRequest->influencer->user_id,
            'collaboration_completed', // Notification type
            [
                'message' => strtoupper($brand->name) . " has recognized that you have successfully completed the collaboration " . strtoupper($collaboration->name) . ".",
                'collaboration_id' => $collaboration->id,
                'collaboration_request_id' => $collaborationRequest->id,
                'collaboration_image' => $collaboration->image,
                'brand_id' => $collaboration->brand->id,
            ]
        );

        // Prepare push notification data for the influencer
        $pushNotificationData = [
            'title' => "Collaboration Completed",
            'body' => strtoupper($brand->name) . " has recognized that you have successfully completed the collaboration " . strtoupper($collaboration->name) . ".",
            'image' => $collaboration->image,
            'data' => [
                'collaboration_id' => $collaboration->id,
                'collaboration_request_id' => $collaborationRequest->id,
                'influencer_id' => $collaborationRequest->influencer->user_id,
                'status' => 5, // Completed status
                'message' => strtoupper("{$brand->name} has recognized that you have successfully completed the collaboration {$collaboration->name}."),
            ]
        ];

        // Send push notification to the influencer
        $fcmTokens = $collaborationRequest->influencer->user->fcmTokens->pluck('fcm_token')->toArray();
        $pushNotificationService->sendPushNotification($fcmTokens, $pushNotificationData);

        return response()->json(['success' => true, 'message' => 'Collaboration request completed.']);
    }

    //Explore
    public function getAllCollaborations(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Get the offset and limit from the request, defaulting to 0 and 10 respectively
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);

        // Fetch collaborations with status 'interested', 'accepted', or 'completed'
        $excludedStatuses = [2, 4, 5];
        $excludedCollaborationIds = CollaborationRequest::where('influencer_id', $user->influencer->id)
            ->whereIn('status', $excludedStatuses)
            ->pluck('collaboration_id')
            ->toArray();

        // Step 2: Get categories of collaborations the user is interested in
        $interestedCategories = Collaboration::whereIn('id', $excludedCollaborationIds)
            ->pluck('category')
            ->toArray();

        // Flatten and remove duplicate categories
        $interestedCategories = array_unique(array_merge(...array_map('json_decode', $interestedCategories)));

        // Step 3: Fetch collaborations from interested categories, excluding already excluded collaborations
        $collaborationsFromInterestedCategories = Collaboration::where(function ($query) use ($interestedCategories) {
            foreach ($interestedCategories as $categoryId) {
                $query->orWhereRaw('JSON_CONTAINS(category, ?)', [json_encode($categoryId)]);
            }
        })
            ->where('status', '!=', 7) // Exclude closed collaborations
            ->whereNotIn('id', $excludedCollaborationIds) // Exclude interested, accepted, and completed collaborations
            ->where(function ($query) {
                $query->whereNull('end_date') // Include collaborations with no end_date
                      ->orWhere('end_date', '>=', now()); // Include only active collaborations
            })
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->take($limit)
            ->get();

        // Step 4: Fetch additional collaborations if needed to fill the limit
        $totalFetched = $collaborationsFromInterestedCategories->count();
        $remainingLimit = $limit - $totalFetched;

        $otherCollaborations = [];
        if ($remainingLimit > 0) {
            $otherCollaborations = Collaboration::whereNotIn('id', $excludedCollaborationIds) // Exclude interested, accepted, and completed collaborations
                ->where('status', '!=', 7) // Exclude closed collaborations
                ->where(function ($query) {
                    $query->whereNull('end_date') // Include collaborations with no end_date
                          ->orWhere('end_date', '>=', now()); // Include only active collaborations
                })
                ->whereNotIn('id', $collaborationsFromInterestedCategories->pluck('id'))
                ->orderBy('created_at', 'desc')
                ->skip($offset + $totalFetched)
                ->take($remainingLimit)
                ->get();
        }

        // Combine both collections
        $collaborations = $collaborationsFromInterestedCategories->merge($otherCollaborations);

        // Check if there are more collaborations
        $totalCollaborations = Collaboration::whereNotIn('id', $excludedCollaborationIds)
            ->where('status', '!=', 7) // Exclude closed collaborations
            ->count();
        $hasMore = ($offset + $limit) < $totalCollaborations;

        return response()->json([
            'success' => true,
            'collaborations' => $collaborations,
            'has_more' => $hasMore,
            'next_offset' => $offset + $limit
        ]);
    }

    // Get collaboration details
    public function getCollaborationDetails($collaborationId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Find the collaboration by ID
        $collaboration = Collaboration::find($collaborationId);

        // Check if the collaboration exists
        if (!$collaboration) {
            return response()->json(['error' => 'Collaboration not found'], 404);
        }

        // Get the current date and time
        $currentDate = now();
        $endDate = $collaboration->end_date;

        // Determine if the end_date has passed
        $hasEnded = $endDate && $currentDate->greaterThanOrEqualTo($endDate);

        // Check if the authenticated user is an influencer and has expressed interest
        $hasExpressedInterest = false;
        if ($user->role === 'influencer') {
            $hasExpressedInterest = $collaboration->collaborationRequests()
                ->where('influencer_id', $user->influencer->id)
                ->where('status', 2)
                ->exists();
        }

        // Check if there are any accepted influencers
        $hasCompletedInfluencers = $collaboration->collaborationRequests()
        ->where('status', 5) // Assuming status 5 means "completed"
        ->exists(); // Check if any accepted influencers exist

        $brand = $collaboration->brand;

        // Return the collaboration details with the 'has_ended' flag and 'has_expressed_interest'
        return response()->json([
            'success' => true,
            'collaboration' => $collaboration->toArray() + [
                // 'brand_name' => $brand->name ?? '',
                'has_ended' => $hasEnded,
                'has_expressed_interest' => $hasExpressedInterest,
                'completed_influencers' => $hasCompletedInfluencers,
            ]
        ]);
    }

    public function closeCollaboration($collaborationId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'brand') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Find the collaboration by ID
        $collaboration = Collaboration::find($collaborationId);

        // If collaboration not found, return an error message
        if (!$collaboration) {
            return response()->json(['message' => 'Collaboration not found'], 404);
        }

        if($collaboration->brand->id !== $user->brand->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if($collaboration->status === 7) {
            return response()->json(['message' => 'Collaboration already closed'], 400);
        }

        // Check if there are any accepted influencers
        $hasAcceptedInfluencers = $collaboration->collaborationRequests()
            ->where('status', 5) // Assuming status 5 means "completed"
            ->exists();

        if (!$hasAcceptedInfluencers) {
            return response()->json([
                'message' => 'Collaboration cannot be closed because no influencers have been accepted.'
            ], 400);
        }

        // Update the status to 7 (closed)
        $collaboration->status = 7;
        $collaboration->save();

        // Return a success message
        return response()->json(['success' => true,'message' => 'Collaboration closed successfully']);
    }

}
