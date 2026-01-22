<?php

namespace App\Jobs;

use App\Services\Replication\ReplicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ReplicateDatabaseJob - Queued job for database replication
 * 
 * This job handles the synchronization of data from the write database
 * to the read database (replica). It can run in two modes:
 * - Full replication: Syncs all records
 * - Incremental replication: Syncs only records updated since a given time
 */
class ReplicateDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 3600; // 1 hour

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 1;

    /**
     * Replication mode: 'full' or 'incremental'
     */
    protected string $mode;

    /**
     * For incremental mode, replicate records since this datetime
     */
    protected ?\DateTimeInterface $since;

    /**
     * Chunk size for batch processing
     */
    protected int $chunkSize;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $mode = 'incremental',
        ?\DateTimeInterface $since = null,
        int $chunkSize = 500
    ) {
        $this->mode = $mode;
        $this->since = $since;
        $this->chunkSize = $chunkSize;
    }

    /**
     * Execute the job.
     */
    public function handle(ReplicationService $replicationService): void
    {
        Log::info('[ReplicateDatabaseJob] Job started.', [
            'mode' => $this->mode,
            'since' => $this->since?->format('Y-m-d H:i:s'),
            'chunk_size' => $this->chunkSize,
        ]);

        $replicationService->setChunkSize($this->chunkSize);

        try {
            if ($this->mode === 'full') {
                $stats = $replicationService->runFullReplication();
            } else {
                $stats = $replicationService->runIncrementalReplication($this->since);
            }

            Log::info('[ReplicateDatabaseJob] Job completed successfully.', $stats);

        } catch (\Exception $e) {
            Log::error('[ReplicateDatabaseJob] Job failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to mark job as failed
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::critical('[ReplicateDatabaseJob] Job failed permanently.', [
            'mode' => $this->mode,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['replication', 'database', $this->mode];
    }

    /**
     * Static factory for full replication
     */
    public static function full(int $chunkSize = 500): self
    {
        return new self('full', null, $chunkSize);
    }

    /**
     * Static factory for incremental replication
     */
    public static function incremental(?\DateTimeInterface $since = null, int $chunkSize = 500): self
    {
        return new self('incremental', $since ?? now()->subHour(), $chunkSize);
    }
}
