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
        // Table for blocking users
        Schema::create('blocked_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('blocker_id')->comment('User who blocked another user');
            $table->unsignedBigInteger('blocked_id')->comment('User who is blocked');
            $table->timestamps();

            // Foreign keys
            $table->foreign('blocker_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('blocked_id')->references('id')->on('users')->cascadeOnDelete();

            // Ensure a user cannot block another user multiple times
            $table->unique(['blocker_id', 'blocked_id']);
        });

        // Table for reporting users
        Schema::create('reported_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reporter_id')->comment('User who is reporting');
            $table->unsignedBigInteger('reported_id')->nullable()->comment('User being reported');
            $table->unsignedBigInteger('post_id')->nullable()->comment('Post being reported');
            $table->string('reason');
            $table->timestamps();

            // Foreign keys
            $table->foreign('reporter_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reported_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reported_users');
        Schema::dropIfExists('blocked_users');
    }
};
