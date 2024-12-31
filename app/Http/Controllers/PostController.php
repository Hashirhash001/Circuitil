<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostLike;
use Illuminate\Http\Request;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Validator;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

class PostController extends Controller
{
    // Create a new post
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,heic,gif,webp,svg|max:2048',
            'description' => 'nullable|string',
        ]);

        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();

        // Handle image upload
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('post_images', 'public');
            $data = array_merge($validator->validated(), ['image' => $path]);
        } else {
            $data = $validator->validated();
        }

        $data['user_id'] = $user->id;

        $post = Post::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Post created successfully',
            'post' => $post,
        ]);
    }

    public function getPostsByUser($user_id)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $authUser = Auth::user(); // Get the authenticated user

        // Fetch posts for the specified user, order by 'created_at' descending, and eager load the likes relationship
        $posts = Post::where('user_id', $user_id)
            ->with('likes')
            ->orderBy('created_at', 'desc')  // Fetch latest posts first
            ->get();

        if ($posts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No posts found for this user.',
            ], 404);
        }

        // Add a flag to each post to indicate if the authenticated user has liked it
        // and include the total likes count
        $posts = $posts->map(function ($post) use ($authUser) {
            // Check if the authenticated user has liked the post and the like has a status of 1
            $post->has_liked = $post->likes->contains(function ($like) use ($authUser) {
                return $like->user_id == $authUser->id && $like->status == 1;
            });

            // Get the total likes count for the post where the status is 1
            $post->total_likes = $post->likes_count;

            return $post;
        });

        return response()->json([
            'success' => true,
            'posts' => $posts,
        ]);
    }

    // Like a post
    public function likePost($postId, NotificationService $notificationService, PushNotificationService $pushNotificationService)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();
        $post = Post::find($postId);

        if (!$post) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        $existingLike = PostLike::where('user_id', $user->id)
                                ->where('post_id', $postId)
                                ->first();

        $isLiked = $existingLike && $existingLike->status === 1;

        if ($existingLike) {
            if ($isLiked) {
                $existingLike->update(['status' => 0]);
                $post->decrement('likes_count');

                Notification::where('type', 'post_like')
                    ->where('data->user_id', $user->id)
                    ->where('data->post_id', $postId)
                    ->delete();

                return response()->json(['success' => true, 'message' => 'Post unliked successfully']);
            } else {
                $existingLike->update(['status' => 1]);
                $post->increment('likes_count');
            }
        } else {
            PostLike::create([
                'user_id' => $user->id,
                'post_id' => $postId,
                'status' => 1,
            ]);
            $post->increment('likes_count');
        }

        if ($post->user_id !== $user->id) {
            $userRole = $user->role;
            $userId = null;
            $roleId = null;

            if ($userRole === 'influencer' && $user->influencer) {
                $userId = $user->influencer->id;
                $roleId = 'influencer_id';
            } elseif ($userRole === 'brand' && $user->brand) {
                $userId = $user->brand->id;
                $roleId = 'brand_id';
            }

            $notificationData = [
                'message' => (($user->influencer->name ?? $user->brand->name) . " liked your post."),
                'user_profile_photo' => ($user->influencer->profile_photo ?? $user->brand->profile_photo) ?? null,
                'user_role' => $user->role,
                $roleId => $userId,
                'user_id' => $user->id,
                'post_id' => $postId,
                'post_image' => $post->image,
            ];

            $notificationService->createNotification(
                $post->user_id,
                'post_like',
                $notificationData
            );

            $fcmTokens = $post->user->fcmTokens->pluck('fcm_token')->toArray();

            if (!empty($fcmTokens)) {
                // Define the prefix for images
                $imageBaseUrl = 'https://apptest.zenerom.com/storage/'; // Replace with your actual image base URL

                $pushNotificationData = [
                    'title' => 'New Like',
                    'body' => $notificationData['message'],
                    'image' => $imageBaseUrl . $notificationData['post_image'],
                    'click_action' => url('/post/' . $postId),
                    'data' => array_merge($notificationData, [
                        'icon' => $imageBaseUrl . $notificationData['user_profile_photo'], // Move 'icon' to the 'data' section
                    ]),
                ];

                if (is_null($fcmTokens) || is_null($pushNotificationData)) {
                    Log::error('Push Notification Data is null', [
                        'fcmTokens' => $fcmTokens,
                        'pushNotificationData' => $pushNotificationData
                    ]);
                } else {
                    Log::info('Push Notification Data', [
                        'fcmTokens' => $fcmTokens,
                        'pushNotificationData' => $pushNotificationData
                    ]);
                }

                $response = $pushNotificationService->sendPushNotification($fcmTokens, $pushNotificationData);
                // Log the response, ensuring it's in array format
                Log::info('FCM Response: ', ['response' => $response]);
            } else {
                Log::error('No FCM tokens found for the user.');
            }
        }

        return response()->json([
            'success' => true,
            'message' => $isLiked ? 'Post liked again' : 'Post liked successfully',
        ]);
    }

    public function getLikes($postId)
    {
        $post = Post::find($postId);

        if(!$post) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        $likes = PostLike::where('post_id', $postId)
                        ->where('status', 1) // Only fetch active likes
                        ->count();

        return response()->json([
            'success' => true,
            'likes' => $likes,
        ]);
    }

    // Remove a post
    public function destroy($id)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Find the post by ID and ensure it's owned by the authenticated user
        $post = Post::where('id', $id)->where('user_id', $user->id)->first();

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found or not owned by the authenticated user',
            ], 404);
        }

        $post->delete();

        return response()->json([
            'success' => true,
            'message' => 'Post deleted successfully.',
        ]);
    }

}
