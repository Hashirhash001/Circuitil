<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Post;
use App\Models\User;
use App\Models\Brand;
use App\Models\BlockUser;
use App\Models\Influencer;
use Illuminate\Http\Request;
use App\Models\Collaboration;
use App\Models\ChatParticipant;
use App\Models\CollaborationRequest;
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

        // Fetch total posts count for the influencer
        $totalPosts = Post::where('user_id', $user->id)->count();

        // Fetch total completed collaborations count (status = 5 means completed)
        $totalCollaborations = CollaborationRequest::where('influencer_id', $influencer->id)
            ->where('status', 5)  // Completed collaborations
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Influencer details fetched successfully',
            'influencer' => $influencer,
            'total_posts' => $totalPosts,
            'total_collaborations' => $totalCollaborations,
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

        // Fetch total posts count for the influencer
        $totalPosts = Post::where('user_id', $user->id)->count();

        // Fetch total completed collaborations count (status = 5 means completed)
        $totalCollaborations = CollaborationRequest::where('influencer_id', $influencer->id)
            ->where('status', 5)  // Completed collaborations
            ->count();

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
            'chat_id' => $chatId,
            'total_posts' => $totalPosts,
            'total_collaborations' => $totalCollaborations,
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
        $influencer = Influencer::where('user_id', $user->id)->first();

        if (!$influencer) {
            return response()->json([
                'error' => 'Influencer profile not found for the authenticated user.',
            ], 404);
        }

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
                $query->where('status', 5); // Only count collaboration requests where status = 3
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

    // Explore influencers
    public function exploreInfluencers(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Ensure the authenticated user is a brand
        if ($user->role !== 'brand' || !$user->brand) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        // Get the offset and limit from the request, defaulting to 0 and 10 respectively
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);

        // Get blocked user IDs
        $blockedUserIds = BlockUser::where('blocker_id', $user->id)
        ->orWhere('blocked_id', $user->id)
        ->pluck('blocked_id')
        ->merge(BlockUser::where('blocked_id', $user->id)->pluck('blocker_id'))
        ->unique()
        ->toArray();

        // Step 1: Fetch collaboration IDs created by the brand
        $brandCollaborationIds = Collaboration::where('brand_id', $user->brand->id)
            ->pluck('id')
            ->toArray();

        // Step 2: Fetch influencer IDs from CollaborationRequest with excluded statuses for the brand's collaborations
        $excludedStatuses = [3, 4, 5];
        $excludedInfluencerIds = CollaborationRequest::whereIn('collaboration_id', $brandCollaborationIds)
            ->whereIn('status', $excludedStatuses)
            ->pluck('influencer_id')
            ->toArray();

        // Step 3: Fetch categories from both the Brand and the Collaborations it created
        $collaborationCategories = Collaboration::where('brand_id', $user->brand->id)
        ->pluck('category')
        ->toArray();

        $brandCategories = Brand::where('id', $user->brand->id)
        ->pluck('category')
        ->toArray();

        // Ensure categories are always arrays before merging
        $collaborationCategories = array_filter(array_map(fn($c) => json_decode($c, true) ?? [], $collaborationCategories));
        $brandCategories = array_filter(array_map(fn($c) => json_decode($c, true) ?? [], $brandCategories));

        // Merge and flatten both category sources, removing duplicates
        $allCategories = array_unique(array_merge(...$collaborationCategories, ...$brandCategories));

        // Step 4: Fetch influencers from the brand's collaboration categories, excluding excluded influencers
        $influencersFromCategories = Influencer::where(function ($query) use ($allCategories) {
            foreach ($allCategories as $categoryId) {
                $query->orWhereRaw('JSON_CONTAINS(category, ?)', [json_encode($categoryId)]);
            }
        })
            ->whereNotIn('id', array_merge($excludedInfluencerIds, $blockedUserIds))
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->take($limit)
            ->get();

        // Step 5: Fetch additional influencers to fill the limit
        $totalFetched = $influencersFromCategories->count();
        $remainingLimit = $limit - $totalFetched;

        $otherInfluencers = [];
        if ($remainingLimit > 0) {
            $otherInfluencers = Influencer::whereNotIn('id', array_merge($excludedInfluencerIds, $blockedUserIds))
                ->whereNotIn('id', $influencersFromCategories->pluck('id'))
                ->orderBy('created_at', 'desc')
                ->skip($offset + $totalFetched)
                ->take($remainingLimit)
                ->get();
        }

        // Combine both collections
        $influencers = $influencersFromCategories->merge($otherInfluencers);

        // Check if there are more influencers
        $totalInfluencers = Influencer::whereNotIn('id', array_merge($excludedInfluencerIds, $blockedUserIds))->count();
        $hasMore = ($offset + $limit) < $totalInfluencers;

        return response()->json([
            'success' => true,
            'influencers' => $influencers,
            'has_more' => $hasMore,
            'next_offset' => $offset + $limit
        ]);
    }

    // Fetch collaborations the influencer has expressed interest in
    public function fetchInterestedCollaborations()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Ensure the user is an influencer
        if (!$user->influencer) {
            return response()->json([
                'success' => false,
                'message' => 'Only influencers can access this resource.',
            ], 403);
        }

        $influencerId = $user->influencer->id;

        // Fetch collaborations the influencer has expressed interest in
        $interestedCollaborations = CollaborationRequest::with('collaboration', 'collaboration.brand')
            ->where('influencer_id', $influencerId)
            ->where('status', 2) // 2 = expressed interest
            ->orderBy('created_at', 'desc')
            ->get();

        if ($interestedCollaborations->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No collaborations found where the influencer has expressed interest.',
            ]);
        }

        // Prepare response data
        $data = $interestedCollaborations->map(function ($request) {
            return [
                'collaboration_request_id' => $request->id,
                'collaboration_request_status' => $request->status,
                'collaboration_id' => $request->collaboration->id,
                'collaboration_name' => $request->collaboration->name,
                'collaboration_image' => $request->collaboration->image,
                'brand_id' => $request->collaboration->brand->id,
                'brand_name' => $request->collaboration->brand->name,
                'brand_profile_photo' => $request->collaboration->brand->profile_photo,
                'brand_user_id' => $request->collaboration->brand->user->id,
                'expressed_interest_at' => $request->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'success' => true,
            'collaborations' => $data,
        ]);
    }

}
