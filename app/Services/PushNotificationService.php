<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    /**
     * Send push notifications via FCM HTTP v1 API.
     *
     * @param array $fcmTokens
     * @param array $data
     * @return mixed
     */
    public function sendPushNotification(array $fcmTokens, array $data)
    {
        $url = 'https://fcm.googleapis.com/v1/projects/circuitil-zenerom/messages:send';
        $accessToken = $this->getAccessToken();

        foreach ($fcmTokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $data['title'],
                        'body' => $data['body'],
                        'image' => $data['image'] ?? null, // This is valid
                    ],
                    'data' => array_map('strval', $data['data'] ?? []), // Add the icon here
                ],
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($response->failed()) {
                Log::error('FCM Notification failed', ['response' => $response->body()]);
            } else {
                Log::info('FCM Notification sent successfully', ['response' => $response->json()]);
            }
        }

        return true;
    }

    /**
     * Get the OAuth 2.0 access token for FCM HTTP v1 API.
     *
     * @return string
     */
    private function getAccessToken()
    {
        $client = new GoogleClient();
        $client->setAuthConfig(config('services.firebase.service_account')); // Path to service account JSON
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $token = $client->fetchAccessTokenWithAssertion();

        return $token['access_token'];
    }
}
