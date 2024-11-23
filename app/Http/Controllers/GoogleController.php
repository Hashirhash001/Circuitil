<?php

namespace App\Http\Controllers;

use App\Models\User;
use Google\Client as GoogleClient;
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
            'idToken' => 'required|string',
            'role' => 'required|in:brand,influencer',
        ]);

        // Return validation errors if validation fails
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
            $idToken = $request->idToken;

            // Initialize the Google Client and set client ID
            $client = new GoogleClient();
            $client->setClientId(env('GOOGLE_CLIENT_ID')); // Use your Google Client ID

            // Verify the ID token with Google
            $payload = $client->verifyIdToken($idToken);

            // If payload is null, the token verification failed
            if (!$payload) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid Google token.',
                    'code' => 401,
                    'timestamp' => now()->toISOString(),
                ], 401);
            }

            // Extract user details from the payload
            $googleId = $payload['sub']; // Google's unique user ID
            $email = $payload['email'];
            $name = $payload['name'];

            // Check if user exists, or create a new one
            $user = User::where('email', $email)->first();

            if (!$user) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => null, // No password for Google login
                    'role' => $request->role,
                ]);
            }

            // Generate access token for the user
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return successful response
            return response()->json([
                'status' => 'success',
                'message' => 'Login successful.',
                'access_token' => $token,
                'role' => $user->role,
                'profile_updated' => $user->profile_updated,
                'code' => 200,
                'timestamp' => now()->toISOString(),
            ], 200);

        } catch (\Exception $e) {
            // Handle any exceptions that occur during the process
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
                'timestamp' => now()->toISOString(),
            ], 500);
        }
    }


}
