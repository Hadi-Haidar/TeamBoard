<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BoardMember;
use Illuminate\Support\Facades\Log;

class CleanupBoardInvitations extends Command
{
    protected $signature = 'invitations:cleanup {--days=15 : Days to keep old invitations}';
    protected $description = 'Clean up old declined or expired board invitations';

    public function handle()
    {
        $days = (int) $this->option('days');
        
        $this->info("🧹 Cleaning invitations older than {$days} days...");
        
        try {
            // Use the model method we already have!
            $deleted = BoardMember::cleanupOldInvitations($days);
            
            if ($deleted === 0) {
                $this->info("✅ No old invitations found.");
                return self::SUCCESS;
            }
            
            $this->info("✅ Deleted {$deleted} old invitations.");
            
            Log::info('Invitations cleanup completed', [
                'deleted_count' => $deleted,
                'days_threshold' => $days,
            ]);
            
            return self::SUCCESS;
            
        } catch (\Throwable $e) {
            $this->error("❌ Cleanup failed: " . $e->getMessage());
            Log::error('Invitations cleanup failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }
}
