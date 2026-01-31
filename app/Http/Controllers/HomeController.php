<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\BlockUser;
use App\Models\Influencer;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\Collaboration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class HomeController extends Controller
{
    // Search all brands and influencers
    public function search(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Validate the input using the Validator class
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'error' => $validator->errors()->first()], 400);
        }

        $query = $request->input('query'); // The search query (name)
        $currentUserId = Auth::id(); // Get the current authenticated user's ID

        // Get blocked user IDs
        $blockedUserIds = BlockUser::where('blocker_id', $currentUserId)
        ->orWhere('blocked_id', $currentUserId)
        ->pluck('blocked_id')
        ->merge(BlockUser::where('blocked_id', $currentUserId)->pluck('blocker_id'))
        ->unique()
        ->toArray();

        // Search for brands and influencers by name, excluding the current user
        $brands = Brand::where('name', 'LIKE', '%' . $query . '%')
                        ->where('user_id', '!=', $currentUserId)
                        ->whereNotIn('user_id', $blockedUserIds)
                        ->whereNotNull('category')
                        ->whereNotNull('profile_photo')
                        ->get();

        $influencers = Influencer::where('name', 'LIKE', '%' . $query . '%')
                                    ->where('user_id', '!=', $currentUserId)
                                    ->whereNotIn('user_id', $blockedUserIds)
                                    ->whereNotNull('category')
                                    ->whereNotNull('profile_photo')
                                    ->get();

        // Map the results into a single array, preserving the 'type' key
        $results = collect(); // Create a new empty collection

        $brands->each(function($brand) use ($results) {
            $results->push([
                'type' => 'brand', // Identify as a brand
                'details' => $brand
            ]);
        });

        $influencers->each(function($influencer) use ($results) {
            $results->push([
                'type' => 'influencer', // Identify as an influencer
                'details' => $influencer
            ]);
        });

        // Check if the results collection is empty
        if ($results->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No matches found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    // Get all influencers by category
    public function getInfluencersByCategory(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Use Validator to validate the category, min_collab_value, and max_collab_value fields
        $validator = Validator::make($request->all(), [
            'category' => 'required|string|exists:influencers,category',
            'min_collab_value' => 'nullable|numeric|min:0',
            'max_collab_value' => 'nullable|numeric|min:0',
        ]);

        // Add custom validation logic to check the relationship between min and max values
        $validator->after(function ($validator) use ($request) {
            $minCollabValue = $request->input('min_collab_value');
            $maxCollabValue = $request->input('max_collab_value');

            if (!is_null($minCollabValue) && !is_null($maxCollabValue) && $minCollabValue > $maxCollabValue) {
                $validator->errors()->add('min_collab_value', 'The min_collab_value must be less than or equal to max_collab_value.');
                $validator->errors()->add('max_collab_value', 'The max_collab_value must be greater than or equal to min_collab_value.');
            }
        });

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 400); // Return 400 Bad Request with validation errors
        }

        // Get validated category
        $category = $validator->validated()['category'];

        // Get min and max collaboration values from the request, if provided
        $minCollabValue = $request->input('min_collab_value');
        $maxCollabValue = $request->input('max_collab_value');

        // Fetch influencers that match the provided category and filter by collaboration value
        $query = Influencer::where('category', $category);

        // Apply min_collab_value filter if provided
        if (!is_null($minCollabValue)) {
            $query->where('collab_value', '>=', $minCollabValue);
        }

        // Apply max_collab_value filter if provided
        if (!is_null($maxCollabValue)) {
            $query->where('collab_value', '<=', $maxCollabValue);
        }

        // Get the filtered influencers
        $influencers = $query->get();

        if ($influencers->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No influencers found for the given category and collaboration value range.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'influencers' => $influencers,
        ]);
    }

    // Get all brands by category
    public function getBrandsByCategory(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Use Validator to validate the category field
        $validator = Validator::make($request->all(), [
            'category' => 'required|string|exists:brands,category',
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 400); // Return 400 Bad Request with validation errors
        }

        // Get validated category
        $category = $validator->validated()['category'];

        // Fetch brands that match the provided category
        $brands = Brand::where('category', $category)
                ->whereNotNull('profile_photo')
                ->get();

        if ($brands->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No brands found for the given category.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'brands' => $brands,
        ]);
    }

    // Fetch collaborations that match the authenticated user's category.
    public function getCollaborationsForInfluencersByCategory()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $authUser = Auth::user(); // Get the authenticated user

        // Ensure the user is an influencer
        if (!$authUser->influencer) {
            return response()->json([
                'success' => false,
                'message' => 'Only influencers can access this resource.',
            ], 403);
        }

        // Get the influencer's categories (assuming it's stored as JSON)
        $categories = json_decode($authUser->influencer->category);

        // Ensure categories are provided and properly parsed
        if (!$categories || !is_array($categories)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid categories found for this influencer.',
            ], 422);
        }

        // Fetch collaborations where the category matches and the collaboration has not ended
        $collaborations = Collaboration::where(function ($query) use ($categories) {
            foreach ($categories as $categoryId) {
                $query->orWhereRaw('JSON_CONTAINS(category, ?)', [json_encode($categoryId)]);
            }
        })
        ->where(function ($query) {
            $query->whereNull('end_date') // Collaborations with no end date are considered active
                  ->orWhere(function ($query) {
                      // Check if the end_date is in 'd-m-Y' format and still active
                      $query->whereNotNull('end_date')
                            ->whereRaw("STR_TO_DATE(end_date, '%d-%m-%Y') >= ?", [now()]);
                  });
        })
        ->get();

        if ($collaborations->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No collaborations found for these categories.',
            ], 404);
        }

        // Map through collaborations and add the 'has_expressed_interest' flag
        $collaborationsWithInterestFlag = $collaborations->map(function ($collaboration) use ($authUser) {
            $hasExpressedInterest = $collaboration->collaborationRequests()
                ->where('influencer_id', $authUser->influencer->id)
                ->exists();

            return $collaboration->toArray() + [
                'has_expressed_interest' => $hasExpressedInterest,
                'brand_name' => $collaboration->brand->name ?? 'Unknown Brand',
            ];
        });

        return response()->json([
            'success' => true,
            'collaborations' => $collaborationsWithInterestFlag,
        ]);
    }

    public function getTopBrands()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Fetch top brands based on completed collaboration requests and count distinct influencers
        $topBrands = Brand::with(['user']) // Load the user relationship
            ->withCount([
                // Count completed collaborations
                'collaborations as completed_collaboration_requests_count' => function ($query) {
                    $query->whereHas('collaborationRequests', function ($requestQuery) {
                        $requestQuery->where('status', 5); // Filter collaboration requests with status = 5
                    });
                },
                // Count distinct influencers who completed collaborations
                'collaborations as distinct_influencers_count' => function ($query) {
                    $query->whereHas('collaborationRequests', function ($requestQuery) {
                        $requestQuery->where('status', 5); // Filter collaboration requests with status = 5
                    })->distinct('influencer_id'); // Ensure unique influencers are counted
                },
            ])
            ->having('completed_collaboration_requests_count', '>', 0) // Only brands with at least one completed request
            ->orderByDesc('completed_collaboration_requests_count') // Sort by the count of completed requests
            ->limit(10) // Limit the results to the top 10 brands
            ->get()
            ->map(function ($brand) {
                return [
                    'id' => $brand->id,
                    'user_id' => $brand->user->id, // Access user ID through the relationship
                    'name' => $brand->name,
                    'profile_photo' => $brand->profile_photo,
                    'completed_collaboration_requests_count' => $brand->completed_collaboration_requests_count,
                    'distinct_influencers_count' => $brand->distinct_influencers_count, // Add the new count field
                ];
            });

        if ($topBrands->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No top brands found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'top_brands' => $topBrands,
        ]);
    }

    // Get all categories
    public function categories()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Fetch all categories
        $categories = Category::all();

        // Return the categories as a JSON response
        return response()->json([
            'success' => true,
            'categories' => $categories
        ]);
    }

    // Get all brands
    public function AllBrands()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Fetch all brands
        $brands = Brand::whereNotNull('profile_photo')->take(20)->get();

        if ($brands->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No brands found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'brands' => $brands
        ]);
    }

    // Get all influencers
    public function AllInfluencers()
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Fetch all brands
        $influencers = Influencer::whereNotNull('profile_photo')->take(20)->get();

        if ($influencers->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No influencers found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'influencers' => $influencers
        ]);
    }

    // Get user notifications
    public function getUserNotifications(Request $request)
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Fetch the notifications for the authenticated user
        $notifications = Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc') // Order by the most recent notifications first
            ->get();

        // Format the notifications if needed
        $formattedNotifications = $notifications->map(function ($notification) {
            // Add any necessary formatting or additional fields
            return [
                'id' => $notification->id,
                'message' => $notification->message,
                'type' => $notification->type,
                'created_at' => $notification->created_at->toDateTimeString(), // Format the created_at timestamp
                'is_read' => $notification->is_read, // Assuming there's an 'is_read' field
                'data' => json_decode($notification->data) // Any extra data stored in JSON format
            ];
        });

        return response()->json([
            'success' => true,
            'notifications' => $formattedNotifications
        ]);
    }


}
