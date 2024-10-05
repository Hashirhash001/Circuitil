<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Chat;
use App\Models\User;
use App\Events\MessageSent;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use App\Models\ChatParticipant;
use App\Models\MessageReadReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    // send a message in a chat
    // public function sendMessage(Request $request, $chatId, $messageId = null)
    // {
    //     // Check if the user is authenticated
    //     if (!Auth::check()) {
    //         return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
    //     }

    //     $user = Auth::user();

    //     // Validate the request
    //     $validator = Validator::make($request->all(), [
    //         'message' => 'required|string|max:500',
    //         'attachment_url' => 'nullable|url|max:255', // Optional attachment (URL)
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['success' => false, 'error' => $validator->errors()], 422);
    //     }

    //     $data = $validator->validated();

    //     // Ensure the user is a participant in the chat
    //     $isParticipant = ChatParticipant::where('chat_id', $chatId)
    //         ->where('user_id', $user->id)
    //         ->exists();

    //     if (!$isParticipant) {
    //         return response()->json(['success' => false, 'error' => 'You are not a participant in this chat'], 403);
    //     }

    //     // Check if the message is a reply to another message
    //     $replyToMessageId = null;
    //     if ($messageId) {
    //         // Check if the original message exists in the chat
    //         $originalMessage = ChatMessage::where('id', $messageId)->where('chat_id', $chatId)->first();

    //         if (!$originalMessage) {
    //             return response()->json(['success' => false, 'error' => 'Original message not found in this chat'], 404);
    //         }

    //         $replyToMessageId = $messageId; // Store the ID of the original message
    //     }

    //     // Send the message (or reply)
    //     $message = ChatMessage::create([
    //         'chat_id' => $chatId,
    //         'sender_id' => $user->id,
    //         'message' => $data['message'],
    //         'attachment_url' => $data['attachment_url'] ?? null,
    //         'status' => 'sent',
    //         'reply_to_message_id' => $replyToMessageId, // Reference the original message if it's a reply
    //     ]);

    //     return response()->json(['success' => true, 'message' => 'Message sent', 'data' => $message]);
    // }

    public function sendMessageBetweenUsers(Request $request, $recipientId)
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Ensure the recipient is not the authenticated user
        if ($user->id == $recipientId) {
            return response()->json(['success' => false, 'error' => 'You cannot send a message to yourself'], 400);
        }

        // Validate the request
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:500',
            'attachment_url' => 'nullable|url|max:255', // Optional attachment (URL)
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'error' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Ensure the recipient exists
        $recipient = User::find($recipientId);
        if (!$recipient) {
            return response()->json(['success' => false, 'error' => 'Recipient not found'], 404);
        }

        // Check if a chat already exists between the sender and recipient
        $chat = Chat::whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->whereHas('participants', function ($query) use ($recipient) {
            $query->where('user_id', $recipient->id);
        })->first();

        // If no chat exists, create one
        if (!$chat) {
            $chat = Chat::create(['created_by' => $user->id]);

            // Add both the sender and recipient as participants
            ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $user->id]);
            ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $recipient->id]);
        }

        // Encrypt the message before saving
        $encryptedMessage = encrypt($data['message']);

        // Send the message
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'sender_id' => $user->id,
            // 'message' => $data['message'],
            'message' => $encryptedMessage, // Store the encrypted message
            'attachment_url' => $data['attachment_url'] ?? null,
            'status' => 'sent',
        ]);

        // Attempt to broadcast the message
        try {
            broadcast(new MessageSent($message))->toOthers();
            $broadcasted = true;
        } catch (\Exception $e) {
            // Handle broadcast error (log or take necessary action)
            Log::error('Broadcast failed: ' . $e->getMessage());
            $broadcasted = false;
        }

        return response()->json(['success' => true, 'message' => 'Message sent', 'data' => $message]);
    }

    // Fetch chat history.
    public function fetchChatHistory($chatId)
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Ensure the user is a participant in the chat
        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isParticipant) {
            return response()->json(['success' => false, 'error' => 'You are not a participant in this chat'], 403);
        }

        // Get the chat messages along with read receipts
        $messages = ChatMessage::where('chat_id', $chatId)
            ->with(['readReceipts' => function ($query) use ($user) {
                $query->where('reader_id', $user->id);
            }])
            ->get();

        return response()->json(['success' => true, 'data' => $messages]);
    }

    // Reply to a message.
    public function replyToMessage(Request $request, $chatId, $messageId)
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Ensure the user is a participant in the chat
        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isParticipant) {
            return response()->json(['success' => false, 'error' => 'You are not a participant in this chat'], 403);
        }

        // Validate the request
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:500',
            'attachment_url' => 'nullable|url|max:255', // Optional attachment (URL)
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'error' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Check if the original message exists
        $originalMessage = ChatMessage::find($messageId);

        if (!$originalMessage || $originalMessage->chat_id !== $chatId) {
            return response()->json(['success' => false, 'error' => 'Original message not found in this chat'], 404);
        }

        // Send the reply
        $replyMessage = ChatMessage::create([
            'chat_id' => $chatId,
            'sender_id' => $user->id,
            'message' => $data['message'],
            'attachment_url' => $data['attachment_url'] ?? null,
            'status' => 'sent',
            'reply_to_message_id' => $messageId, // Reference the original message
        ]);

        return response()->json(['success' => true, 'message' => 'Reply sent', 'data' => $replyMessage]);
    }

    // Mark messages as read.
    public function markMessagesAsRead(Request $request, $chatId)
    {
        // Ensure the user is authenticated
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Check if the user is a participant in the chat
        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isParticipant) {
            return response()->json(['success' => false, 'error' => 'You are not a participant in this chat'], 403);
        }

        // Get all unread messages for the user in this chat
        $unreadMessages = ChatMessage::where('chat_id', $chatId)
            ->where('sender_id', '!=', $user->id) // Only mark messages from other participants as read
            ->where('status', '!=', 'read') // Only select messages that are not marked as 'read'
            ->whereDoesntHave('readReceipts', function ($query) use ($user) {
                $query->where('reader_id', $user->id);
            })
            ->get();

        foreach ($unreadMessages as $message) {
            // Update the message status to 'read'
            $message->status = 'read';
            $message->save();

            // Insert a new read receipt for each unread message
            MessageReadReceipt::create([
                'message_id' => $message->id,
                'reader_id' => $user->id,
                'read_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Messages marked as read']);
    }

    // Delete a message.
    public function deleteMessage($chatId, $messageId)
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Ensure the user is a participant in the chat
        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isParticipant) {
            return response()->json(['success' => false, 'error' => 'You are not a participant in this chat'], 403);
        }

        // Find the message to be deleted
        $message = ChatMessage::where('id', $messageId)->where('chat_id', $chatId)->first();
        if (!$message) {
            return response()->json(['success' => false, 'error' => 'Message not found'], 404);
        }

        // Ensure the message belongs to the authenticated user
        if ($message->sender_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized: You can only delete your own messages'], 403);
        }

        // Soft delete the message
        $message->delete();

        return response()->json(['success' => true, 'message' => 'Message deleted']);
    }

    public function encryptMessagesField()
    {
        // Fetch all messages that are not encrypted
        $messages = ChatMessage::all();

        DB::beginTransaction();
        try {
            foreach ($messages as $message_field) {
                // Encrypt the message with emojis support
                $message_field->message = encrypt($message_field->message);
                $message_field->save();

                Log::info('Encrypted message field for message ID: ' . $message_field->id);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Message fields encrypted successfully']);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error encrypting message fields: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Error encrypting messages: ' . $e->getMessage()
            ]);
        }
    }
}
