<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Chat;
use App\Models\Brand;
use App\Models\Post;
use Illuminate\Http\Request;
use App\Models\Collaboration;
use App\Models\ChatParticipant;
use App\Models\CollaborationRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BrandController extends Controller
{
    /**
     * Display my brand profile.
     */
    public function index()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Find the brand associated with the user
        $brand = Brand::where('user_id', $user->id)->first();

        if (!$brand) {
            return response()->json(['error' => 'brand not found'], 404);
        }

        // Fetch total posts count for the brand
        $totalPosts = Post::where('user_id', $brand->user_id)->count();

        // Fetch total completed collaborations for a brand
        $totalCollaborations = Collaboration::where('brand_id', $brand->id)
            ->whereHas('collaborationRequests', function ($query) {
                $query->where('status', 7);
            })
            ->distinct()
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'brand details fetched successfully',
            'brand' => $brand,
            'total_posts' => $totalPosts,
            'total_collaborations' => $totalCollaborations,
        ]);
    }

    // brand profile show
    public function show($brandId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user(); // Get the logged-in user

        // Fetch the brand profile
        $brand = Brand::where('id', $brandId)->first();

        if (!$brand) {
            return response()->json(['error' => 'Brand not found'], 404);
        }

        // Fetch total posts count for the brand
        $totalPosts = Post::where('user_id', $brand->user_id)->count();

        // Fetch total completed collaborations for a brand
        $totalCollaborations = Collaboration::where('brand_id', $brand->id)
            ->whereHas('collaborationRequests', function ($query) {
                $query->where('status', 7);
            })
            ->distinct()
            ->count();

        // Check if there's an existing chat between the logged-in user and the brand's user
        $chat = Chat::whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->whereHas('participants', function ($query) use ($brand) {
                $query->where('user_id', $brand->user_id); // Assuming 'user_id' is the foreign key in the brand table
            })
            ->first();

        // If no chat exists, create a new one
        // if (!$chat) {
        //     // Create a new chat
        //     $chat = Chat::create(['created_by' => $user->id]);

        //     // Add both the authenticated user and the brand's user as participants
        //     ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $user->id]);
        //     ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $brand->user_id]);
        // }

        // Return the chat ID in the response, whether it existed or was newly created
        $chatId = $chat->id ?? null;

        return response()->json([
            'success' => true,
            'message' => 'Brand details fetched successfully',
            'brand' => $brand,
            'chat_id' => $chatId,
            'total_posts' => $totalPosts,
            'total_collaborations' => $totalCollaborations,
        ]);
    }

    /**
     * Update brand profile
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'category' => 'required|array|max:4',
            'category.*' => 'required|integer|exists:categories,id',
            'about' => 'nullable|string',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'social_media_links' => 'nullable|json',
        ]);

        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Check if the user has the 'brand' role
        if ($user->role !== 'brand') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Find the brand associated with the user
        $brand = Brand::where('user_id', $user->id)->first();

        if (!$brand) {
            return response()->json([
                'error' => 'brand profile not found for the authenticated user.',
            ], 404);
        }

        // Handle profile photo upload
        if ($request->hasFile('profile_photo')) {
            // Store the file in the 'public/profile_photos' directory
            $path = $request->file('profile_photo')->store('profile_photos', 'public');

            // Delete old profile photo if it exists
            if ($brand->profile_photo) {
                Storage::disk('public')->delete($brand->profile_photo);
            }

            // Add the file path to the validated data
            $data = array_merge($validator->validated(), ['profile_photo' => $path]);
        } else {
            $data = $validator->validated();
        }

        // Ensure that the categories are properly encoded as JSON (as an array, not as a single string)
        $data['category'] = json_encode(array_unique($data['category']));

        // Update brand details
        $brand->update($data);

        // Set the 'profile_updated' field to true
        User::where('id', $user->id)->update(['profile_updated' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Brand details updated successfully',
            'brand' => $brand,
        ]);
    }

    /**
     * Fetch all collaborations for a brand.
     */
    public function getCollaborations()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Check if the user has the 'brand' role
        if ($user->role !== 'brand') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Find the brand associated with the authenticated user
        $brand = Brand::where('user_id', $user->id)->first();

        // Check if the authenticated user is a brand owner
        if (!$brand) {
            return response()->json(['error' => 'Unauthorized: User is not a brand'], 403);
        }

        // Fetch all collaborations for the brand
        $collaborations = $brand->collaborations;

        // Map through each collaboration to add the flag and accepted influencer's details
        $collaborationsWithStatus = $collaborations->map(function ($collaboration) {
            $currentDate = now(); // Get the current date and time
            $endDate = $collaboration->end_date;

            // Determine if the end_date has passed
            $hasEnded = $endDate && $currentDate->greaterThanOrEqualTo($endDate);

            // Get the accepted collaboration request
            $acceptedRequest = $collaboration->collaborationRequests()
                ->where('status', 3) // Assuming '3' means accepted
                ->with('influencer') // Eager load the influencer relationship
                ->first();

            // Set the influencer details if there is an accepted request
            $influencer = $acceptedRequest ? $acceptedRequest->influencer : null;
            $hasAcceptedInfluencer = $acceptedRequest !== null;

            // Return all attributes including the 'has_ended' flag and accepted influencer
            return $collaboration->toArray() + [
                'has_ended' => $hasEnded,
                'has_accepted_influencer' => $hasAcceptedInfluencer,
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
            'collaborations' => $collaborationsWithStatus
        ]);
    }

    public function getCollaborationsWithInvitedFlag($influencerId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Check if the user has the 'brand' role
        if ($user->role !== 'brand') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Find the brand associated with the authenticated user
        $brand = Brand::where('user_id', $user->id)->first();

        // Check if the authenticated user is a brand owner
        if (!$brand) {
            return response()->json(['error' => 'Unauthorized: User is not a brand'], 403);
        }

        // Fetch collaborations excluding ended ones and closed ones (status = 7)
        $collaborations = $brand->collaborations()
            ->where('status', '!=', 7) // Exclude closed collaborations
            ->where(function ($query) {
                $query->whereNull('end_date') // Include collaborations with no end_date
                      ->orWhereRaw("STR_TO_DATE(end_date, '%d-%m-%Y') > ?", [now()]); // Compare formatted dates
            })
            ->get();

        // Map through each collaboration to add the 'has_invited_profile' flag
        $collaborationsWithStatus = $collaborations->map(function ($collaboration) use ($influencerId) {
            // Check if the given profile ID has already been invited for this collaboration (status = 3)
            $hasInvitedProfile = $collaboration->collaborationRequests()
                ->where('influencer_id', $influencerId)
                ->where('status', 3) // Status 3 means "invited"
                ->exists();

            // Return all attributes including the 'has_invited_profile' flag
            return $collaboration->toArray() + [
                'has_invited_profile' => $hasInvitedProfile
            ];
        });

        return response()->json([
            'success' => true,
            'collaborations' => $collaborationsWithStatus
        ]);
    }

    public function fetchInvitedInfluencers()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'brand') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $brandId = $user->brand->id;

        $brand = Brand::find($brandId);

        if (!$brand) {
            return response()->json([
                'success' => false,
                'message' => 'Brand not found',
            ], 404);
        }

        // Fetch influencers invited by the brand
        $invitedInfluencers = CollaborationRequest::with('influencer', 'collaboration')
            ->whereHas('collaboration', function ($query) use ($brandId) {
                $query->where('brand_id', $brandId);
            })
            ->where('status', 3) // 3 = invited
            ->orderBy('created_at', 'desc')
            ->get();

        if ($invitedInfluencers->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No invited influencers found',
            ]);
        }

        // Prepare response data
        $data = $invitedInfluencers->map(function ($request) {
            return [
                'collaboration_request_id' => $request->id,
                'collaboration_request_status' => $request->status,
                'collaboration_id' => $request->collaboration->id,
                'collaboration_name' => $request->collaboration->name,
                'collaboration_image' => $request->collaboration->image,
                'influencer_id' => $request->influencer->id,
                'influencer_name' => $request->influencer->name,
                'influencer_profile_photo' => $request->influencer->profile_photo,
                'influencer_user_id' => $request->influencer->user->id,
                'expressed_interest_at' => $request->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'success' => true,
            'influencers' => $data,
        ]);
    }


}
