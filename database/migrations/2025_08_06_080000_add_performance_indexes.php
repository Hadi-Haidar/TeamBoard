<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🚀 PERFORMANCE OPTIMIZATION: Add only missing indexes with correct column names
     */
    public function up()
    {
        // Tasks table - EXISTING: assigned_to+status, due_date
        // ADD: missing indexes
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->index(['list_id', 'status']);
                $table->index('created_at');
                $table->index('created_by');
            });
        }
        
        // Activities table - NO existing indexes
        // ADD: all performance indexes
        if (Schema::hasTable('activities')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->index(['user_id', 'created_at']);
                $table->index(['board_id', 'created_at']);
                $table->index('created_at');
            });
        }
        
        // Board members table - EXISTING: user_id+board_id
        // ADD: missing indexes
        if (Schema::hasTable('board_members')) {
            Schema::table('board_members', function (Blueprint $table) {
                $table->index('board_id');
                $table->index(['status', 'user_id']);
                $table->index('invitation_token');
            });
        }
        
        // Boards table - NO existing indexes
        // ADD: all performance indexes
        if (Schema::hasTable('boards')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->index(['owner_id', 'updated_at']);
                $table->index('updated_at');
                $table->index('created_at');
            });
        }
        
        // Notifications table - NO existing indexes
        // ADD: all performance indexes
        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index(['user_id', 'read_at']);
                $table->index(['user_id', 'created_at']);
                $table->index(['user_id', 'type']);
                $table->index('created_at');
            });
        }
        
        // Comments table - EXISTING: task_id+created_at
        // ADD: only missing indexes
        if (Schema::hasTable('comments')) {
            Schema::table('comments', function (Blueprint $table) {
                $table->index('user_id');
                $table->index('created_at');
            });
        }
        
        // Attachments table - NO existing indexes
        // ADD: performance indexes with CORRECT column names (user_id not uploaded_by)
        if (Schema::hasTable('attachments')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->index('task_id');
                $table->index('user_id'); // CORRECT: user_id (not uploaded_by)
                $table->index('created_at');
            });
        }
        
        // Lists table - NO existing indexes
        // ADD: all performance indexes
        if (Schema::hasTable('lists')) {
            Schema::table('lists', function (Blueprint $table) {
                $table->index(['board_id', 'position']);
                $table->index('board_id');
            });
        }
    }

    /**
     * Reverse the migrations
     */
    public function down()
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropIndex(['list_id', 'status']);
                $table->dropIndex(['created_at']);
                $table->dropIndex(['created_by']);
            });
        }
        
        if (Schema::hasTable('activities')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'created_at']);
                $table->dropIndex(['board_id', 'created_at']);
                $table->dropIndex(['created_at']);
            });
        }
        
        if (Schema::hasTable('board_members')) {
            Schema::table('board_members', function (Blueprint $table) {
                $table->dropIndex(['board_id']);
                $table->dropIndex(['status', 'user_id']);
                $table->dropIndex(['invitation_token']);
            });
        }
        
        if (Schema::hasTable('boards')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->dropIndex(['owner_id', 'updated_at']);
                $table->dropIndex(['updated_at']);
                $table->dropIndex(['created_at']);
            });
        }
        
        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'read_at']);
                $table->dropIndex(['user_id', 'created_at']);
                $table->dropIndex(['user_id', 'type']);
                $table->dropIndex(['created_at']);
            });
        }
        
        if (Schema::hasTable('comments')) {
            Schema::table('comments', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
                $table->dropIndex(['created_at']);
            });
        }
        
        if (Schema::hasTable('attachments')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->dropIndex(['task_id']);
                $table->dropIndex(['user_id']);
                $table->dropIndex(['created_at']);
            });
        }
        
        if (Schema::hasTable('lists')) {
            Schema::table('lists', function (Blueprint $table) {
                $table->dropIndex(['board_id', 'position']);
                $table->dropIndex(['board_id']);
            });
        }
    }
};
