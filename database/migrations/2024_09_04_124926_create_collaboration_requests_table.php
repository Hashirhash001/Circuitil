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
        Schema::create('collaboration_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('collaboration_id')->comment('collaborations: id');
            $table->foreign('collaboration_id')->references('id')->on('collaborations')->cascadeOnDelete();
            $table->unsignedBigInteger('influencer_id')->comment('influencers: id');
            $table->foreign('influencer_id')->references('id')->on('influencers')->cascadeOnDelete();
            $table->tinyInteger('status')->nullable()->comment('1 = pending, 2 = interested, 3 = invited, 4 = accepted, 5 = completed, 6 = rejected');

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collaboration_requests');
    }
};
