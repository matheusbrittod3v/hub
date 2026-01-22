<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use PDO;
use PDOException;

class DumpAllDatabases extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'db:dump-all 
                            {--path= : Custom path for dump files (default: storage/app/dumps)}
                            {--compress : Compress dump files with gzip}
                            {--retries=5 : Number of connection retries}
                            {--timeout=30 : Connection timeout in seconds}';

    /**
     * The console command description.
     */
    protected $description = 'Dump all 3 databases (principal, escrita, leitura) to SQL files using PHP/PDO';

    /**
     * Database configurations
     */
    protected array $databases = [];

    /**
     * Max retries for operations
     */
    protected int $maxRetries = 5;

    /**
     * Connection timeout
     */
    protected int $timeout = 30;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->maxRetries = (int) $this->option('retries');
        $this->timeout = (int) $this->option('timeout');

        $this->info('🗄️  Starting database dumps (PHP/PDO method)...');
        $this->line("   ⚙️  Retries: {$this->maxRetries} | Timeout: {$this->timeout}s");
        $this->newLine();

        // Parse database configurations from .env
        $this->databases = $this->parseDatabaseConfigs();

        if (empty($this->databases)) {
            $this->error('❌ No database configurations found!');

            return Command::FAILURE;
        }

        // Get output path
        $basePath = $this->option('path') ?: storage_path('app/dumps');
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $dumpPath = $basePath.'/'.$timestamp;

        // Create directory if it doesn't exist
        if (! is_dir($dumpPath)) {
            mkdir($dumpPath, 0755, true);
        }

        $this->info("📁 Dump directory: {$dumpPath}");
        $this->newLine();

        $compress = $this->option('compress');
        $success = true;
        $totalStartTime = microtime(true);

        foreach ($this->databases as $name => $config) {
            $this->info("📊 Dumping database: {$name}");
            $this->line("   📍 Host: {$config['host']}:{$config['port']}");
            $this->line("   📍 Database: {$config['database']}");
            $this->line("   📍 User: {$config['username']}");

            $filename = "{$name}_{$timestamp}.sql";
            $filepath = "{$dumpPath}/{$filename}";

            $startTime = microtime(true);
            $result = $this->dumpDatabaseWithPdo($config, $filepath, $compress);
            $elapsed = round(microtime(true) - $startTime, 2);

            if ($result) {
                $finalPath = $compress ? "{$filepath}.gz" : $filepath;
                $size = $this->formatFileSize(filesize($finalPath));
                $this->info("   ✅ Success! File: {$filename}".($compress ? '.gz' : '')." ({$size}) in {$elapsed}s");
            } else {
                $this->error("   ❌ Failed to dump {$name}");
                $success = false;
            }

            $this->newLine();
        }

        $totalElapsed = round(microtime(true) - $totalStartTime, 2);

        if ($success) {
            $this->info('🎉 All databases dumped successfully!');
            $this->info("📂 Files saved to: {$dumpPath}");
            $this->info("⏱️  Total time: {$totalElapsed}s");
        } else {
            $this->error('⚠️ Some databases failed to dump. Check errors above.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Parse database configurations from environment variables
     */
    protected function parseDatabaseConfigs(): array
    {
        $databases = [];

        // Principal database
        $principalUrl = env('DB_URL');
        if ($principalUrl) {
            $databases['principal'] = $this->parseDbUrl($principalUrl);
        }

        // Escrita database (Content Write)
        $escritaUrl = env('DB_URL_CONTENT_WRITE');
        if ($escritaUrl) {
            $databases['escrita'] = $this->parseDbUrl($escritaUrl);
        }

        // Leitura database (Content Read)
        $leituraUrl = env('DB_URL_CONTENT_READ');
        if ($leituraUrl) {
            $databases['leitura'] = $this->parseDbUrl($leituraUrl);
        }

        return $databases;
    }

    /**
     * Parse a database URL into components
     */
    protected function parseDbUrl(string $url): array
    {
        $parsed = parse_url($url);

        return [
            'host' => $parsed['host'] ?? 'localhost',
            'port' => $parsed['port'] ?? 3306,
            'username' => $parsed['user'] ?? 'root',
            'password' => $parsed['pass'] ?? '',
            'database' => ltrim($parsed['path'] ?? '', '/'),
        ];
    }

    /**
     * Create PDO connection with retry logic
     */
    protected function createConnection(array $config, int $attempt = 1): ?PDO
    {
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";

            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => $this->timeout,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Important for stability
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ]);

            // Set additional timeouts
            $pdo->exec('SET SESSION wait_timeout = 28800');
            $pdo->exec('SET SESSION interactive_timeout = 28800');
            $pdo->exec('SET SESSION net_read_timeout = 300');
            $pdo->exec('SET SESSION net_write_timeout = 600');

            return $pdo;

        } catch (PDOException $e) {
            if ($attempt < $this->maxRetries) {
                $waitTime = min(pow(2, $attempt), 30); // Exponential backoff, max 30s
                $this->warn("   ⚠️  Connection attempt {$attempt} failed, retrying in {$waitTime}s...");
                $this->line('      Error: '.$e->getMessage());
                sleep($waitTime);

                return $this->createConnection($config, $attempt + 1);
            }

            throw $e;
        }
    }

    /**
     * Execute query with retry logic
     */
    protected function executeWithRetry(PDO $pdo, string $query, array $config, int $maxRetries = 3): ?\PDOStatement
    {
        $attempt = 1;
        $lastError = null;

        while ($attempt <= $maxRetries) {
            try {
                $stmt = $pdo->query($query);

                return $stmt;
            } catch (PDOException $e) {
                $lastError = $e;

                // Check if it's a connection error that might be recoverable
                $code = $e->getCode();
                $isConnectionError = in_array($code, ['HY000', '2006', '2013', '2014'])
                    || stripos($e->getMessage(), 'server has gone away') !== false
                    || stripos($e->getMessage(), 'Lost connection') !== false;

                if ($isConnectionError && $attempt < $maxRetries) {
                    $waitTime = min(pow(2, $attempt), 10);
                    $this->warn("   ⚠️  Query failed (attempt {$attempt}), reconnecting in {$waitTime}s...");
                    sleep($waitTime);

                    // Try to reconnect
                    try {
                        $pdo = $this->createConnection($config);
                    } catch (PDOException $reconnectException) {
                        // Continue to next attempt
                    }

                    $attempt++;

                    continue;
                }

                throw $e;
            }
        }

        throw $lastError;
    }

    /**
     * Dump a single database using PDO (no mysqldump required)
     */
    protected function dumpDatabaseWithPdo(array $config, string $filepath, bool $compress = false): bool
    {
        $pdo = null;
        $handle = null;

        try {
            // Connect with retry logic
            $this->line('   🔗 Connecting to database...');
            $pdo = $this->createConnection($config);
            $this->line('   ✓ Connected successfully');

            // Open file for writing
            $handle = fopen($filepath, 'w');
            if (! $handle) {
                $this->error("   ❌ Could not open file for writing: {$filepath}");

                return false;
            }

            // Write header
            $header = $this->generateHeader($config['database']);
            fwrite($handle, $header);

            // Get list of tables with retry
            $tables = $this->getTablesWithRetry($pdo, $config);
            $this->line('   📋 Found '.count($tables).' tables');

            if (empty($tables)) {
                $this->warn('   ⚠️  No tables found in database');
                fwrite($handle, "-- No tables found in database\n");
                fclose($handle);

                return true;
            }

            $progressBar = $this->output->createProgressBar(count($tables));
            $progressBar->setFormat('   %current%/%max% [%bar%] %percent:3s%% %message%');
            $progressBar->start();

            $failedTables = [];

            foreach ($tables as $table) {
                $progressBar->setMessage($table);

                try {
                    // Reconnect for each table to ensure fresh connection
                    if ($pdo === null) {
                        $pdo = $this->createConnection($config);
                    }

                    // Write table structure
                    $createTable = $this->getCreateTableWithRetry($pdo, $table, $config);
                    fwrite($handle, "\n-- --------------------------------------------------------\n");
                    fwrite($handle, "-- Table structure for table `{$table}`\n");
                    fwrite($handle, "-- --------------------------------------------------------\n\n");
                    fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($handle, $createTable.";\n\n");

                    // Write table data
                    $this->dumpTableDataSafe($pdo, $table, $handle, $config);

                } catch (PDOException $e) {
                    $failedTables[] = $table;
                    fwrite($handle, "-- ERROR dumping table `{$table}`: {$e->getMessage()}\n\n");

                    // Try to reconnect for next table
                    try {
                        $pdo = $this->createConnection($config);
                    } catch (PDOException $reconnectException) {
                        $pdo = null;
                    }
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();

            if (! empty($failedTables)) {
                $this->warn('   ⚠️  Some tables failed: '.implode(', ', $failedTables));
            }

            // Write footer
            $footer = $this->generateFooter();
            fwrite($handle, $footer);

            fclose($handle);
            $handle = null;

            // Compress if requested
            if ($compress) {
                $this->line('   🗜️  Compressing...');
                $this->compressFile($filepath);
            }

            return empty($failedTables);

        } catch (PDOException $e) {
            $this->error('   ❌ Database error: '.$e->getMessage());

            return false;
        } catch (\Exception $e) {
            $this->error('   ❌ Error: '.$e->getMessage());

            return false;
        } finally {
            if ($handle) {
                fclose($handle);
            }
            $pdo = null;
        }
    }

    /**
     * Get tables with retry logic
     */
    protected function getTablesWithRetry(PDO $pdo, array $config): array
    {
        $attempt = 1;

        while ($attempt <= $this->maxRetries) {
            try {
                $database = $config['database'];
                $stmt = $pdo->query("SHOW TABLES FROM `{$database}`");
                $tables = [];

                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tables[] = $row[0];
                }

                // Consume all results
                $stmt->closeCursor();

                return $tables;
            } catch (PDOException $e) {
                if ($attempt < $this->maxRetries) {
                    $waitTime = pow(2, $attempt);
                    $this->warn("   ⚠️  Failed to get tables (attempt {$attempt}), retrying in {$waitTime}s...");
                    sleep($waitTime);

                    try {
                        $pdo = $this->createConnection($config);
                    } catch (PDOException $reconnectException) {
                        // Continue
                    }

                    $attempt++;

                    continue;
                }
                throw $e;
            }
        }

        return [];
    }

    /**
     * Get CREATE TABLE with retry logic
     */
    protected function getCreateTableWithRetry(PDO $pdo, string $table, array $config): string
    {
        $attempt = 1;

        while ($attempt <= $this->maxRetries) {
            try {
                $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $stmt->closeCursor();

                return $row[1] ?? '';
            } catch (PDOException $e) {
                if ($attempt < $this->maxRetries) {
                    $waitTime = pow(2, $attempt);
                    sleep($waitTime);
                    $attempt++;

                    continue;
                }
                throw $e;
            }
        }

        return '';
    }

    /**
     * Dump table data safely with proper cursor handling
     */
    protected function dumpTableDataSafe(PDO $pdo, string $table, $handle, array $config): void
    {
        // Get row count first
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
        $rowCount = (int) $countStmt->fetchColumn();
        $countStmt->closeCursor();

        if ($rowCount === 0) {
            fwrite($handle, "-- Table `{$table}` is empty\n\n");

            return;
        }

        fwrite($handle, "-- Dumping data for table `{$table}` ({$rowCount} rows)\n");
        fwrite($handle, "LOCK TABLES `{$table}` WRITE;\n");
        fwrite($handle, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n");

        // Fetch data in batches to avoid memory issues
        $batchSize = 500; // Smaller batch for stability
        $offset = 0;
        $retryCount = 0;
        $maxBatchRetries = 3;

        while ($offset < $rowCount) {
            try {
                // Use a new query for each batch
                $stmt = $pdo->prepare("SELECT * FROM `{$table}` LIMIT :limit OFFSET :offset");
                $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                if (empty($rows)) {
                    break;
                }

                $columns = array_keys($rows[0]);
                $columnList = '`'.implode('`, `', $columns).'`';

                $values = [];
                foreach ($rows as $row) {
                    $rowValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $rowValues[] = 'NULL';
                        } elseif (is_numeric($value) && ! is_string($value)) {
                            $rowValues[] = $value;
                        } else {
                            $rowValues[] = $pdo->quote($value);
                        }
                    }
                    $values[] = '('.implode(', ', $rowValues).')';
                }

                if (! empty($values)) {
                    // Write in chunks to avoid huge INSERT statements
                    $chunkSize = 100;
                    $chunks = array_chunk($values, $chunkSize);

                    foreach ($chunks as $chunk) {
                        fwrite($handle, "INSERT INTO `{$table}` ({$columnList}) VALUES\n");
                        fwrite($handle, implode(",\n", $chunk).";\n");
                    }
                }

                $offset += $batchSize;
                $retryCount = 0; // Reset retry count on success

            } catch (PDOException $e) {
                $retryCount++;

                if ($retryCount >= $maxBatchRetries) {
                    fwrite($handle, "-- ERROR at offset {$offset}: {$e->getMessage()}\n");
                    break;
                }

                // Wait and try to reconnect
                sleep(pow(2, $retryCount));

                try {
                    $pdo = $this->createConnection($config);
                } catch (PDOException $reconnectException) {
                    fwrite($handle, "-- ERROR reconnecting: {$reconnectException->getMessage()}\n");
                    break;
                }
            }
        }

        fwrite($handle, "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n");
        fwrite($handle, "UNLOCK TABLES;\n\n");
    }

    /**
     * Generate SQL dump header
     */
    protected function generateHeader(string $database): string
    {
        $timestamp = Carbon::now()->toIso8601String();

        return <<<SQL
-- ========================================================
-- SQL Dump generated by Laravel DumpAllDatabases Command
-- Database: {$database}
-- Generated at: {$timestamp}
-- PHP Version: PHP_VERSION
-- ========================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

SQL;
    }

    /**
     * Generate SQL dump footer
     */
    protected function generateFooter(): string
    {
        return <<<'SQL'

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- Dump completed successfully
SQL;
    }

    /**
     * Compress a file using gzip
     */
    protected function compressFile(string $filepath): bool
    {
        $gzFilepath = $filepath.'.gz';

        $fp = fopen($filepath, 'rb');
        $zp = gzopen($gzFilepath, 'wb9');

        if (! $fp || ! $zp) {
            return false;
        }

        while (! feof($fp)) {
            gzwrite($zp, fread($fp, 1024 * 512)); // 512KB chunks
        }

        fclose($fp);
        gzclose($zp);

        // Remove original file
        unlink($filepath);

        return true;
    }

    /**
     * Format file size in human-readable format
     */
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
