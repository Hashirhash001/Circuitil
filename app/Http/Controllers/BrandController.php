<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;
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

        return response()->json([
            'success' => true,
            'message' => 'brand details fetched successfully',
            'brand' => $brand,
        ]);
    }

    // brand profile show
    public function show($brandId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Find the brand associated with the user
        $brand = Brand::where('id', $brandId)->first();

        if (!$brand) {
            return response()->json(['error' => 'brand not found'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'brand details fetched successfully',
            'brand' => $brand,
        ]);
    }

    /**
     * Update brand profile
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
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
        $brand = Brand::where('user_id', $user->id)->firstOrFail();

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

        // Update brand details
        $brand->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Brand details updated successfully',
            'brand' => $brand,
        ]);
    }

    /**
     * Fetch all collaborations for a brand.
     */
    public function getCollaborations($brandId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Find the brand by ID
        $brand = Brand::find($brandId);

        // Check if the brand exists
        if (!$brand) {
            return response()->json(['error' => 'Brand not found'], 404);
        }

        // Check if the authenticated user is the owner of the brand
        if ($brand->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Fetch all collaborations for the brand, eager load the collaboration requests where status is 'accepted'
        $collaborations = $brand->collaborations()->with(['collaborationRequests' => function ($query) {
            $query->where('status', '3'); // Assuming 'accepted' is the status for accepted requests
        }, 'collaborationRequests.influencer'])->get(); // Eager load the influencer details for accepted requests

        // Add a flag to indicate if each collaboration has ended and include influencer details for accepted requests
        $collaborationsWithDetails = $collaborations->map(function ($collaboration) {
            $currentDate = now(); // Get the current date and time
            $endDate = $collaboration->end_date;

            // Determine if the end_date has passed
            $hasEnded = $endDate && $currentDate->greaterThanOrEqualTo($endDate);

            // Check if there are any accepted collaboration requests and fetch the first accepted influencer details
            $acceptedRequest = $collaboration->collaborationRequests->first(); // Get the first accepted request (if exists)
            $influencerDetails = $acceptedRequest ? $acceptedRequest->influencer->toArray() : null;

            // Return all attributes including the 'has_ended' flag and influencer details (if accepted)
            return $collaboration->toArray() + [
                'has_ended' => $hasEnded,
                'influencer' => $influencerDetails
            ];
        });

        return response()->json([
            'success' => true,
            'collaborations' => $collaborationsWithDetails
        ]);
    }

}
