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
        Schema::create('collaborations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->comment('brands: id');
            $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('image')->nullable();
            $table->string('category')->nullable();
            $table->string('description')->nullable();
            $table->double('amount', 10, 2)->nullable();
            $table->string('end_date')->nullable();

            // Add these new columns
            $table->tinyInteger('status')->default(1)->comment('1 = pending, 4 = accepted, 5 = completed');
            $table->unsignedBigInteger('accepted_user_id')->nullable()->comment('Accepted influencer: users:id');

            // Foreign key for accepted influencer
            $table->foreign('accepted_user_id')->references('id')->on('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collaborations');
    }
};
