<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect the user to the Instagram authentication page.
     *
     * @return \Illuminate\Http\Response
     */

    public function redirectToProvider()
    {
        return Socialite::driver('instagram')->redirect();
    }

    /**
     * Obtain the user information from Instagram and log them in.
     *
     * @return \Illuminate\Http\Response
     */

    public function handleProviderCallback()
    {
        try {
            $instagramUser = Socialite::driver('instagram')->user();

            // Find or create the user in your database
            $user = User::where('name', $instagramUser->getName())->first();

            if (!$user) {
                // Create a new user if not exists
                $user = User::create([
                    'name' => $instagramUser->getName(),
                    'email' => $instagramUser->getEmail() ?? null,
                    'password' => Hash::make(Str::random(16)),
                    'profile_photo' => $instagramUser->getAvatar() ?? null,
                    'social_id' => $instagramUser->getId(),
                    'role' => 'influencer',
                ]);
            }

            // Log in the user
            Auth::login($user);

            // Create a token for the user
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return a JSON response with the token
            return response()->json([
                'success' => true,
                'message' => 'User logged in successfully',
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);

        } catch (\Exception $e) {
            // Handle errors and return a JSON response
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle deauthorization request from Instagram.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */

    public function deauthorize(Request $request)
    {
        try {
            $instagramUserId = $request->input('id');

            $user = User::where('social_id', $instagramUserId)->first();

            if ($user) {
                // Remove the Instagram ID and token
                $user->update(['social_id' => null, 'token' => null]);

                return response()->json([
                    'success' => true,
                    'message' => 'User deauthorized successfully',
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Instagram deletion request and remove user data.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleDeletionRequest(Request $request)
    {
        try {
            $instagramUserId = $request->input('id');

            $user = User::where('social_id', $instagramUserId)->first();

            if ($user) {
                // Delete the user
                $user->delete();

                return response()->json([
                    'success' => true,
                    'message' => 'User data deleted successfully',
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }
}
