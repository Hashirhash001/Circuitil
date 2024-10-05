<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\User;
use App\Models\Brand;
use Illuminate\Http\Request;
use App\Models\ChatParticipant;
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

        $user = Auth::user(); // Get the logged-in user

        // Fetch the brand profile
        $brand = Brand::where('id', $brandId)->first();

        if (!$brand) {
            return response()->json(['error' => 'Brand not found'], 404);
        }

        // Check if there's an existing chat between the logged-in user and the brand's user
        $chat = Chat::whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->whereHas('participants', function ($query) use ($brand) {
                $query->where('user_id', $brand->user_id); // Assuming 'user_id' is the foreign key in the brand table
            })
            ->first();

        // If no chat exists, create a new one
        if (!$chat) {
            // Create a new chat
            $chat = Chat::create(['created_by' => $user->id]);

            // Add both the authenticated user and the brand's user as participants
            ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $user->id]);
            ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $brand->user_id]);
        }

        // Return the chat ID in the response, whether it existed or was newly created
        $chatId = $chat->id;

        return response()->json([
            'success' => true,
            'message' => 'Brand details fetched successfully',
            'brand' => $brand,
            'chat_id' => $chatId, // Include the chat_id in the response
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

        // Store categories as an array in the 'categories' field
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
}
