<?php

namespace App\Services;

use App\Models\Notification;

class NotificationService
{
    public function createNotification($userId, $type, $data)
    {
        Notification::create([
            'user_id' => $userId,
            'type'    => $type,
            'data'    => json_encode($data),
        ]);
    }
}
