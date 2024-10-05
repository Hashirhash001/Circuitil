<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\User;
use App\Models\Brand;
use App\Models\Influencer;
use Illuminate\Http\Request;
use App\Models\ChatParticipant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class InfluencerController extends Controller
{
    /**
     * Display my influencer profile.
     */
    public function index()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        $influencer = Influencer::where('user_id', $user->id)->first();

        if (!$influencer) {
            return response()->json(['error' => 'Influencer not found'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Influencer details fetched successfully',
            'influencer' => $influencer,
        ]);
    }

    // influencer profile show
    public function show($influencerId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user(); // Get the logged-in user

        // Fetch the influencer profile
        $influencer = Influencer::where('id', $influencerId)->first();

        if (!$influencer) {
            return response()->json(['error' => 'Influencer not found'], 404);
        }

        // Check if there's an existing chat between the logged-in user and the influencer
        $chat = Chat::whereHas('participants', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->whereHas('participants', function ($query) use ($influencer) {
                $query->where('user_id', $influencer->user_id); // Assuming 'user_id' is the foreign key in the influencer table
            })
            ->first();

        // If no chat exists, create a new one
        if (!$chat) {
            // Create a new chat
            $chat = Chat::create(['created_by' => $user->id]);

            // Add both the authenticated user and the influencer as participants
            ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $user->id]);
            ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $influencer->user_id]);
        }

        // Return the chat ID in the response, whether it existed or was newly created
        $chatId = $chat->id;

        return response()->json([
            'success' => true,
            'message' => 'Influencer details fetched successfully',
            'influencer' => $influencer,
            'chat_id' => $chatId, // Include the chat_id in the response
        ]);
    }

    /**
     * Update influencer profile
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|array|max:4',
            'category.*' => 'required|string|max:255',
            'about' => 'nullable|string',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'social_media_links' => 'nullable|json',
            'collab_value' => 'required|numeric',
        ]);

        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Check if the user has the 'brand' role
        if ($user->role !== 'influencer') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Find the influencer associated with the user
        $influencer = Influencer::where('user_id', $user->id)->firstOrFail();

        // Handle profile photo upload
        if ($request->hasFile('profile_photo')) {
            // Store the file in the 'public/profile_photos' directory
            $path = $request->file('profile_photo')->store('profile_photos', 'public');

            // Delete old profile photo if it exists
            if ($influencer->profile_photo) {
                Storage::disk('public')->delete($influencer->profile_photo);
            }

            // Add the file path to the validated data
            $data = array_merge($validator->validated(), ['profile_photo' => $path]);
        } else {
            $data = $validator->validated();
        }

        // Store categories as an array in the 'categories' field
        $data['category'] = json_encode($data['category']);

        // Update influencer details
        $influencer->update($data);

        // Set the 'profile_updated' field to true
        User::where('id', $user->id)->update(['profile_updated' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Influencer details updated successfully',
            'influencer' => $influencer,
        ]);
    }

    public function getTrendingInfluencers()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Get the date for one month ago
        $oneMonthAgo = now()->subMonth();

        // Fetch influencers with the count of their collaboration requests where status = 3 and created within the past month
        $trendingInfluencers = Influencer::whereNotNull('profile_photo') // Only fetch influencers with a profile photo
            ->withCount(['collaborationRequests as collaboration_count' => function ($query) use ($oneMonthAgo) {
                $query->where('status', 3) // Only count collaboration requests where status = 3
                    ->where('created_at', '>=', $oneMonthAgo); // Only count requests from the past month
            }])
            ->orderBy('collaboration_count', 'desc') // Order by the counted collaborations
            ->take(10) // Limit to top 10 trending influencers
            ->get();

        if ($trendingInfluencers->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No trending influencers found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'trending_influencers' => $trendingInfluencers,
        ]);
    }

    public function getTopCollaboratedInfluencers()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Fetch influencers with the count of their collaboration requests where status = 3
        $topCollaboratedInfluencers = Influencer::whereNotNull('profile_photo')
            ->withCount(['collaborationRequests as collaboration_count' => function ($query) {
            $query->where('status', 3); // Only count collaboration requests where status = 3
        }])
            ->orderBy('collaboration_count', 'desc') // Order by the counted collaborations
            ->take(10) // Limit to top 10 top collaborated influencers
            ->get();

        if ($topCollaboratedInfluencers->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No influencers found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'Top_Collaborated_Influencers' => $topCollaboratedInfluencers,
        ]);
    }
}
