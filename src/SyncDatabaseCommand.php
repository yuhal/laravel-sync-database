<?php

namespace Yuhal\SyncDatabase;

use Spatie\Regex\Regex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
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

    protected $ignore = ['migrations', 'telescope_entries'];

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

        $this->unDeletedMigrates()->each(function ($table) {
            $this->info("Delete migration file for <fg=black;bg=white>{$table}</>");
            File::delete($this->nameToPath($table));
        });

        // foreach ($this->files as $file) {
        //     $content = file_get_contents($file);
        //     $this->processDatabase($content, $file);
        // }

        $this->unCreatedMigrates()->each(function ($table) {
            echo $this->nameToPath($table);exit;
            $this->info("Create migration file for <fg=black;bg=white>{$table}</>");
            Artisan::call("migrate:generate {$table} --no-interaction");
            $file = basename($migrationFilepath, '.php');
            $this->migrator->repository->log($file, $this->nextBatchNumber);
        })->isEmpty() && $this->syncedOrNot();
    }

    protected function syncedOrNot()
    {
        return !$this->schemas->pluck('synced')->contains(true) && $this->info('Nothing to sync.');
    }

    protected function tables()
    {
        return Collection::make(DB::select('SHOW TABLES'))->map(function ($table) {
            return array_values((array)$table);
        })->reject(function ($table) {
            return in_array(current($table), $this->ignore);
        })->flatten();
    }

    protected function processDatabase($content, $file)
    {
        $schemas = $this->getAllSchemas($content);
        foreach ($schemas as $schema) {
            $schema = new Schema($schema, $file, $this);
            $schema->process();
            $this->schemas->push($schema);
        }
    }

    protected function getAllSchemas($content)
    {   
        return Regex::matchAll('/Schema::create\s*\((.*)\,.*{(.*)}\);/sU', $content)->results();
    }

    /**
     * @return mixed
     */
    protected function unCreatedMigrates()
    {
        return $this->tables()->diff($this->schemasTables());
    }

    /**
     * @return mixed
     */
    protected function unDeletedMigrates()
    {
        return $this->schemasTables()->diff($this->tables());
    }

    protected function schemasTables()
    {
        return Collection::make($this->files)->map(function ($path, $file) { 
            return $this->fileToName($file);
        })->reject(function ($path, $file) {
            return in_array($this->fileToName($file), $this->ignore);
        })->flatten();
    }

    protected function fileToName($file)
    {
        // $fileArr = explode('_', $file);
        // array_pop($fileArr);
        // return implode('_', array_slice($fileArr, 5));
        return basename($file, '.php');
    }

    protected function nameToPath($name)
    {
        foreach ($this->files as $file => $path) {
            if ($this->fileToName($file) == $name) {
                return $path;
            }
        }
    }
}
