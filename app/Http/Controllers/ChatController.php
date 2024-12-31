<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Chat;
use App\Models\User;
use App\Models\Brand;
use App\Models\FcmToken;
use App\Models\Influencer;
use App\Events\MessageSent;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use App\Models\ChatParticipant;
use App\Models\MessageReadReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    // send a message in a chat
    // public function sendMessageBetweenUsers(Request $request, $recipientId)
    // {
    //     // Check if the user is authenticated
    //     if (!Auth::check()) {
    //         return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
    //     }

    //     $user = Auth::user();

    //     // Ensure the recipient is not the authenticated user
    //     if ($user->id == $recipientId) {
    //         return response()->json(['success' => false, 'error' => 'You cannot send a message to yourself'], 400);
    //     }

    //     // Validate the request
    //     $validator = Validator::make($request->all(), [
    //         'message' => 'required|string|max:500',
    //         'attachment_url' => 'nullable|url|max:255', // Optional attachment (URL)
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['success' => false, 'error' => $validator->errors()], 422);
    //     }

    //     $data = $validator->validated();

    //     // Ensure the recipient exists
    //     $recipient = User::find($recipientId);
    //     if (!$recipient) {
    //         return response()->json(['success' => false, 'error' => 'Recipient not found'], 404);
    //     }

    //     // Check if a chat already exists between the sender and recipient
    //     $chat = Chat::whereHas('participants', function ($query) use ($user) {
    //         $query->where('user_id', $user->id);
    //     })->whereHas('participants', function ($query) use ($recipient) {
    //         $query->where('user_id', $recipient->id);
    //     })->first();

    //     // If no chat exists, create one
    //     if (!$chat) {
    //         $chat = Chat::create(['created_by' => $user->id]);

    //         // Add both the sender and recipient as participants
    //         ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $user->id]);
    //         ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $recipient->id]);
    //     }

    //     // Encrypt the message before saving
    //     $encryptedMessage = encrypt($data['message']);

    //     // Send the message
    //     $message = ChatMessage::create([
    //         'chat_id' => $chat->id,
    //         'sender_id' => $user->id,
    //         // 'message' => $data['message'],
    //         'message' => $encryptedMessage,
    //         'attachment_url' => $data['attachment_url'] ?? null,
    //         'status' => 'sent',
    //     ]);

    //     // Attempt to broadcast the message
    //     try {
    //         broadcast(new MessageSent($message))->toOthers();
    //         $broadcasted = true;
    //     } catch (\Exception $e) {
    //         // Handle broadcast error (log or take necessary action)
    //         Log::error('Broadcast failed: ' . $e->getMessage());
    //         $broadcasted = false;
    //     }

    //     return response()->json(['success' => true, 'message' => 'Message sent', 'data' => $message]);
    // }

    protected $pushNotificationService;

    public function __construct(PushNotificationService $pushNotificationService)
    {
        $this->pushNotificationService = $pushNotificationService;
    }

    // send a message after chatId is created
    public function sendMessage(Request $request, $chatId, $messageId = null)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Determine the role (e.g., 'influencer' or 'brand') and fetch name and profile photo
        if ($user->role === 'influencer' && $user->influencer) {
            $senderName = $user->influencer->name;
            $profilePhoto = $user->influencer->profile_photo;
        } elseif ($user->role === 'brand' && $user->brand) {
            $senderName = $user->brand->name;
            $profilePhoto = $user->brand->profile_photo;
        } else {
            return response()->json(['success' => false, 'error' => 'User role or profile information not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:500',
            'attachment_url' => 'nullable|url|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'error' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isParticipant) {
            return response()->json(['success' => false, 'error' => 'You are not a participant in this chat'], 403);
        }

        $replyToMessageId = null;
        if ($messageId) {
            $originalMessage = ChatMessage::where('id', $messageId)->where('chat_id', $chatId)->first();
            if (!$originalMessage) {
                return response()->json(['success' => false, 'error' => 'Original message not found in this chat'], 404);
            }
            $replyToMessageId = $messageId;
        }

        $encryptedMessage = encrypt($data['message']);

        $message = ChatMessage::create([
            'chat_id' => $chatId,
            'sender_id' => $user->id,
            'message' => $encryptedMessage,
            'attachment_url' => $data['attachment_url'] ?? null,
            'status' => 'sent',
            'reply_to_message_id' => $replyToMessageId,
        ]);

        $recipients = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id')
            ->toArray();

        $fcmTokens = FcmToken::whereIn('user_id', $recipients)->pluck('fcm_token')->toArray();

        if (!empty($fcmTokens)) {
            $notificationData = [
                'title' => $senderName . ' sent you a message',
                'body' => decrypt($encryptedMessage),
                'icon' => $profilePhoto,
                'click_action' => url("/chat/{$chatId}/messages/{$message->id}"),
                'data' => [
                    'message' => decrypt($encryptedMessage),
                    'sender_name' => $senderName,
                    'sender_profile_photo' => $profilePhoto,
                    'chat_id' => $chatId,
                    'message_id' => $message->id,
                ]
            ];

            $this->pushNotificationService->sendPushNotification($fcmTokens, $notificationData);
            Log::info('Push Notification sent successfully', ['fcmTokens' => $fcmTokens, 'notificationData' => $notificationData]);
        } else {
            Log::warning('No FCM tokens found for the recipients');
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

        $authUser = Auth::user();

        // Ensure the user is a participant in the chat
        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $authUser->id)
            ->exists();

        if (!$isParticipant) {
            return response()->json(['success' => false, 'error' => 'You are not a participant in this chat'], 403);
        }

        // Fetch the other participant(s) in the chat
        $otherParticipants = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', '!=', $authUser->id)
            ->with('user.influencer', 'user.brand') // Load related influencer/brand details
            ->get()
            ->map(function ($participant) {
                $user = $participant->user;

                // Dynamically add only the relevant id and name based on role
                return [
                    'user_id' => $user->id,
                    'role' => $user->role,
                    'name' => $user->role === 'influencer' ? $user->influencer->name ?? null : $user->brand->name ?? null,
                    'id' => $user->role === 'influencer'
                        ? $user->influencer->id ?? null
                        : $user->brand->id ?? null, // Add only influencer_id or brand_id
                ];
            });

        // Get the chat messages along with sender details and read receipts
        $messages = ChatMessage::where('chat_id', $chatId)
            ->with([
                'sender.influencer', // Load sender's influencer details
                'sender.brand',      // Load sender's brand details
                'readReceipts' => function ($query) use ($authUser) {
                    $query->where('reader_id', $authUser->id);
                },
            ])
            ->orderBy('created_at', 'asc') // Order messages chronologically
            ->get();

        // Format messages to include relevant sender details
        $formattedMessages = $messages->map(function ($message) {
            $sender = $message->sender;

            // Dynamically add only the relevant id and name based on role
            return [
                'id' => $message->id,
                'chat_id' => $message->chat_id,
                'sender_id' => $sender->id,
                'sender_name' => $sender->role === 'influencer' ? $sender->influencer->name ?? null : $sender->brand->name ?? null,
                'role' => $sender->role,
                'id' => $sender->role === 'influencer'
                    ? $sender->influencer->id ?? null
                    : $sender->brand->id ?? null, // Add only influencer_id or brand_id
                'message' => $message->message,
                'status' => $message->status,
                'reply_to_message_id' => $message->reply_to_message_id,
                'created_at' => $message->created_at->toDateTimeString(),
                'read_receipts' => $message->readReceipts,
            ];
        });

        return response()->json([
            'success' => true,
            'other_participants' => $otherParticipants,
            'messages' => $formattedMessages,
        ]);
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

    public function getUserChats(Request $request)
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $user = Auth::user();

        // Fetch all chat IDs where the user is a participant
        $chatIds = ChatParticipant::where('user_id', $user->id)->pluck('chat_id');

        if ($chatIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No chats found for this user',
                'data' => [],
            ]);
        }

        // Fetch chats with participants and last message, excluding chats with no participants
        $chats = Chat::whereIn('id', $chatIds)
            ->whereHas('participants') // Ensure only chats with participants are included
            ->with([
                'participants.user' => function ($query) use ($user) {
                    $query->where('id', '!=', $user->id) // Exclude the authenticated user
                        ->select('id', 'name', 'email', 'role'); // Include role
                },
                'lastMessage' => function ($query) {
                    $query->select('chat_messages.id', 'chat_messages.chat_id', 'chat_messages.message', 'chat_messages.created_at');
                },
            ])
            ->get();

            // dd($chats);

        // Format the response
        $formattedChats = $chats->map(function ($chat) {
            // Filter out chats with no participants
            $participants = $chat->participants->map(function ($participant) {
                $user = $participant->user;

                // Handle null users
                if (!$user) {
                    return null;
                }

                // Fetch role-specific details
                $details = null;
                if ($user->role === 'influencer') {
                    $details = Influencer::where('user_id', $user->id)->first();
                } elseif ($user->role === 'brand') {
                    $details = Brand::where('user_id', $user->id)->first();
                }

                return [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'details' => $details,
                ];
            })->filter(); // Remove null participants

            // Only include chats with participants
            if ($participants->isEmpty()) {
                return null;
            }

            // $lastMessage = $chat->lastMessage;

            $lastMessage = DB::table('chat_messages')
                ->where('chat_id', $chat->id)
                ->orderBy('created_at', 'desc')
                ->first();

            return [
                'id' => $chat->id,
                'created_at' => $chat->created_at,
                'updated_at' => $chat->updated_at,
                'participants' => $participants,
                'lastMessage' => $lastMessage
                    ? [
                        'id' => $lastMessage->id,
                        'message' => decrypt($lastMessage->message),
                        'status' => $lastMessage->status,
                        'created_at' => $lastMessage->created_at,
                    ]
                    : null,
            ];
        })->filter(); // Remove null chats (empty participants)

        return response()->json([
            'success' => true,
            'message' => 'Chats retrieved successfully',
            'data' => $formattedChats,
        ]);
    }

}
