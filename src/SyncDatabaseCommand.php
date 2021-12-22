<?php

namespace Yuhal\SyncDatabase;

use Spatie\Regex\Regex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema as LaravelSchema;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Console\Migrations\BaseCommand;

class SyncDatabaseCommand extends BaseCommand
{
    /**
     * The migrator instance.
     *
     * @var \Illuminate\Database\Migrations\Migrator
     */
    protected $migrator;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'database:sync';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Developer databases syncing tool';


    protected $schemas;

    public $files;

    /**
     * Create a new database command instance.
     *
     * @param  \Illuminate\Database\Migrations\Migrator  $migrator
     * @return void
     */
    public function __construct(Migrator $migrator)
    {
        parent::__construct();

        $this->schemas = Collection::make();
        $this->migrator = $migrator;
    }

    /**
     *
     */
    public function handle()
    {
        $this->files = $this->migrator->getMigrationFiles($this->getMigrationPaths());
        foreach ($this->files as $file) {
            $content = file_get_contents($file);
            $content = str_replace(
                '$table->softDeletes()',
                '$table->timestamp(\'deleted_at\',0)',
                $content
            );
            $this->processDatabase($content);
        }
        $this->syncedOrNot();
    }

    protected function processDatabase($content)
    {
        $schemas = $this->getAllSchemas($content);
        foreach ($schemas as $schema) {
            $schema = new Schema($schema, $this);
            $schema->process();
            $this->schemas->push($schema);
        }
    }

    protected function getAllSchemas($content)
    {   
        return Regex::matchAll('/Schema::create\s*\((.*)\,.*{(.*)}\);/sU', $content)->results();
    }

    protected function syncedOrNot()
    {
        return !$this->schemas->pluck('synced')->contains(true) && $this->info('Nothing to sync.');
    }
}
