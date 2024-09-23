<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Influencer;
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

        // Search for brands and influencers by name, excluding the current user
        $brands = Brand::where('name', 'LIKE', '%' . $query . '%')
                        ->where('user_id', '!=', $currentUserId)
                        ->get();

        $influencers = Influencer::where('name', 'LIKE', '%' . $query . '%')
                                    ->where('user_id', '!=', $currentUserId)
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
        $brands = Brand::where('category', $category)->get();

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

        // Get the influencer's category
        $category = $authUser->influencer->category;

        // Fetch collaborations where the category matches the influencer's category
        $collaborations = Collaboration::where('category', $category)->get();

        if ($collaborations->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No collaborations found for this category.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'collaborations' => $collaborations,
        ]);
    }

}