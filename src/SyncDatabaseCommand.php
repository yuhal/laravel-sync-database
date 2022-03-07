<?php

namespace Yuhal\SyncDatabase;

use Carbon\Carbon;
use Spatie\Regex\Regex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema as LaravelSchema;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Console\Migrations\BaseCommand;
use MigrationsGenerator\Generators\FilenameGenerator;
use MigrationsGenerator\Generators\TableNameGenerator;
use MigrationsGenerator\MigrationsGeneratorSetting;

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

    /**
     * Ignore the migrated tables
     *
     * @var string
     */
    protected $ignore = [
        'migrations', 
        'telescope_entries', 
        'telescope_entries_tags', 
        'telescope_monitoring'
    ];

    /**
     * All migration files
     *
     * @var string
     */
    public $files;

    /**
     * Migrations generator setting
     *
     * @var string
     */
    public $setting;

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
        $this->setting = app(MigrationsGeneratorSetting::class);
        $this->setting->setConnection(Config::get('database.default'));
        $this->setting->setPath(Config::get('generators.config.migration_target_path'));
        $this->setting->setIgnoreIndexNames(false);
        $this->setting->setIgnoreForeignKeyNames(false);
        $this->setting->setUseDBCollation(false);
        $this->setting->setStubPath(Config::get('generators.config.migration_template_path'));
        $this->setting->setDate(Carbon::now());
        $this->repository = app(MigrationRepositoryInterface::class);
    }

    /**
     *
     */
    public function handle()
    {
        if (!$this->initMigrate()) {
            Artisan::call("migrate");
        }

        $this->initSyncLog();

        $this->files = $this->getMigrationFiles();
        
        $this->unDeletedMigrates()->each(function ($table) {
            DB::beginTransaction();
            $this->setting->setTableFilename(
                Config::get('generators.config.filename_pattern.table')
            );
            $path = $this->makeFilename($this->setting->getTableFilename(), $table);
            $className = basename($path, '.php');
            File::delete($path);
            unset($this->files[$className]);
            try {
                $this->repository->delete(json_decode("{\"migration\":\"{$className}\"}"));
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                exit;
            }
            if (!file_exists($path) && !isset($this->files[$className])) {
                $this->info("Delete migration file for <fg=black;bg=white>{$table}</>");
                DB::commit();
            } else {
                DB::rollBack();
            }
        });

        foreach ($this->files as $file) {
            $content = file_get_contents($file);
            $this->processDatabase($content, $file);
        }
        
        $this->unCreatedMigrates()->each(function ($table) {
            DB::beginTransaction();
            $this->setting->setTableFilename(
                Config::get('generators.config.filename_pattern.table')
            );
            $path = $this->makeFilename($this->setting->getTableFilename(), $table);
            $fileName = basename($path);
            $className = rtrim($fileName, '.php');
            try {
                $this->repository->log($className, 0);
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                exit;
            }
            Artisan::call("
                migrate:generate {$table} --no-interaction --table-filename='{$fileName}'
            ");
            if (file_exists($path)) {
                $this->info("Create migration file for <fg=black;bg=white>{$table}</>");
                DB::commit();
            } else {
                DB::rollBack();
            }
        })->isEmpty() && $this->syncedOrNot();
    }

    protected function initMigrate()
    {
        foreach ($this->ignore as $table) {
            if (!LaravelSchema::hasTable($table)) {
                return false;
            }
        }
        return true;
    }

    protected function initSyncLog()
    {
        foreach ($this->tables() as $table) {
            $this->setting->setTableFilename(
                Config::get('generators.config.filename_pattern.table')
            );
            $path = $this->makeFilename($this->setting->getTableFilename(), $table);
            $fileName = basename($path);
            $className = rtrim($fileName, '.php');
            $this->repository->log($className, 0);
        }
    }

    protected function syncedOrNot()
    {
        return !$this->schemas->pluck('synced')->contains(true) && $this->info('Nothing to sync.');
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

    protected function tables()
    {
        return Collection::make(DB::select('SHOW TABLES'))->map(function ($table) {
            return array_values((array)$table);
        })->reject(function ($table) {
            return in_array(current($table), $this->ignore);
        })->flatten();
    }

    protected function getAllSchemas($content)
    {   
        return array_merge(
            Regex::matchAll('/Schema::create\s*\((.*)\,.*{(.*)}\);/sU', $content)->results(),
            Regex::matchAll('/Schema::table\s*\((.*)\,.*{(.*)}\);/sU', $content)->results(),
            Regex::matchAll('/\$this->schema->create\s*\((.*)\,.*{(.*)}\);/sU', $content)->results()
        );
    }

    protected function getMigrationFiles()
    {   
        return Collection::make($this->migrator->getMigrationFiles($this->getMigrationPaths()))
        ->mapWithKeys(function ($path, $file) { 
            return [$file => $path];
        })->reject(function ($path, $file) {
            return in_array($this->fileToName($path), $this->ignore);
        });
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
            return $this->fileToName($path);
        })->reject(function ($path) {
            return in_array($path, $this->ignore);
        })->flatten();
    }

    protected function fileToName($path)
    {
        $content = file_get_contents($path);
        $schemas = $this->getAllSchemas($content);
        foreach ($schemas as $schema) {
            return $this->getTableName($schema->group(1));
        }
    }

    /**
     * Makes migration filename by given naming pattern.
     *
     * @param  string  $pattern  Naming pattern for migration filename.
     * @param  string  $datetimePrefix  Current datetime for filename prefix.
     * @param  string  $table  Table name.
     * @return string
     */
    private function makeFilename(string $pattern, string $table): string
    {
        $path     = $this->setting->getPath();
        $filename = $pattern;
        $replace  = [
            '[datetime_prefix]_' => '',
            '[table]'           => $this->stripTablePrefix($table),
        ];
        $filename = str_replace(array_keys($replace), $replace, $filename);
        return "$path/$filename";
    }

    /**
     * Strips prefix from table name.
     *
     * @param  string  $table  Table name.
     * @return string Table name without prefix.
     */
    private function stripTablePrefix(string $table): string
    {
        $tableNameEscaped = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $table);
        return app(TableNameGenerator::class)->stripPrefix($tableNameEscaped);
    }

    public function getTableName($name)
    {
        //TODO: https://github.com/yuhal/laravel-sync-migration/issues/2
        if(preg_match('/[\'|"]\s?\.\s?\$/', $name) || preg_match('/\$[a-zA-z0-9-_]+\s?\.\s?[\'|"]/', $name)) {
            $this->output()->error("Using variables as table names (<fg=black;bg=white> {$name} </>) is not supported currentlly, see <href=https://github.com/yuhal/laravel-sync-migration/issues/2> issue#2 </>");
            exit;
        }

        return str_replace(['\'', '"'], '', $name);
    }
}
