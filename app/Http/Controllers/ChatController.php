<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\Chat;
use App\Models\User;
use App\Models\Brand;
use App\Models\FcmToken;
use App\Models\BlockUser;
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

        // Determine sender details based on role
        if ($user->role === 'influencer' && $user->influencer) {
            $senderName = $user->influencer->name;
            $profilePhoto = $user->influencer->profile_photo;
        } elseif ($user->role === 'brand' && $user->brand) {
            $senderName = $user->brand->name;
            $profilePhoto = $user->brand->profile_photo;
        } else {
            return response()->json(['success' => false, 'error' => 'User role or profile information not found'], 404);
        }

        // Validate request input
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:500',
            'attachment_url' => 'nullable|url|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'error' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Check if user is a participant in the chat
        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isParticipant) {
            return response()->json(['success' => false, 'error' => 'You are not a participant in this chat'], 403);
        }

        // Check if replying to a message
        $replyToMessageId = null;
        if ($messageId) {
            $originalMessage = ChatMessage::where('id', $messageId)->where('chat_id', $chatId)->first();
            if (!$originalMessage) {
                return response()->json(['success' => false, 'error' => 'Original message not found in this chat'], 404);
            }
            $replyToMessageId = $messageId;
        }

        // Encrypt the message
        $encryptedMessage = encrypt($data['message']);

        // Create the chat message
        $message = ChatMessage::create([
            'chat_id' => $chatId,
            'sender_id' => $user->id,
            'message' => $encryptedMessage,
            'attachment_url' => $data['attachment_url'] ?? null,
            'status' => 'sent',
            'reply_to_message_id' => $replyToMessageId,
            'created_at' => Carbon::now('Asia/Kolkata'),
            'updated_at' => Carbon::now('Asia/Kolkata'),
        ]);

        // Format message response
        $broadcastMessage = [
            'id' => (int) $message->id,
            'chat_id' => (int) $message->chat_id,
            'sender_id' => (int) $user->id,
            'sender_name' => $senderName,
            'role' => $user->role,
            'profile_photo' => $profilePhoto,
            'message' => $data['message'],
            'status' => $message->status,
            'reply_to_message_id' => $replyToMessageId ? (int) $replyToMessageId : null,
            'created_at' => $message->created_at->toDateTimeString(),
            'read_receipts' => [], // Placeholder for read receipts
        ];

        // Broadcast the message to chat participants
        broadcast(new MessageSent($broadcastMessage));

        // Get all chat participants except the sender
        $recipients = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id')
            ->toArray();

        // Exclude blocked users from receiving notifications
        $blockedUsers = BlockUser::where('blocked_by', $user->id)
            ->orWhere('blocked_user', $user->id)
            ->pluck('blocked_user')
            ->toArray();

        $allowedRecipients = array_diff($recipients, $blockedUsers);

        if (!empty($allowedRecipients)) {
            $fcmTokens = FcmToken::whereIn('user_id', $allowedRecipients)->pluck('fcm_token')->toArray();

            if (!empty($fcmTokens)) {
                // Image base URL for profile photos
                $imageBaseUrl = 'https://apptest.zenerom.com/storage/';

                $notificationData = [
                    'title' => $senderName . ' sent a message',
                    'body' => decrypt($encryptedMessage),
                    'icon' => $imageBaseUrl . $profilePhoto,
                    'click_action' => url("/chat/{$chatId}/messages/{$message->id}"),
                    'data' => [
                        'message' => decrypt($encryptedMessage),
                        'sender_name' => $senderName,
                        'sender_profile_photo' => $profilePhoto,
                        'chat_id' => $chatId,
                        'message_id' => $message->id,
                    ]
                ];

                // Send push notification
                $this->pushNotificationService->sendPushNotification($fcmTokens, $notificationData);
                Log::info('Push Notification sent successfully', ['fcmTokens' => $fcmTokens, 'notificationData' => $notificationData]);
            } else {
                Log::warning('No FCM tokens found for the recipients');
            }
        } else {
            Log::info('All recipients are blocked, no notifications sent.');
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
                // 'id' => $sender->role === 'influencer'
                //     ? $sender->influencer->id ?? null
                //     : $sender->brand->id ?? null, // Add only influencer_id or brand_id
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

        // Get blocked user IDs
        $blockedUserIds = BlockUser::where('blocker_id', $user->id)
            ->orWhere('blocked_id', $user->id)
            ->pluck('blocked_id')
            ->merge(BlockUser::where('blocked_id', $user->id)->pluck('blocker_id'))
            ->unique()
            ->toArray();

        // Fetch chats with participants and last message, excluding blocked users
        $chats = Chat::whereIn('id', $chatIds)
            ->whereHas('participants.user', function ($query) use ($blockedUserIds, $user) {
                $query->whereNotIn('id', array_merge([$user->id], $blockedUserIds));
            })
            ->with([
                'participants.user' => function ($query) use ($user, $blockedUserIds) {
                    $query->where('id', '!=', $user->id) // Exclude the authenticated user
                        ->whereNotIn('id', $blockedUserIds) // Exclude blocked users
                        ->select('id', 'name', 'email', 'role'); // Include role
                },
                'lastMessage' => function ($query) {
                    $query->select('chat_messages.id', 'chat_messages.chat_id', 'chat_messages.message', 'chat_messages.created_at');
                },
            ])
            ->get();

        // Format the response
        $formattedChats = $chats->map(function ($chat) use ($user, $blockedUserIds) {
            // Filter out blocked participants
            $participants = $chat->participants->map(function ($participant) use ($blockedUserIds) {
                $user = $participant->user;

                // Handle null users or blocked users
                if (!$user || in_array($user->id, $blockedUserIds)) {
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

            // Fetch last message
            $lastMessage = DB::table('chat_messages')
                ->where('chat_id', $chat->id)
                ->orderBy('created_at', 'desc')
                ->first();

            // Calculate unread messages for the authenticated user
            $unreadCount = ChatMessage::where('chat_id', $chat->id)
                ->where('sender_id', '!=', $user->id) // Messages not sent by the authenticated user
                ->whereDoesntHave('readReceipts', function ($query) use ($user) {
                    $query->where('reader_id', $user->id);
                })
                ->count();

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
                'unread_count' => $unreadCount,
            ];
        })->filter(); // Remove null chats (empty participants)

        return response()->json([
            'success' => true,
            'message' => 'Chats retrieved successfully',
            'data' => $formattedChats,
        ]);
    }
}
