<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create the 'chats' table
        Schema::create('chats', function (Blueprint $table) {
            $table->id(); // Primary key

            // No need to specify brand_id and influencer_id, use a general 'created_by' field
            $table->unsignedBigInteger('created_by')->comment('users: id');
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();

            // Soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();
        });

        // Create the 'chat_participants' table (for storing all participants in a chat)
        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id(); // Primary key

            // Foreign key for Chat
            $table->unsignedBigInteger('chat_id')->comment('chats: id');
            $table->foreign('chat_id')->references('id')->on('chats')->cascadeOnDelete();

            // Foreign key for User (either brand or influencer)
            $table->unsignedBigInteger('user_id')->comment('users: id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();
        });

        // Create the 'chat_messages' table
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id(); // Primary key

            // Foreign key for Chat
            $table->unsignedBigInteger('chat_id')->comment('chats: id');
            $table->foreign('chat_id')->references('id')->on('chats')->cascadeOnDelete();

            // Sender type inferred from 'users' table, so no need for 'sender_type'
            $table->unsignedBigInteger('sender_id')->comment('users: id');
            $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();

            // Message content
            $table->text('message')->comment('The message content');

            // Message status (sent, delivered, read)
            $table->enum('status', ['sent', 'delivered', 'read'])->default('sent')->comment('Status of the message');

            $table->unsignedBigInteger('reply_to_message_id')->nullable()->comment('chat_messages: id');
            $table->foreign('reply_to_message_id')->references('id')->on('chat_messages')->cascadeOnDelete();

            // Attachment URL (optional)
            $table->string('attachment_url', 255)->nullable()->comment('Optional URL for attachments like images or files');

            // Soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();
        });

        // Create the 'message_read_receipts' table
        Schema::create('message_read_receipts', function (Blueprint $table) {
            $table->id(); // Primary key

            // Foreign key for Message
            $table->unsignedBigInteger('message_id')->comment('chat_messages: id');
            $table->foreign('message_id')->references('id')->on('chat_messages')->cascadeOnDelete();

            // Foreign key for User (Reader)
            $table->unsignedBigInteger('reader_id')->comment('users: id');
            $table->foreign('reader_id')->references('id')->on('users')->cascadeOnDelete();

            // Timestamp when the message was read
            $table->timestamp('read_at')->nullable()->comment('Timestamp when the message was read');

            // Soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the 'message_read_receipts' table first, because it references 'chat_messages'
        Schema::dropIfExists('message_read_receipts');

        // Drop the 'chat_messages' table next, because it references 'chats'
        Schema::dropIfExists('chat_messages');

        // Drop the 'chat_participants' table
        Schema::dropIfExists('chat_participants');

        // Drop the 'chats' table last
        Schema::dropIfExists('chats');
    }
};
