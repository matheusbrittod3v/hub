<?php

namespace App\Services\Replication;

/**
 * UserReplicator - Handles replication of users table
 */
class UserReplicator extends BaseReplicator
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';
    protected string $timestampColumn = 'updated_at';
}
