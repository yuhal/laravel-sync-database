<?php

namespace Yuhal\SyncDatabase;

use Illuminate\Support\ServiceProvider;

class SyncDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('command.devDatabaseCommand', function ($app) {
            return new SyncDatabaseCommand($app['migrator']);
        });

        $this->commands([
            'command.devDatabaseCommand',
        ]);
    }
}
