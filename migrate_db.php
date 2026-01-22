<?php

/**
 * ULTRA PERFORMANCE DATABASE MIGRATOR
 * Migrates data from Old Content DB to New Leitura DB
 */

// --- CONFIGURATION ---

// SOURCE (OLD)
$src_host = '72.60.7.121';
$src_port = 3306;
$src_db = 'hubp2';
$src_user = 'hubp2';
$src_pass = 'dfghjklkjhgf345';

// DESTINATION (NEW) - Using URL format
$dst_url = 'mysql://leitura:HQ2JabMtBqfJExbfiZp8@62.171.184.88:3301/leitura';

$chunk_size = 2000; // Number of rows per batch

// --- SCRIPT START ---

set_time_limit(0);
ini_set('memory_limit', '512M');

echo "Connecting to Source DB ($src_host)...".PHP_EOL;
try {
    $dsn_src = "mysql:host=$src_host;port=$src_port;dbname=$src_db;charset=utf8mb4";
    $pdo_src = new PDO($dsn_src, $src_user, $src_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // Important for large datasets
    ]);
} catch (Exception $e) {
    exit('Error connecting to Source: '.$e->getMessage().PHP_EOL);
}

// Parse destination URL
$parsed = parse_url($dst_url);
$dst_host = $parsed['host'];
$dst_port = $parsed['port'];
$dst_user = $parsed['user'];
$dst_pass = $parsed['pass'];
$dst_db = ltrim($parsed['path'], '/');

echo "Connecting to Destination DB ($dst_host:$dst_port)...".PHP_EOL;
try {
    $dsn_dst = "mysql:host=$dst_host;port=$dst_port;dbname=$dst_db;charset=utf8mb4";
    $pdo_dst = new PDO($dsn_dst, $dst_user, $dst_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    exit('Error connecting to Destination: '.$e->getMessage().PHP_EOL);
}

// Disable foreign key checks
echo 'Disabling foreign key checks on Target...'.PHP_EOL;
$pdo_dst->exec('SET FOREIGN_KEY_CHECKS = 0;');
$pdo_dst->exec('SET UNIQUE_CHECKS = 0;');
$pdo_dst->exec('SET AUTOCOMMIT = 0;');

// Get tables - fetch all at once to close the statement
$tables = [];
$stmt = $pdo_src->query('SHOW TABLES');
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
$stmt->closeCursor();
$stmt = null;

echo 'Found '.count($tables).' tables to migrate.'.PHP_EOL;

// Create a second buffered connection for metadata queries
$pdo_src_meta = new PDO($dsn_src, $src_user, $src_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
]);

foreach ($tables as $table) {
    echo "Processing table: $table".PHP_EOL;

    // Get create table statement (using buffered connection)
    $stmt = $pdo_src_meta->query("SHOW CREATE TABLE `$table`");
    $create_row = $stmt->fetch();
    $create_sql = $create_row['Create Table'];
    $stmt->closeCursor();

    // Create table on destination
    $pdo_dst->exec("DROP TABLE IF EXISTS `$table`");
    $pdo_dst->exec($create_sql);

    // Get row count for progress
    $count_stmt = $pdo_src_meta->query("SELECT COUNT(*) FROM `$table`");
    $total_rows = $count_stmt->fetchColumn();
    $count_stmt->closeCursor();
    echo "  Total rows to migrate: $total_rows".PHP_EOL;

    if ($total_rows == 0) {
        echo '  Skipping empty table.'.PHP_EOL;

        continue;
    }

    // Stream data (using unbuffered connection)
    $stmt_data = $pdo_src->query("SELECT * FROM `$table`");

    $columns = [];
    $first_row = true;
    $batch = [];
    $total_count = 0;

    while ($row = $stmt_data->fetch()) {
        if ($first_row) {
            $columns = array_keys($row);
            $col_list = '`'.implode('`, `', $columns).'`';
            $placeholders = '('.implode(', ', array_fill(0, count($columns), '?')).')';
            $first_row = false;
        }

        $batch[] = array_values($row);
        $total_count++;

        if (count($batch) >= $chunk_size) {
            insertBatch($pdo_dst, $table, $col_list, $placeholders, $batch);
            $batch = [];
            $pct = round(($total_count / $total_rows) * 100, 1);
            echo "  $total_count / $total_rows rows ($pct%)...".PHP_EOL;
        }
    }

    // Close cursor before next table
    $stmt_data->closeCursor();
    $stmt_data = null;

    // Insert remaining
    if (! empty($batch)) {
        insertBatch($pdo_dst, $table, $col_list, $placeholders, $batch);
        echo "  $total_count rows total.".PHP_EOL;
    }

    $pdo_dst->commit(); // Commit per table for safety and speed
    $pdo_dst->beginTransaction(); // Start new transaction for next table
}

// Re-enable checks
echo 'Re-enabling checks...'.PHP_EOL;
$pdo_dst->exec('SET FOREIGN_KEY_CHECKS = 1;');
$pdo_dst->exec('SET UNIQUE_CHECKS = 1;');
$pdo_dst->exec('COMMIT;');

echo 'MIGRATION COMPLETE!'.PHP_EOL;

function insertBatch($pdo, $table, $col_list, $placeholders, $batch)
{
    $all_placeholders = implode(', ', array_fill(0, count($batch), $placeholders));
    $sql = "INSERT INTO `$table` ($col_list) VALUES $all_placeholders";

    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($batch as $row) {
        foreach ($row as $val) {
            $params[] = $val;
        }
    }
    $stmt->execute($params);
}
