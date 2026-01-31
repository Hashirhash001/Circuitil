<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\Brand;
use App\Models\Influencer;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\Collaboration;
use Illuminate\Support\Facades\DB;
use App\Models\CollaborationRequest;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Validator;

class CollaborationRequestController extends Controller
{
    // Invite an influencer for a collaboration
    public function inviteInfluencerForCollaboration(Request $request, PushNotificationService $pushNotificationService)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'brand') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Use the Validator class for validation
        $validator = Validator::make($request->all(), [
            'collaboration_id' => 'required|exists:collaborations,id',
            'influencer_id' => 'required|exists:influencers,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Fetch the brand based on the authenticated user
        $brand = Brand::where('user_id', $user->id)->first();

        if (!$brand) {
            return response()->json(['error' => 'Brand not found'], 404);
        }

        // Fetch the collaboration and check if it belongs to the brand
        $collaboration = Collaboration::where('id', $request->collaboration_id)
            ->where('brand_id', $brand->id)
            ->first();

        if (!$collaboration) {
            return response()->json(['error' => 'Collaboration not found or does not belong to this brand'], 404);
        }

        // Check if the collaboration has ended
        if ($collaboration->end_date && Carbon::createFromFormat('d-m-Y', $collaboration->end_date)->lt(now())) {
            return response()->json(['error' => 'Collaboration has ended and cannot invite influencers'], 400);
        }

        // Check if this influencer has already been invited
        $collaborationRequest = CollaborationRequest::where('collaboration_id', $request->collaboration_id)
            ->where('influencer_id', $request->influencer_id)
            ->where('status', '3')
            ->first();

        if ($collaborationRequest) {
            return response()->json(['error' => 'Influencer has already been invited'], 400);
        }

        // Create a new collaboration request with status 'pending' (1)
        $newCollaborationRequest = new CollaborationRequest();
        $newCollaborationRequest->collaboration_id = $request->collaboration_id;
        $newCollaborationRequest->influencer_id = $request->influencer_id;
        $newCollaborationRequest->status = 3; // 3 = invited
        $newCollaborationRequest->save();

        // Notify the influencer about the invitation
        $notificationService = new NotificationService();
        $influencer = Influencer::find($request->influencer_id);
        $notificationService->createNotification(
            $influencer->user_id, // The influencer's user ID
            'collaboration_invitation', // Notification type
            [
                'message' => strtoupper($brand->name) . " has invited you to collaborate on " . strtoupper($collaboration->name) . ".",
                'collaboration_id' => $collaboration->id,
                'collaboration_request_id' => $newCollaborationRequest->id,
                'collaboration_request_status' => $newCollaborationRequest->status,
                'collaboration_image' => $collaboration->image,
                'brand_id' => $collaboration->brand->id,
            ]
        );

        // Prepare push notification data
        $pushNotificationData = [
            'title' => "Collaboration Invitation",
            'body' => strtoupper($brand->name) . " has invited you to collaborate on ". strtoupper($collaboration->name),
            'image' => $collaboration->image, // Assuming image is the collaboration image
            'data' => [
                'collaboration_id' => $collaboration->id,
                'collaboration_request_id' => $newCollaborationRequest->id,
                'influencer_id' => $influencer->id,
                'status' => $newCollaborationRequest->status,
                'message' => "{$brand->name} has invited you to collaborate on '{$collaboration->name}'.",
            ]
        ];

        $fcmTokens = $influencer->user->fcmTokens->pluck('fcm_token')->toArray();
        $pushNotificationService->sendPushNotification($fcmTokens, $pushNotificationData);

        return response()->json(['success' => true, 'message' => 'Influencer invited successfully']);
    }

    // Accept a collaboration invitation
    public function acceptCollaborationInvitation($collaborationRequestId, PushNotificationService $pushNotificationService)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'influencer') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Fetch the influencer based on the authenticated user
        $influencer = Influencer::where('user_id', $user->id)->first();

        if (!$influencer) {
            return response()->json(['error' => 'Influencer not found'], 404);
        }

        // Fetch the collaboration request and check if it belongs to the influencer
        $collaborationRequest = CollaborationRequest::where('id', $collaborationRequestId)
            ->where('influencer_id', $influencer->id)
            ->where('status', 3) // Ensure it's in "invited" status
            ->first();

        if (!$collaborationRequest) {
            return response()->json(['error' => 'Collaboration request not found or not valid for acceptance'], 404);
        }

        // Check if the collaboration request has already been accepted
        if ($collaborationRequest->status === 4) { // 4 = accepted
            return response()->json(['error' => 'Collaboration request has already been accepted'], 400);
        }

        // Update the status of the collaboration request to "accepted" (1)
        $collaborationRequest->status = 4; // 4 = accepted
        $collaborationRequest->save();

        $notification = Notification::where('user_id', $user->id)
        ->where('data', 'like', '%"collaboration_request_id":' . $collaborationRequestId . '%')
        ->first();

        if ($notification) {
            // Decode existing notification data
            $notificationData = json_decode($notification->data, true);

            // Update the status field in the notification data
            $notificationData['collaboration_request_status'] = 4;

            // Save the updated notification data
            $notification->update([
                'data' => json_encode($notificationData),
            ]);
        }

        $collaboration = $collaborationRequest->collaboration;
        $brand = $collaboration->brand;

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

        // Notify the brand about the acceptance
        $notificationService = new NotificationService();
        $notificationService->createNotification(
            $brand->user_id, // The brand's user ID
            'collaboration_acceptance', // Notification type
            [
                'message' => "{$influencer->name} has accepted your invitation to collaborate on {$collaboration->name}.",
                'collaboration_id' => $collaboration->id,
                'collaboration_request_id' => $collaborationRequest->id,
                'collaboration_request_status' => $collaborationRequest->status,
                'collaboration_image' => $collaboration->image,
                'user_profile_photo' => $influencer->profile_photo,
                'influencer_id' => $influencer->id,
            ]
        );

        // Prepare push notification data for the brand
        $pushNotificationData = [
            'title' => "Collaboration Acceptance",
            'body' => ($influencer->name) . " has accepted your invitation to collaborate on " . strtoupper($collaboration->name),
            'image' => $collaboration->image, // Assuming the image is the collaboration image
            'data' => [
                'collaboration_id' => $collaboration->id,
                'collaboration_request_id' => $collaborationRequest->id,
                'influencer_id' => $influencer->id,
                'status' => 4, // Accepted status
                'message' => "{$influencer->name} has accepted your invitation.",
            ]
        ];

        // Send push notification to the brand
        $fcmTokens = $brand->user->fcmTokens->pluck('fcm_token')->toArray();
        $pushNotificationService->sendPushNotification($fcmTokens, $pushNotificationData);

        return response()->json(['success' => true, 'message' => 'Collaboration invitation accepted successfully']);
    }

    // Mark a collaboration as interested
    public function markInterestedForCollaboration(Request $request, PushNotificationService $pushNotificationService)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'influencer') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'collaboration_id' => 'required|integer|exists:collaborations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $influencer = Influencer::where('user_id', $user->id)->first();

        if (!$influencer) {
            return response()->json(['success' => false, 'message' => 'Influencer not found'], 404);
        }

        $collaboration = Collaboration::find($request->collaboration_id);

        if ($collaboration->end_date && Carbon::hasFormat($collaboration->end_date, 'd-m-Y') &&
            Carbon::createFromFormat('d-m-Y', $collaboration->end_date)->lt(now())) {
            return response()->json(['success' => false, 'message' => 'Collaboration has ended'], 400);
        }

        $alreadyInterested = CollaborationRequest::where([
            ['collaboration_id', $request->collaboration_id],
            ['influencer_id', $influencer->id],
            ['status', '2']
        ])->exists();

        if ($alreadyInterested) {
            return response()->json(['success' => false, 'message' => 'Already marked as interested'], 400);
        }

        DB::transaction(function () use ($request, $influencer, $collaboration, $pushNotificationService) {
            $newCollaborationRequest = new CollaborationRequest();
            $newCollaborationRequest->collaboration_id = $request->collaboration_id;
            $newCollaborationRequest->influencer_id = $influencer->id;
            $newCollaborationRequest->status = 2; // Interested
            $newCollaborationRequest->save();

            // Notify the brand about the influencer's interest
            $notificationService = new NotificationService();
            $brand = Brand::find($collaboration->brand_id);
            $notificationService->createNotification(
                $brand->user_id, // The brand's user ID
                'collaboration_interest', // Notification type
                [
                    'message' => "{$influencer->name} has expressed interest in your collaboration {$collaboration->name}.",
                    'collaboration_id' => $collaboration->id,
                    'collaboration_request_id' => $newCollaborationRequest->id,
                    'collaboration_request_status' => $newCollaborationRequest->status,
                    'collaboration_image' => $collaboration->image,
                    'brand_id' => $collaboration->brand->id,
                ]
            );

            // Prepare push notification data
            $pushNotificationData = [
                'title' => "Collaboration Interest",
                'body' => strtoupper($influencer->name) . " has expressed interest in your collaboration " . strtoupper($collaboration->name),
                'image' => $collaboration->image, // Assuming image is the collaboration image
                'data' => [
                    'collaboration_id' => $collaboration->id,
                    'collaboration_request_id' => $newCollaborationRequest->id,
                    'influencer_id' => $influencer->id,
                    'status' => $newCollaborationRequest->status,
                    'message' => "{$influencer->name} has expressed interest for your collaboration '{$collaboration->name}'.",
                ]
            ];

            $fcmTokens = $collaboration->brand->user->fcmTokens->pluck('fcm_token')->toArray();
            $pushNotificationService->sendPushNotification($fcmTokens, $pushNotificationData);
        });

        return response()->json(['success' => true, 'message' => 'Collaboration marked as interested']);
    }

}
