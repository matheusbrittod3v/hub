<?php

namespace App\Services\Replication;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ReplicationService - Orchestrates the replication process between write and read databases
 * 
 * This service is responsible for:
 * - Detecting outdated/missing records in the read database
 * - Syncing data from write to read database efficiently
 * - Processing in chunks for optimal performance
 */
class ReplicationService
{
    protected AlbumReplicator $albumReplicator;
    protected MediaItemReplicator $mediaItemReplicator;
    protected UserReplicator $userReplicator;
    
    protected int $chunkSize = 500;
    protected array $stats = [];

    public function __construct(
        AlbumReplicator $albumReplicator,
        MediaItemReplicator $mediaItemReplicator,
        UserReplicator $userReplicator
    ) {
        $this->albumReplicator = $albumReplicator;
        $this->mediaItemReplicator = $mediaItemReplicator;
        $this->userReplicator = $userReplicator;
        
        $this->resetStats();
    }

    /**
     * Run full replication process for all tables
     */
    public function runFullReplication(): array
    {
        $this->resetStats();
        
        Log::info('[ReplicationService] Starting full replication...');
        
        try {
            // 1. Replicate Users first (foreign key dependency)
            $this->stats['users'] = $this->userReplicator->replicate($this->chunkSize);
            
            // 2. Replicate Albums
            $this->stats['albums'] = $this->albumReplicator->replicate($this->chunkSize);
            
            // 3. Replicate Media Items
            $this->stats['media_items'] = $this->mediaItemReplicator->replicate($this->chunkSize);
            
            $this->stats['success'] = true;
            $this->stats['completed_at'] = now()->toDateTimeString();
            
            Log::info('[ReplicationService] Full replication completed successfully.', $this->stats);
            
        } catch (\Exception $e) {
            $this->stats['success'] = false;
            $this->stats['error'] = $e->getMessage();
            
            Log::error('[ReplicationService] Replication failed: ' . $e->getMessage(), [
                'exception' => $e,
                'stats' => $this->stats,
            ]);
        }
        
        return $this->stats;
    }

    /**
     * Run incremental replication (only new/updated records)
     */
    public function runIncrementalReplication(\DateTimeInterface $since = null): array
    {
        $this->resetStats();
        
        $since = $since ?? now()->subHours(1);
        
        Log::info('[ReplicationService] Starting incremental replication since: ' . $since->format('Y-m-d H:i:s'));
        
        try {
            // 1. Replicate Users
            $this->stats['users'] = $this->userReplicator->replicateIncremental($since, $this->chunkSize);
            
            // 2. Replicate Albums
            $this->stats['albums'] = $this->albumReplicator->replicateIncremental($since, $this->chunkSize);
            
            // 3. Replicate Media Items
            $this->stats['media_items'] = $this->mediaItemReplicator->replicateIncremental($since, $this->chunkSize);
            
            $this->stats['success'] = true;
            $this->stats['completed_at'] = now()->toDateTimeString();
            
            Log::info('[ReplicationService] Incremental replication completed.', $this->stats);
            
        } catch (\Exception $e) {
            $this->stats['success'] = false;
            $this->stats['error'] = $e->getMessage();
            
            Log::error('[ReplicationService] Incremental replication failed: ' . $e->getMessage());
        }
        
        return $this->stats;
    }

    /**
     * Verify data integrity between databases
     */
    public function verifyIntegrity(): array
    {
        $report = [
            'checked_at' => now()->toDateTimeString(),
            'tables' => [],
        ];
        
        $tables = ['users', 'albums', 'media_items', 'album_likes', 'album_views', 'reports'];
        
        $writeConn = config()->has('database.connections.mysql_content_write') 
            ? 'mysql_content_write' 
            : 'mysql_content';
            
        $readConn = config()->has('database.connections.mysql_content_read') 
            ? 'mysql_content_read' 
            : 'mysql_content';
        
        foreach ($tables as $table) {
            $writeCount = DB::connection($writeConn)
                ->table($table)
                ->count();
                
            $readCount = DB::connection($readConn)
                ->table($table)
                ->count();
            
            $report['tables'][$table] = [
                'write_count' => $writeCount,
                'read_count' => $readCount,
                'difference' => $writeCount - $readCount,
                'synced' => $writeCount === $readCount,
            ];
        }
        
        $report['all_synced'] = collect($report['tables'])->every(fn($t) => $t['synced']);
        
        return $report;
    }

    /**
     * Set chunk size for batch processing
     */
    public function setChunkSize(int $size): self
    {
        $this->chunkSize = max(100, min(5000, $size));
        return $this;
    }

    /**
     * Get current stats
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    protected function resetStats(): void
    {
        $this->stats = [
            'started_at' => now()->toDateTimeString(),
            'success' => false,
            'users' => [],
            'albums' => [],
            'media_items' => [],
        ];
    }
}
