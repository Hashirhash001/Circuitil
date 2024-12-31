<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Mail\OtpMail;
use App\Models\Brand;
use App\Models\Influencer;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Mail\PasswordResetOtpMail;
use App\Mail\MailVerificationOtpMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:brand,influencer',
            'dob' => 'nullable|date_format:d-m-Y',
            'fcm_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        // Store or update FCM token if provided in the request
        if ($request->has('fcm_token')) {
            $fcmToken = $request->fcm_token;

            // Check if the FCM token already exists for the user
            $existingToken = $user->fcmTokens()->where('fcm_token', $fcmToken)->first();

            if (!$existingToken) {
                // Create a new FCM token entry
                $user->fcmTokens()->create([
                    'fcm_token' => $fcmToken,
                ]);
            }
        }

        // Generate a 6-digit numeric OTP
        $otp = rand(1000, 9999);
        // Set OTP expiration time (e.g., 10 minutes from now)
        $otpExpiresAt = Carbon::now()->addMinutes(1);

        // Save OTP and its expiration time to the user
        $user->otp = $otp;
        $user->otp_expires_at = $otpExpiresAt;
        $user->save();

        // Send the OTP email (create and use a Mailable for this)
        Mail::to($user->email)->send(new MailVerificationOtpMail($otp));

        // Check the role and create the corresponding model
        if ($data['role'] === 'brand') {
            Brand::create([
                'user_id' => $user->id,
                'name' => $user->name,
            ]);
        } elseif ($data['role'] === 'influencer') {
            Influencer::create([
                'user_id' => $user->id,
                'name' => $user->name,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'User created successfully. Please verify your email using the OTP sent to your inbox.',
            'user_id' => $user->id, // Include the user ID for verification purposes
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'otp' => 'required|digits:4',
            'fcm_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::find($request->user_id);

        // Ensure that otp_expires_at is a Carbon instance or null
        if (!is_null($user->otp_expires_at)) {
            $user->otp_expires_at = Carbon::parse($user->otp_expires_at);
        }

        // Check if OTP is valid and not expired
        if ($user->otp !== $request->otp) {
            return response()->json(['error' => 'Invalid OTP.'], 400);
        }

        // Ensure we are comparing with a valid Carbon instance
        if (Carbon::now()->greaterThan($user->otp_expires_at)) {
            return response()->json(['error' => 'OTP has expired. Please request a new one.'], 400);
        }

        // Store or update FCM token if provided in the request
        if ($request->has('fcm_token')) {
            $fcmToken = $request->fcm_token;

            // Check if the FCM token already exists for the user
            $existingToken = $user->fcmTokens()->where('fcm_token', $fcmToken)->first();

            if (!$existingToken) {
                // Create a new FCM token entry
                $user->fcmTokens()->create([
                    'fcm_token' => $fcmToken,
                ]);
            }
        }

        // Mark user as verified
        $user->otp = null; // Clear the OTP
        $user->otp_expires_at = null; // Clear expiration time
        $user->email_verified_at = now(); // Use now() to set the current time for verification
        $user->save();

        // Create a new token for the user
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'role' => $user->role,
            'profile_updated' => $user->profile_updated,
        ]);
    }

    public function resendOtp(Request $request)
    {
        // Validate the request using the Validator class
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Retrieve the user
        $user = User::find($request->user_id);

        // Check if OTP is already set and if it's expired
        if ($user->otp && !Carbon::now()->greaterThan($user->otp_expires_at)) {
            return response()->json(['error' => 'An OTP has already been sent and is still valid. Please check your email.'], 400);
        }

        // Generate a new 6-digit OTP
        $otp = rand(1000, 9999);

        // Calculate OTP expiration time (e.g., 10 minutes from now)
        $otpExpiresAt = Carbon::now()->addMinutes(10);

        // Update the user's OTP and expiration time in the database
        $user->otp = $otp;
        $user->otp_expires_at = $otpExpiresAt;
        $user->save();

        // Send email using the Mail facade
        Mail::to($user->email)->send(new MailVerificationOtpMail($otp));

        return response()->json(['success' => 'OTP resent to your email. Please check your inbox.']);
    }


    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
            'fcm_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid login details'], 401);
        }

        $user = User::where('email', $request->email)->firstOrFail();

        // Store or update FCM token if provided in the request
        if ($request->has('fcm_token')) {
            $fcmToken = $request->fcm_token;

            // Check if the FCM token already exists for the user
            $existingToken = $user->fcmTokens()->where('fcm_token', $fcmToken)->first();

            if (!$existingToken) {
                // Create a new FCM token entry
                $user->fcmTokens()->create([
                    'fcm_token' => $fcmToken,
                ]);
            }
        }

        // Check if the user's email is verified
        if (!$user->email_verified_at) {

            // Generate a new 6-digit OTP
            $otp = rand(1000, 9999);

            // Set OTP expiration time (e.g., 10 minutes from now)
            $otpExpiresAt = Carbon::now()->addMinutes(1);

            // Update the user's OTP and expiration time in the database
            $user->otp = $otp;
            $user->otp_expires_at = $otpExpiresAt;
            $user->save();

            // Send the OTP email
            Mail::to($user->email)->send(new MailVerificationOtpMail($otp));

            return response()->json([
                'message' => 'Email not verified. A new OTP has been sent to your email for verification.',
                'user_id' => $user->id,
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'User logged in successfully',
            'access_token' => $token,
            'id' => $user->id,
            'token_type' => 'Bearer',
            'role' => $user->role,
            'profile_updated' => $user->profile_updated,
        ]);
    }

    public function logout()
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        $user = Auth::user();
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function sendOtpForResetPassword(Request $request)
    {
        // Validate the request
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        // Retrieve the user
        $user = User::where('email', $request->email)->first();

        // Check if user is found
        if (!$user) {
            return response()->json(['error' => 'This email address is not registered with us.'], 404);
        }

        // Check if the OTP is still valid
        if ($user->otp_expires_at && $user->otp_expires_at > Carbon::now()) {
            return response()->json(['error' => 'An OTP has already been sent and is still valid. Please check your inbox.'], 400);
        }

        // Generate a 6-digit numeric OTP
        $otp = rand(1000, 9999);

        // Calculate OTP expiration time (e.g., 10 minutes from now)
        $otpExpiresAt = Carbon::now()->addMinutes(1);

        // Update the user's OTP and expiration time in the database
        $user->otp = $otp;
        $user->otp_expires_at = $otpExpiresAt;
        $user->save();

        // Send email using the Mail facade
        Mail::to($user->email)->send(new PasswordResetOtpMail($otp));

        return response()->json(['success' => 'OTP sent to your email. Please check your inbox.']);
    }

    // public function resetPassword(Request $request)
    // {
    //     // Validate the request
    //     $request->validate([
    //         'email' => 'required|email|exists:users,email',
    //         'otp' => 'required|string',
    //         'password' => 'required|string|min:8|confirmed',
    //     ]);

    //     // Find the user by email
    //     $user = User::where('email', $request->email)->first();

    //     // Check if the OTP matches and is not expired
    //     if ($user->otp !== $request->otp) {
    //         return response()->json(['error' => 'Invalid OTP provided.'], 400);
    //     }

    //     if (Carbon::now()->greaterThan($user->otp_expires_at)) {
    //         return response()->json(['error' => 'OTP has expired. Please request a new one.'], 400);
    //     }

    //     // Update the user's password
    //     $user->password = Hash::make($request->password);
    //     // Clear the OTP and expiration time
    //     $user->otp = null;
    //     $user->otp_expires_at = null;
    //     $user->save();

    //     return response()->json(['success' => 'Password has been reset successfully.']);
    // }

    public function verifyPasswordOtp(Request $request)
    {
        // Validate the request
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string',
        ]);

        // Find the user by email
        $user = User::where('email', $request->email)->first();

        // Check if the OTP matches and is not expired
        if ($user->otp !== $request->otp) {
            return response()->json(['error' => 'Invalid OTP provided.'], 400);
        }

        if (Carbon::now()->greaterThan($user->otp_expires_at)) {
            return response()->json(['error' => 'OTP has expired. Please request a new one.'], 400);
        }

        return response()->json(['success' => 'OTP verified successfully.']);
    }

    public function resetPassword(Request $request)
    {
        // Validate the request
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Find the user by email
        $user = User::where('email', $request->email)->first();

        // Ensure OTP has already been verified
        if ($user->otp == null || $user->otp_expires_at == null) {
            return response()->json(['error' => 'OTP verification is required before resetting the password.'], 400);
        }

        // Update the user's password
        $user->password = Hash::make($request->password);
        $user->otp = null; // Reset OTP
        $user->otp_expires_at = null; // Reset OTP expiration time
        $user->save();

        return response()->json(['success' => 'Password has been reset successfully.']);
    }

}
