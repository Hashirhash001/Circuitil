<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']); // Remove the current unique constraint
            $table->unique(['email', 'deleted_at']); // Add a composite unique constraint
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email', 'deleted_at']); // Drop the composite unique constraint
            $table->unique('email'); // Re-add the original unique constraint
        });
    }

};
