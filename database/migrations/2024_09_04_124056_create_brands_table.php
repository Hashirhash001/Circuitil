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
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('users: id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->json('category')->nullable();
            $table->string('about')->nullable();
            $table->string('profile_photo')->nullable();
            $table->json('social_media_links')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
