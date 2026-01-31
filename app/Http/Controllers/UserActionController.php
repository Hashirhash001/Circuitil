<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\BlockUser;
use App\Models\Influencer;
use App\Models\ReportUser;
use App\Models\UserReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class UserActionController extends Controller
{
    /**
     * Block a user.
     */
    public function blockUser(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'blocked_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $blockerId = Auth::id();
        $blockedId = $request->blocked_id;

        // Prevent self-blocking
        if ($blockerId == $blockedId) {
            return response()->json(['success' => false, 'message' => 'You cannot block yourself.'], 400);
        }

        // Check if already blocked
        if (BlockUser::where('blocker_id', $blockerId)->where('blocked_id', $blockedId)->exists()) {
            return response()->json(['success' => false,'message' => 'User already blocked.'], 400);
        }

        BlockUser::create([
            'blocker_id' => $blockerId,
            'blocked_id' => $blockedId,
        ]);

        return response()->json(['success' => true,'message' => 'User blocked successfully.']);
    }

    /**
     * Unblock a user.
     */
    public function unblockUser(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'blocked_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $blockerId = Auth::id();
        $blockedId = $request->blocked_id;

        $blocked = BlockUser::where('blocker_id', $blockerId)->where('blocked_id', $blockedId)->first();

        if (!$blocked) {
            return response()->json(['success' => false, 'message' => 'User is not blocked.'], 400);
        }

        $blocked->delete();

        return response()->json(['success' => true, 'message' => 'User unblocked successfully.']);
    }

    // Get blocked users
    public function getBlockedUsers()
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $userId = Auth::id();

        // Fetch blocked users with role information
        $blockedUsers = BlockUser::where('blocker_id', $userId)
            ->with('blockedUser')
            ->get()
            ->map(function ($blocked) {
                $blockedUser = $blocked->blockedUser;

                if (!$blockedUser) {
                    return null; // If user record is missing (rare case)
                }

                $profilePhoto = null;
                $name = $blockedUser->name; // Default to user's name

                if ($blockedUser->role === 'brand') {
                    $brand = Brand::where('user_id', $blockedUser->id)->select('name', 'profile_photo')->first();
                    if ($brand) {
                        $name = $brand->name;
                        $profilePhoto = $brand->profile_photo;
                    }
                } elseif ($blockedUser->role === 'influencer') {
                    $influencer = Influencer::where('user_id', $blockedUser->id)->select('name', 'profile_photo')->first();
                    if ($influencer) {
                        $name = $influencer->name;
                        $profilePhoto = $influencer->profile_photo;
                    }
                }

                return [
                    'id' => $blockedUser->id,
                    'name' => $name,
                    'email' => $blockedUser->email,
                    'profile_photo' => $profilePhoto,
                ];
            })->filter(); // Remove any null results

        return response()->json([
            'success' => true,
            'blocked_users' => $blockedUsers,
        ]);
    }

    /**
     * Report a user or post.
     */
    public function report(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
            'reported_id' => 'nullable|exists:users,id',
            'post_id' => 'nullable|exists:posts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!$request->reported_id && !$request->post_id) {
            return response()->json(['success' => false, 'message' => 'You must report either a user or a post.'], 400);
        }

        ReportUser::create([
            'reporter_id' => Auth::id(),
            'reported_id' => $request->reported_id,
            'post_id' => $request->post_id,
            'reason' => $request->reason,
        ]);

        return response()->json(['success' => true, 'message' => 'Report submitted successfully.']);
    }
}
