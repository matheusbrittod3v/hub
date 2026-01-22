<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplicateDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'app:replicate-now 
                            {--full : Force full replication instead of incremental}
                            {--since= : For incremental, replicate records since (e.g., "1 hour ago")}';

    /**
     * The console command description.
     */
    protected $description = 'Replicate data from Write database to Read database using ReplicationService';

    protected \App\Services\Replication\ReplicationService $replicationService;

    public function __construct(\App\Services\Replication\ReplicationService $replicationService)
    {
        parent::__construct();
        $this->replicationService = $replicationService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Starting Database Replication...');
        $this->newLine();

        // Configure connections dynamically
        $this->configureConnections();

        try {
            if ($this->option('full')) {
                $this->info('🚀 Running FULL replication...');
                $stats = $this->replicationService->runFullReplication();
            } else {
                $sinceStr = $this->option('since') ?? '1 hour ago';
                try {
                    $since = new \DateTime($sinceStr);
                } catch (\Exception $e) {
                    $this->error("Invalid date format for --since: $sinceStr");
                    return 1;
                }
                
                $this->info("🚀 Running INCREMENTAL replication (Since: {$since->format('Y-m-d H:i:s')})...");
                $stats = $this->replicationService->runIncrementalReplication($since);
            }

            if ($stats['success']) {
                $this->newLine();
                $this->info('✅ Replication completed successfully!');
                
                $rows = [];
                // Format stats for table display
                foreach (['users', 'albums', 'media_items'] as $table) {
                    if (isset($stats[$table])) {
                        $s = $stats[$table];
                        $rows[] = [
                            $table,
                            $s['processed'] ?? 0,
                            $s['inserted'] ?? 0,
                            $s['updated'] ?? 0,
                            $s['errors'] ?? 0
                        ];
                    }
                }

                $this->table(
                    ['Table', 'Processed', 'Inserted', 'Updated', 'Errors'],
                    $rows
                );
                
                return 0;
            } else {
                $this->error('❌ Replication failed provided success=false in stats.');
                if (isset($stats['error'])) {
                    $this->error('Error: ' . $stats['error']);
                }
                return 1;
            }

        } catch (\Exception $e) {
            $this->error('❌ Replication failed with exception: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Configure write and read connections dynamically
     */
    protected function configureConnections(): void
    {
        $baseConfig = config('database.connections.mysql_content');
        unset($baseConfig['read'], $baseConfig['write'], $baseConfig['sticky']);

        // Write Connection
        if (!config('database.connections.mysql_content_write')) {
            $writeConfig = array_merge($baseConfig, config('database.connections.mysql_content.write') ?? []);
            config(['database.connections.mysql_content_write' => $writeConfig]);
        }

        // Read Connection
        if (!config('database.connections.mysql_content_read')) {
            $readConfig = array_merge($baseConfig, config('database.connections.mysql_content.read') ?? []);
            config(['database.connections.mysql_content_read' => $readConfig]);
        }

        $this->info('📊 Write DB: ' . config('database.connections.mysql_content_write.database'));
        $this->info('📖 Read DB: ' . config('database.connections.mysql_content_read.database'));
    }
}
