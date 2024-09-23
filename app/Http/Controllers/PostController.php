<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostLike;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PostController extends Controller
{
    // Create a new post
    public function store(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'description' => 'nullable|string',
        ]);

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

        // Fetch posts for the specified user and eager load the likes relationship
        $posts = Post::where('user_id', $user_id)
            ->with('likes')
            ->get();

        if ($posts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No posts found for this user.',
            ], 404);
        }

        // Add a flag to each post to indicate if the authenticated user has liked it
        $posts = $posts->map(function ($post) use ($authUser) {
            $post->has_liked = $post->likes->contains('user_id', $authUser->id); // Check if auth user liked the post
            return $post;
        });

        return response()->json([
            'success' => true,
            'posts' => $posts,
        ]);
    }

    public function likePost($postId)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();
        $post = Post::find($postId);

        if(!$post) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        // Check if the user already liked the post
        $existingLike = PostLike::where('user_id', $user->id)
                                ->where('post_id', $postId)
                                ->first();

        if ($existingLike) {
            if ($existingLike->status == 1) {
                // If already liked, change status to 0 (unlike)
                $existingLike->update(['status' => 0]);

                return response()->json([
                    'success' => true,
                    'message' => 'Post unliked successfully',
                ]);
            } else {
                // If already unliked, change status back to 1 (re-like)
                $existingLike->update(['status' => 1]);

                return response()->json([
                    'success' => true,
                    'message' => 'Post liked again',
                ]);
            }
        } else {
            // If no record exists, create a new like
            PostLike::create([
                'user_id' => $user->id,
                'post_id' => $postId,
                'status' => 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Post liked successfully',
            ]);
        }
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
