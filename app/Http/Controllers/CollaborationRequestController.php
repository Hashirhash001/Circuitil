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

class CollaborationRequestController extends Controller
{
    // Invite an influencer for a collaboration
    public function inviteInfluencerForCollaboration(Request $request)
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
            return response()->json(['error' => 'Influencer has already been invited or responded'], 400);
        }

        // Create a new collaboration request with status 'invited' (3)
        $newCollaborationRequest = new CollaborationRequest();
        $newCollaborationRequest->collaboration_id = $request->collaboration_id;
        $newCollaborationRequest->influencer_id = $request->influencer_id;
        $newCollaborationRequest->status = 3; // 3 = Invited
        $newCollaborationRequest->save();

        return response()->json(['success' => true, 'message' => 'Influencer invited successfully']);
    }

    // Mark a collaboration as interested
    public function markInterestedForCollaboration(Request $request)
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

        DB::transaction(function () use ($request, $influencer) {
            $newCollaborationRequest = new CollaborationRequest();
            $newCollaborationRequest->collaboration_id = $request->collaboration_id;
            $newCollaborationRequest->influencer_id = $influencer->id;
            $newCollaborationRequest->status = 2; // Interested
            $newCollaborationRequest->save();
        });

        return response()->json(['success' => true, 'message' => 'Collaboration marked as interested']);
    }

}
