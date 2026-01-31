<?php

use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class ChatUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $chatId;
    public $lastMessage;
    public $unreadCount;

    public function __construct(int $chatId, array $lastMessage, int $unreadCount)
    {
        $this->chatId = $chatId;
        $this->lastMessage = $lastMessage;
        $this->unreadCount = $unreadCount;
    }

    public function broadcastOn()
    {
        return new Channel('chat-' . $this->chatId);
    }

    public function broadcastAs()
    {
        return 'chat.updated';
    }
}
