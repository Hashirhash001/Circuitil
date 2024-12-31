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
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 50); // App version, e.g., '1.0.0'
            $table->string('platform', 20); // Platform, e.g., 'android', 'ios', 'web'
            $table->text('release_notes')->nullable(); // Release notes or changelog
            $table->timestamp('released_at')->nullable(); // Release date
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
