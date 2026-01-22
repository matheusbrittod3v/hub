<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use PDO;
use PDOException;

class ImportAllDatabases extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'db:import-all 
                            {--path= : Custom path for dump files (default: storage/app/dumps)}
                            {--folder= : Specific folder timestamp to import (e.g., 2026-01-22_21-29-38)}
                            {--retries=5 : Number of connection retries}
                            {--timeout=60 : Connection timeout in seconds}
                            {--batch=50 : Number of statements to execute per batch}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Import the most recent SQL dumps into their respective databases (principal, escrita, leitura) - HIGH PERFORMANCE';

    protected array $databases = [];
    protected int $maxRetries = 5;
    protected int $timeout = 60;
    protected int $batchSize = 50;

    public function handle(): int
    {
        $this->maxRetries = (int) $this->option('retries');
        $this->timeout = (int) $this->option('timeout');
        $this->batchSize = (int) $this->option('batch');

        $this->info('📥 Starting HIGH PERFORMANCE database import...');
        $this->line("   ⚙️  Retries: {$this->maxRetries} | Timeout: {$this->timeout}s | Batch: {$this->batchSize}");
        $this->newLine();

        $this->databases = $this->parseDatabaseConfigs();

        if (empty($this->databases)) {
            $this->error('❌ No database configurations found!');
            return Command::FAILURE;
        }

        $basePath = $this->option('path') ?: storage_path('app/dumps');
        $folder = $this->option('folder');

        if (! is_dir($basePath)) {
            $this->error("❌ Dump directory not found: {$basePath}");
            return Command::FAILURE;
        }

        $importPath = $this->findImportFolder($basePath, $folder);

        if (! $importPath) {
            $this->error('❌ No dump folder found to import!');
            return Command::FAILURE;
        }

        $this->info("📁 Import from: {$importPath}");
        $this->newLine();

        $files = $this->findDumpFiles($importPath);

        if (empty($files)) {
            $this->error('❌ No SQL dump files found in the folder!');
            return Command::FAILURE;
        }

        $this->info('📋 Files to import:');
        foreach ($files as $dbName => $filePath) {
            $size = $this->formatFileSize(filesize($filePath));
            $this->line("   • {$dbName}: " . basename($filePath) . " ({$size})");
        }
        $this->newLine();

        if (! $this->option('force')) {
            $this->warn('⚠️  WARNING: This will DROP all existing tables and import the dump data!');
            if (! $this->confirm('Do you want to continue?', false)) {
                $this->info('❌ Import cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->newLine();
        $success = true;
        $totalStartTime = microtime(true);

        foreach ($files as $dbName => $filePath) {
            if (! isset($this->databases[$dbName])) {
                $this->warn("   ⚠️  Skipping {$dbName}: No database configuration found");
                continue;
            }

            $config = $this->databases[$dbName];
            $this->info("📊 Importing to database: {$dbName}");
            $this->line("   📍 Host: {$config['host']}:{$config['port']}");
            $this->line("   📍 Database: {$config['database']}");
            $this->line("   📍 File: " . basename($filePath));

            $startTime = microtime(true);
            $result = $this->importDatabaseFast($config, $filePath);
            $elapsed = round(microtime(true) - $startTime, 2);

            if ($result) {
                $this->info("   ✅ Success! Imported in {$elapsed}s");
            } else {
                $this->error("   ❌ Failed to import {$dbName}");
                $success = false;
            }

            $this->newLine();
        }

        $totalElapsed = round(microtime(true) - $totalStartTime, 2);

        if ($success) {
            $this->info('🎉 All databases imported successfully!');
            $this->info("⏱️  Total time: {$totalElapsed}s");
        } else {
            $this->error('⚠️ Some databases failed to import. Check errors above.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function parseDatabaseConfigs(): array
    {
        $databases = [];

        $principalUrl = env('DB_URL');
        if ($principalUrl) {
            $databases['principal'] = $this->parseDbUrl($principalUrl);
        }

        $escritaUrl = env('DB_URL_CONTENT_WRITE');
        if ($escritaUrl) {
            $databases['escrita'] = $this->parseDbUrl($escritaUrl);
        }

        $leituraUrl = env('DB_URL_CONTENT_READ');
        if ($leituraUrl) {
            $databases['leitura'] = $this->parseDbUrl($leituraUrl);
        }

        return $databases;
    }

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

    protected function findImportFolder(string $basePath, ?string $specificFolder): ?string
    {
        if ($specificFolder) {
            $path = $basePath . '/' . $specificFolder;
            if (is_dir($path)) {
                return $path;
            }
            $this->error("   Specified folder not found: {$specificFolder}");
            return null;
        }

        $folders = glob($basePath . '/*', GLOB_ONLYDIR);
        if (empty($folders)) {
            return null;
        }

        usort($folders, fn($a, $b) => basename($b) <=> basename($a));
        return $folders[0];
    }

    protected function findDumpFiles(string $importPath): array
    {
        $files = [];
        $dbNames = ['principal', 'escrita', 'leitura'];

        foreach ($dbNames as $dbName) {
            $patterns = [
                $importPath . "/{$dbName}_*.sql",
                $importPath . "/{$dbName}_*.sql.gz",
            ];

            foreach ($patterns as $pattern) {
                $matches = glob($pattern);
                if (! empty($matches)) {
                    usort($matches, fn($a, $b) => filemtime($b) <=> filemtime($a));
                    $files[$dbName] = $matches[0];
                    break;
                }
            }
        }

        return $files;
    }

    protected function createConnection(array $config, int $attempt = 1): ?PDO
    {
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";

            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => $this->timeout,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ]);

            // Performance optimizations (user-level only, no SUPER privileges needed)
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec('SET UNIQUE_CHECKS = 0');
            $pdo->exec('SET AUTOCOMMIT = 0');

            return $pdo;

        } catch (PDOException $e) {
            if ($attempt < $this->maxRetries) {
                $waitTime = min(pow(2, $attempt), 30);
                $this->warn("   ⚠️  Connection attempt {$attempt} failed, retrying in {$waitTime}s...");
                $this->line('      Error: ' . $e->getMessage());
                sleep($waitTime);
                return $this->createConnection($config, $attempt + 1);
            }
            throw $e;
        }
    }

    /**
     * HIGH PERFORMANCE import using streaming and batching
     */
    protected function importDatabaseFast(array $config, string $filePath): bool
    {
        try {
            $this->line('   🔗 Connecting to database...');
            $pdo = $this->createConnection($config);
            $this->line('   ✓ Connected successfully');

            $isCompressed = str_ends_with($filePath, '.gz');
            $fileSize = filesize($filePath);
            $this->line('   📄 File size: ' . $this->formatFileSize($fileSize));

            // Open file stream
            if ($isCompressed) {
                $this->line('   📦 Opening compressed file...');
                $handle = gzopen($filePath, 'rb');
            } else {
                $handle = fopen($filePath, 'rb');
            }

            if (!$handle) {
                $this->error('   ❌ Could not open file!');
                return false;
            }

            $this->line('   🚀 Streaming import started...');

            $executed = 0;
            $errors = 0;
            $currentStatement = '';
            $inString = false;
            $stringChar = '';
            $bytesRead = 0;
            $lastProgress = 0;
            $batchStatements = [];

            // Start transaction for batch
            $pdo->beginTransaction();

            while (!($isCompressed ? gzeof($handle) : feof($handle))) {
                // Read chunk
                $chunk = $isCompressed ? gzread($handle, 65536) : fread($handle, 65536); // 64KB chunks
                $bytesRead += strlen($chunk);

                // Show progress every 5%
                $progress = $fileSize > 0 ? floor(($bytesRead / $fileSize) * 100) : 0;
                if ($progress >= $lastProgress + 5) {
                    $this->output->write("\r   📊 Progress: {$progress}% | Executed: {$executed} | Errors: {$errors}");
                    $lastProgress = $progress;
                }

                // Process chunk character by character
                $chunkLen = strlen($chunk);
                for ($i = 0; $i < $chunkLen; $i++) {
                    $char = $chunk[$i];
                    $nextChar = ($i + 1 < $chunkLen) ? $chunk[$i + 1] : '';

                    // Skip comments at start of line
                    if (!$inString && $char === '-' && $nextChar === '-') {
                        // Find end of line
                        $eol = strpos($chunk, "\n", $i);
                        if ($eol !== false) {
                            $i = $eol;
                            continue;
                        }
                    }

                    // Skip # comments
                    if (!$inString && $char === '#') {
                        $eol = strpos($chunk, "\n", $i);
                        if ($eol !== false) {
                            $i = $eol;
                            continue;
                        }
                    }

                    // Handle strings
                    if (!$inString && ($char === "'" || $char === '"')) {
                        $inString = true;
                        $stringChar = $char;
                        $currentStatement .= $char;
                        continue;
                    }

                    if ($inString) {
                        $currentStatement .= $char;
                        if ($char === '\\' && ($nextChar === $stringChar || $nextChar === '\\')) {
                            $currentStatement .= $nextChar;
                            $i++;
                            continue;
                        }
                        if ($char === $stringChar) {
                            if ($nextChar === $stringChar) {
                                $currentStatement .= $nextChar;
                                $i++;
                                continue;
                            }
                            $inString = false;
                        }
                        continue;
                    }

                    // Statement delimiter
                    if ($char === ';') {
                        $currentStatement .= $char;
                        $stmt = trim($currentStatement);
                        
                        if (!empty($stmt) && $stmt !== ';') {
                            $batchStatements[] = $stmt;
                            
                            // Execute batch when full
                            if (count($batchStatements) >= $this->batchSize) {
                                $result = $this->executeBatch($pdo, $batchStatements);
                                $executed += $result['executed'];
                                $errors += $result['errors'];
                                $batchStatements = [];
                            }
                        }
                        
                        $currentStatement = '';
                        continue;
                    }

                    $currentStatement .= $char;
                }
            }

            // Execute remaining statements
            if (!empty($batchStatements)) {
                $result = $this->executeBatch($pdo, $batchStatements);
                $executed += $result['executed'];
                $errors += $result['errors'];
            }

            // Handle last statement without semicolon
            $stmt = trim($currentStatement);
            if (!empty($stmt) && $stmt !== ';') {
                try {
                    $pdo->exec($stmt);
                    $executed++;
                } catch (PDOException $e) {
                    $errors++;
                }
            }

            // Commit final transaction
            $pdo->commit();

            // Re-enable checks
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->exec('SET UNIQUE_CHECKS = 1');
            $pdo->exec('SET AUTOCOMMIT = 1');

            $isCompressed ? gzclose($handle) : fclose($handle);

            $this->newLine();
            $this->line("   📈 Executed: {$executed} | Errors: {$errors}");

            return true;

        } catch (PDOException $e) {
            $this->newLine();
            $this->error('   ❌ Database error: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('   ❌ Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute a batch of statements efficiently
     */
    protected function executeBatch(PDO $pdo, array $statements): array
    {
        $executed = 0;
        $errors = 0;

        foreach ($statements as $stmt) {
            try {
                // Skip empty or comment-only statements
                $cleanStmt = trim($stmt);
                if (empty($cleanStmt) || 
                    str_starts_with($cleanStmt, '--') || 
                    str_starts_with($cleanStmt, '#') ||
                    str_starts_with($cleanStmt, '/*')) {
                    continue;
                }

                $pdo->exec($stmt);
                $executed++;
            } catch (PDOException $e) {
                $errors++;
                // Only log critical errors
                $msg = $e->getMessage();
                if (stripos($msg, 'duplicate') === false && 
                    stripos($msg, 'already exists') === false &&
                    stripos($msg, 'Duplicate') === false) {
                    \Log::debug("Import SQL Error: " . substr($msg, 0, 200));
                }
            }
        }

        // Commit batch and start new transaction
        try {
            $pdo->commit();
            $pdo->beginTransaction();
        } catch (PDOException $e) {
            // Transaction might not be active
            try {
                $pdo->beginTransaction();
            } catch (PDOException $e2) {
                // Ignore
            }
        }

        return ['executed' => $executed, 'errors' => $errors];
    }

    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
