<?php

namespace App\Models;

use App\Models\User;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MessageReadReceipt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['message_id', 'reader_id', 'read_at'];

    public function message()
    {
        return $this->belongsTo(ChatMessage::class);
    }

    public function reader()
    {
        return $this->belongsTo(User::class, 'reader_id');
    }
}
