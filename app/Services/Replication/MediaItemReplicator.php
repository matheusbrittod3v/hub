<?php

namespace App\Services\Replication;

/**
 * MediaItemReplicator - Handles replication of media_items table
 */
class MediaItemReplicator extends BaseReplicator
{
    protected string $table = 'media_items';
    protected string $primaryKey = 'id';
    protected string $timestampColumn = 'created_at'; // media_items uses created_at
    protected array $ignoredColumns = ['locked_by', 'locked_at'];
}
