<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class MessageSent implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(array $message)
    {
        $this->message = $message;
    }

    public function broadcastOn()
    {
        // Use the chat_id dynamically to create a unique channel
        return new Channel('chat-' . $this->message['chat_id']);
    }

    public function broadcastAs()
    {
        return 'message.sent';
    }
}

