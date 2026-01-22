<?php

namespace App\Services\Replication;

/**
 * AlbumReplicator - Handles replication of albums table
 */
class AlbumReplicator extends BaseReplicator
{
    protected string $table = 'albums';
    protected string $primaryKey = 'id';
    protected string $timestampColumn = 'updated_at';
}
