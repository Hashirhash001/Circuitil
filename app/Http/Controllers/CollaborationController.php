<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Brand;
use App\Models\Influencer;
use Illuminate\Http\Request;
use App\Models\Collaboration;
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
            'category' => 'required|string',
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

        // Create the collaboration
        $collaboration = Collaboration::create($data);

        // Send collaboration request to influencers
        $influencers = Influencer::where('category', $data['category'])
            ->where('collab_value', '<=', $data['amount'])
            ->get();

        foreach ($influencers as $influencer) {
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
            ->where('status', 2) // 2 = interested
            ->get();

        if ($interestedInfluencers->isEmpty()) {
            return response()->json(['success' => true, 'message' => 'No interested influencers found']);
        }

        return response()->json(['success' => true, 'influencers' => $interestedInfluencers]);
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
            'status' => 'required|in:3,4', // 3 = accepted or 4 = rejected
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
        $existingAcceptedRequest = CollaborationRequest::where('collaboration_id', $collaboration->id)
            ->where('status', 3) // Status 3 means accepted
            ->first();

        if ($existingAcceptedRequest && $existingAcceptedRequest->id !== $collaborationRequestId) {
            return response()->json(['success' => false, 'error' => 'This collaboration already has an accepted influencer.'], 400);
        }

        // Check if the influencer has shown interest (status = 2)
        if ($collaborationRequest->status !== 2) {
            return response()->json(['success' => false, 'error' => 'Only requests with interested influencers can be accepted or rejected'], 403);
        }

        // Update the status of the collaboration request
        $collaborationRequest->update($data);

        // Return a response based on the new status
        $message = $data['status'] == 3 ? 'Collaboration request accepted.' : 'Collaboration request rejected.';

        return response()->json(['success' => true, 'message' => $message]);
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

        // Delete the collaboration
        $collaboration->delete();

        return response()->json(['success' => true, 'message' => 'Collaboration deleted successfully']);
    }

}
