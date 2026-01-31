<?php

namespace App\Models;

use App\Models\Chat;
use App\Models\User;
use App\Models\MessageReadReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChatMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['chat_id', 'sender_id', 'message', 'status', 'attachment_url', 'reply_to_message_id', 'created_at', 'updated_at'];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function readReceipts()
    {
        return $this->hasMany(MessageReadReceipt::class, 'message_id');
    }

    // Accessor to automatically decrypt the message when fetching
    public function getMessageAttribute($value)
    {
        return decrypt($value);
    }
}
