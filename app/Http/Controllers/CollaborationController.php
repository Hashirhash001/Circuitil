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
use Illuminate\Support\Facades\DB;
use App\Models\CollaborationRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CollaborationController extends Controller
{
    // Create a new collaboration
    public function createCollaboration(Request $request)
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
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
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
                    CollaborationRequest::create([
                        'collaboration_id' => $collaboration->id,
                        'influencer_id' => $influencer->id,
                        'status' => 1, // pending
                    ]);
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
        $requests = CollaborationRequest::with('collaboration.brand') // Eager load brand relationship
            ->where('influencer_id', $influencer->id)
            ->where('status', 1)
            ->orWhere('status', 3)
            ->orWhere('status', 4)
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
    public function updateCollaborationStatus(Request $request, $collaborationRequestId)
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
            'status' => 'required|in:2,4', // 2 = interested, 4 = rejected
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

        // Return response based on whether the update was successful
        if ($updateSuccess) {
            $statusMessages = [
                2 => 'Collaboration request marked as interested.',
                4 => 'Collaboration request marked as rejected.',
            ];

            return response()->json([
                'success' => true,
                'message' => $statusMessages[$request->status] ?? 'Status updated successfully'
            ]);
        } else {
            return response()->json(['error' => 'Failed to update collaboration request status'], 500);
        }

        return response()->json(['success' => true]);
    }

    // Fetch interested influencers for a collaboration by a brand
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
                    ->orWhere('status', 4); // 4 = accepted
            })
            ->orderBy('created_at', 'desc')
            ->get();

        if ($interestedInfluencers->isEmpty()) {
            return response()->json(['success' => true, 'message' => 'No interested or accepted influencers found']);
        }

        return response()->json(['success' => true, 'influencers' => $interestedInfluencers]);
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

    // Accept an influencer for a collaboration
    public function acceptInfluencer(Request $request, $collaborationRequestId)
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

        // Initiate a chat if accepted
        // if ($data['status'] == 3) {
        //     // Check if a chat already exists between the brand and influencer
        //     $influencerId = $collaborationRequest->influencer->user_id; // Assuming this exists in your relation
        //     $existingChat = Chat::whereHas('participants', function ($query) use ($brand, $influencerId) {
        //         $query->where('user_id', $brand->user_id)
        //             ->orWhere('user_id', $influencerId);
        //     })
        //     ->has('participants', 2) // Ensures both participants (brand and influencer) exist in the chat
        //     ->first();

        //     if (!$existingChat) {
        //         // Create a new chat
        //         $chat = Chat::create([
        //             'created_by' => $user->id,
        //         ]);

        //         // Add brand and influencer as participants
        //         ChatParticipant::create([
        //             'chat_id' => $chat->id,
        //             'user_id' => $brand->user_id, // Brand
        //         ]);

        //         ChatParticipant::create([
        //             'chat_id' => $chat->id,
        //             'user_id' => $influencerId, // Influencer
        //         ]);
        //     }
        // }

        // Return a response based on the new status
        $message = $data['status'] == 4 ? 'Collaboration request accepted.' : 'Collaboration request rejected.';

        return response()->json(['success' => true, 'message' => $message]);
    }

    // Delete a collaboration
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

        // Determine if the user is a brand or an influencer
        if ($user->role === 'brand') {
            // For brands, fetch all collaborations
            $collaborations = $user->brand->collaborations;
        } elseif ($user->role === 'influencer') {
            // Get the influencer's ID only if the user is an influencer
            $influencerId = $user->influencer->id;

            // For influencers, only fetch collaborations that have been accepted by the brand and have not ended
            $collaborations = Collaboration::whereHas('collaborationRequests', function ($query) use ($influencerId) {
                $query->where('influencer_id', $influencerId)
                    ->where('status', 4)
                    ->orderBy('created_at', 'desc');
            })
                // ->where(function ($query) {
                //     $query->whereNull('end_date')
                //         ->orWhere('end_date', '>=', now());
                // })
                ->get();
        } else {
            return response()->json(['error' => 'Invalid user role'], 403);
        }

        // Map through collaborations and add status and influencer details (if needed)
        $collaborationsWithDetails = $collaborations->map(function ($collaboration) {
            $currentDate = now();
            $endDate = $collaboration->end_date;
            $hasEnded = $endDate && $currentDate->greaterThanOrEqualTo($endDate);

            // If the user is an influencer, add influencer details
            $acceptedRequest = $collaboration->collaborationRequests()
                ->where('status', 4)
                ->with('influencer')
                ->first();

            $influencer = $acceptedRequest ? $acceptedRequest->influencer : null;

            return $collaboration->toArray() + [
                'has_ended' => $hasEnded,
                'influencer' => $influencer ? [
                    'id' => $influencer->id,
                    'name' => $influencer->name,
                    'category' => $influencer->category,
                    'about' => $influencer->about,
                    'profile_photo' => $influencer->profile_photo,
                    'collab_value' => $influencer->collab_value,
                    'social_media_links' => json_decode($influencer->social_media_links)
                ] : null
            ];
        });

        return response()->json([
            'success' => true,
            'collaborations' => $collaborationsWithDetails
        ]);
    }

    // Complete a collaboration
    public function completeCollaboration($collaborationRequestId)
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

        return response()->json(['success' => true, 'message' => 'Collaboration request completed.']);
    }

}
