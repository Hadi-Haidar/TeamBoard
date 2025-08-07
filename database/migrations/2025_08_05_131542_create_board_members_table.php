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
        Schema::create('board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('invited_by')->constrained()->onDelete('cascade');
            $table->string('email')->nullable();
            $table->enum('role',['owner','member','viewer']);
            $table->enum('status',['pending','active','declined']);
            $table->string('invitation_token')->nullable();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamps();
            
            // Add indexes for better performance
            $table->index(['user_id', 'board_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_members');
    }
};
