<?php

namespace App\Providers;

use App\Services\Replication\AlbumReplicator;
use App\Services\Replication\MediaItemReplicator;
use App\Services\Replication\ReplicationService;
use App\Services\Replication\UserReplicator;
use Illuminate\Support\ServiceProvider;

class ReplicationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register replicators as singletons
        $this->app->singleton(AlbumReplicator::class);
        $this->app->singleton(MediaItemReplicator::class);
        $this->app->singleton(UserReplicator::class);

        // Register main replication service
        $this->app->singleton(ReplicationService::class, function ($app) {
            return new ReplicationService(
                $app->make(AlbumReplicator::class),
                $app->make(MediaItemReplicator::class),
                $app->make(UserReplicator::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
