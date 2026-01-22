<?php

namespace App\Services\Replication;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Base class for table replicators
 * Provides common functionality for syncing data between write and read databases
 */
abstract class BaseReplicator
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected string $timestampColumn = 'updated_at';
    
    /**
     * Full replication - sync all records
     */
    public function replicate(int $chunkSize = 500): array
    {
        $stats = [
            'table' => $this->table,
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
        
        Log::info("[{$this->table}] Starting full replication...");
        
        // Get all IDs from write database
        $writeIds = $this->getWriteConnection()
            ->table($this->table)
            ->pluck($this->primaryKey)
            ->toArray();
        
        // Get all IDs from read database
        $readIds = $this->getReadConnection()
            ->table($this->table)
            ->pluck($this->primaryKey)
            ->toArray();
        
        // Find missing records in read database
        $missingIds = array_diff($writeIds, $readIds);
        
        if (!empty($missingIds)) {
            $stats = $this->insertMissingRecords($missingIds, $chunkSize, $stats);
        }
        
        // Update existing records (check for updates)
        $existingIds = array_intersect($writeIds, $readIds);
        if (!empty($existingIds)) {
            $stats = $this->updateExistingRecords($existingIds, $chunkSize, $stats);
        }
        
        Log::info("[{$this->table}] Replication completed.", $stats);
        
        return $stats;
    }

    /**
     * Incremental replication - only sync records updated since given date
     */
    public function replicateIncremental(\DateTimeInterface $since, int $chunkSize = 500): array
    {
        $stats = [
            'table' => $this->table,
            'since' => $since->format('Y-m-d H:i:s'),
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
        
        Log::info("[{$this->table}] Starting incremental replication since {$since->format('Y-m-d H:i:s')}...");
        
        // Get recently updated records from write database
        $recentIds = $this->getWriteConnection()
            ->table($this->table)
            ->where($this->timestampColumn, '>=', $since)
            ->pluck($this->primaryKey)
            ->toArray();
        
        if (empty($recentIds)) {
            Log::info("[{$this->table}] No records to replicate.");
            return $stats;
        }
        
        // Get existing IDs in read database
        $existingReadIds = $this->getReadConnection()
            ->table($this->table)
            ->whereIn($this->primaryKey, $recentIds)
            ->pluck($this->primaryKey)
            ->toArray();
        
        // Find new records (not in read database)
        $newIds = array_diff($recentIds, $existingReadIds);
        if (!empty($newIds)) {
            $stats = $this->insertMissingRecords($newIds, $chunkSize, $stats);
        }
        
        // Update existing records
        $updateIds = array_intersect($recentIds, $existingReadIds);
        if (!empty($updateIds)) {
            $stats = $this->updateExistingRecords($updateIds, $chunkSize, $stats);
        }
        
        Log::info("[{$this->table}] Incremental replication completed.", $stats);
        
        return $stats;
    }

    protected array $ignoredColumns = [];

    /**
     * Filter record data to remove ignored columns
     */
    protected function filterRecordData(array $record): array
    {
        if (empty($this->ignoredColumns)) {
            return $record;
        }
        
        return array_diff_key($record, array_flip($this->ignoredColumns));
    }

    /**
     * Insert missing records in chunks
     */
    protected function insertMissingRecords(array $ids, int $chunkSize, array $stats): array
    {
        $chunks = array_chunk($ids, $chunkSize);
        
        foreach ($chunks as $chunkIds) {
            try {
                // Fetch records from write database
                $records = $this->getWriteConnection()
                    ->table($this->table)
                    ->whereIn($this->primaryKey, $chunkIds)
                    ->get()
                    ->map(fn($r) => $this->filterRecordData((array) $r))
                    ->toArray();
                
                if (!empty($records)) {
                    // Insert into read database
                    $this->getReadConnection()
                        ->table($this->table)
                        ->insert($records);
                    
                    $stats['inserted'] += count($records);
                }
                
                $stats['processed'] += count($chunkIds);
                
            } catch (\Exception $e) {
                Log::error("[{$this->table}] Error inserting records: " . $e->getMessage(), [
                    'chunk_ids' => $chunkIds,
                ]);
                $stats['errors'] += count($chunkIds);
            }
        }
        
        return $stats;
    }

    /**
     * Update existing records if they differ
     */
    protected function updateExistingRecords(array $ids, int $chunkSize, array $stats): array
    {
        $chunks = array_chunk($ids, $chunkSize);
        
        foreach ($chunks as $chunkIds) {
            try {
                // Fetch records from write database
                $writeRecords = $this->getWriteConnection()
                    ->table($this->table)
                    ->whereIn($this->primaryKey, $chunkIds)
                    ->get()
                    ->keyBy($this->primaryKey);
                
                // Fetch corresponding records from read database
                $readRecords = $this->getReadConnection()
                    ->table($this->table)
                    ->whereIn($this->primaryKey, $chunkIds)
                    ->get()
                    ->keyBy($this->primaryKey);
                
                foreach ($writeRecords as $id => $writeRecord) {
                    $readRecord = $readRecords->get($id);
                    $filteredWriteRecord = $this->filterRecordData((array) $writeRecord);
                    
                    if (!$readRecord) {
                        // Record disappeared from read, insert it
                        $this->getReadConnection()
                            ->table($this->table)
                            ->insert($filteredWriteRecord);
                        $stats['inserted']++;
                        continue;
                    }
                    
                    // Compare records (using filtered data for comparison too, ideally, or just raw)
                    // Logic: We want to update if significant fields changed. 
                    // If ignored fields changed, we probably don't care, so comparing filtered makes sense.
                    if ($this->recordsDiffer($filteredWriteRecord, $readRecord)) {
                        // Update read database
                        $this->getReadConnection()
                            ->table($this->table)
                            ->where($this->primaryKey, $id)
                            ->update($filteredWriteRecord);
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                    
                    $stats['processed']++;
                }
                
            } catch (\Exception $e) {
                Log::error("[{$this->table}] Error updating records: " . $e->getMessage());
                $stats['errors'] += count($chunkIds);
            }
        }
        
        return $stats;
    }

    /**
     * Compare two records to see if they differ
     */
    protected function recordsDiffer($writeRecord, $readRecord): bool
    {
        $writeArray = (array) $writeRecord;
        $readArray = (array) $readRecord;
        
        // Compare all fields
        foreach ($writeArray as $key => $value) {
            if (!isset($readArray[$key]) || $readArray[$key] !== $value) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get write database connection
     */
    protected function getWriteConnection()
    {
        // Use the write connection configured by the command if available
        if (config()->has('database.connections.mysql_content_write')) {
            return DB::connection('mysql_content_write');
        }
        
        return DB::connection('mysql_content');
    }

    /**
     * Get read database connection (Target for replication)
     */
    protected function getReadConnection()
    {
        // Use the read connection configured by the command if available
        // This usually points to the replica/target database
        if (config()->has('database.connections.mysql_content_read')) {
            return DB::connection('mysql_content_read');
        }

        // Fallback: use default connection (note: useWritePdo is removed in newer Laravel)
        return DB::connection('mysql_content');
    }
}
