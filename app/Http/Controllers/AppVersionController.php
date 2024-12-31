<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AppVersion;
use Illuminate\Http\Request;

class AppVersionController extends Controller
{
    // Fetch all previous versions for a platform
    public function fetchPreviousVersions($platform)
    {
        $versions = AppVersion::where('platform', $platform)
            ->orderBy('released_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'versions' => $versions,
        ]);
    }

    // Fetch the current version for a platform
    public function fetchCurrentVersion($platform)
    {
        $currentVersion = AppVersion::where('platform', $platform)
            ->orderBy('released_at', 'desc')
            ->first();

        $appSetting = AppSetting::get();

        if (!$currentVersion) {
            return response()->json([
                'success' => false,
                'message' => 'No version found for the platform.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'latest_version' => $currentVersion,
            'app_setting' => $appSetting
        ]);
    }

    // Create a new app version
    public function createAppVersion(Request $request)
    {
        $validatedData = $request->validate([
            'version' => 'required|string',
            'platform' => 'required|string|in:android,ios',
            'release_notes' => 'nullable|string',
            'released_at' => 'nullable|date',
        ]);

        $appVersion = AppVersion::create([
            'version' => $validatedData['version'],
            'platform' => $validatedData['platform'],
            'release_notes' => $validatedData['release_notes'] ?? null,
            'released_at' => $validatedData['released_at'] ?? now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'App version created successfully.',
            'app_version' => $appVersion,
        ], 201);
    }
}
