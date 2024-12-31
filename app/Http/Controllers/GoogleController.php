<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Brand;
use App\Models\Influencer;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Validator;

class GoogleController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     *
     * @return \Illuminate\Http\Response
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google.
     *
     * @return \Illuminate\Http\Response
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            // Find or create the user in your database
            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                // Create a new user if not exists
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => Hash::make(Str::random(16)),
                ]);
            }

            // Log in the user
            Auth::login($user);

            // Create a token for the user
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return JSON response
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

    public function googleLogin(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'accessToken' => 'required|string',
            'role' => 'nullable|in:brand,influencer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
                'code' => 422,
                'timestamp' => now()->toISOString(),
            ], 422);
        }

        try {
            $accessToken = $request->accessToken;

            // Call Google's UserInfo API
            $client = new \GuzzleHttp\Client();
            $response = $client->get('https://www.googleapis.com/oauth2/v1/userinfo', [
                'headers' => [
                    'Authorization' => "Bearer $accessToken",
                ],
            ]);

            $userInfo = json_decode($response->getBody(), true);

            // Extract user details
            $googleId = $userInfo['id'];
            $email = $userInfo['email'];
            $name = $userInfo['name'];
            $emailVerifiedAt = now();

            // Check if user exists in the database
            $user = User::where('email', $email)->first();

            if (!$user) {
                // Create a new user
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => null,
                    'role' => $request->role ?? null,
                    'email_verified_at' => $emailVerifiedAt,
                ]);

                // Create associated Brand or Influencer based on role
                if ($request->role === 'brand') {
                    Brand::create([
                        'user_id' => $user->id,
                        'name' => $user->name,
                    ]);
                } elseif ($request->role === 'influencer') {
                    Influencer::create([
                        'user_id' => $user->id,
                        'name' => $user->name,
                    ]);
                }

            } else {
                // Update Google ID if not already present
                if (is_null($user->google_id)) {
                    $user->update([
                        'google_id' => $googleId,
                        // 'email_verified_at' => $emailVerifiedAt,
                    ]);
                }
            }

            // Generate a personal access token for the user
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful.',
                'access_token' => $token,
                'role' => $user->role,
                'profile_updated' => (int) ($user->profile_updated ?? 0), // Ensure 0 or 1 is returned
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred during login.',
                'error' => [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                'code' => 500,
            ], 500);
        }
    }


}
