<?php

namespace Yuhal\SyncDatabase;

use Spatie\Regex\Regex;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;
use MigrationsGenerator\Generators\ColumnGenerator;
use MigrationsGenerator\Generators\IndexGenerator;
use MigrationsGenerator\Generators\FilenameGenerator;
use MigrationsGenerator\MigrationsGeneratorSetting;
use MigrationsGenerator\Generators\Writer\MigrationWriter;

class Schema
{
    public $schema;
    public $name;
    public $table;
    public $writeIn;
    public $synced = false;

    /**
     * Schema constructor.
     * @param $schema
     * @param $writeIn
     */
    public function __construct($schema, $file, SyncDatabaseCommand $writeIn)
    {
        $this->schema = $schema;
        $this->file = $file;
        $this->writeIn = $writeIn;
        $this->name = $this->getName($schema->group(1));
        $this->table = DB::getTablePrefix() . $this->name;
        $connection = Config::get('database.default');
        $this->setting = app(MigrationsGeneratorSetting::class);
        $this->setting->setConnection($connection);
        $this->setting->setIgnoreIndexNames(false);
        $this->setting->setIgnoreForeignKeyNames(false);
        $this->setting->setUseDBCollation(false);
        $this->setting->setStubPath(Config::get('generators.config.migration_template_path'));
        $this->setting->setTableFilename(Config::get('generators.config.filename_pattern.table'));
        $this->tableMigration = new TableMigration(
            app(ColumnGenerator::class),
            app(IndexGenerator::class),
            $this->setting,
        );
    }

    public function process()
    {
        $action = 'sync';
        $this->$action();
    }

    public function output()
    {
        $this->synced = true;
        return $this->writeIn;
    }

    protected function sync()
    {
        $schemaUnsyncedColumns = array_values($this->schemaUnsyncedColumns()->toArray());
        $dbUnsyncedColumns = array_values($this->dbUnsyncedColumns()->toArray());
        
        if ($dbUnsyncedColumns || $schemaUnsyncedColumns) {
            $migrationWriter = app(MigrationWriter::class);
            $filenameGenerator = app(FilenameGenerator::class);
            $table = DB::getDoctrineSchemaManager()->listTableDetails($this->name);
            $indexes = $table->getIndexes();
            $columns = $table->getColumns();
            $up = $this->tableMigration->up($table, $columns, $indexes);
            $down = $this->tableMigration->down($table);
            $migrationWriter->writeTo(
                $this->file,
                $this->setting->getStubPath(),
                $filenameGenerator->makeTableClassName($this->name),
                $up,
                $down
            );
            $this->output()->info($this->file);
            foreach ($dbUnsyncedColumns as $column) {
                $this->output()->info(
                    "Add migration column: <fg=black;bg=white>".$column['column']."</>"
                );
            }
            foreach ($schemaUnsyncedColumns as $column) {
                $this->output()->info(
                    "Delete migration column: <fg=black;bg=yellow>".$column['column'][0]."</>"
                );
            }
        }
    }

    protected function getName($name)
    {
        //TODO: https://github.com/yuhal/laravel-sync-migration/issues/2
        if(preg_match('/[\'|"]\s?\.\s?\$/', $name) || preg_match('/\$[a-zA-z0-9-_]+\s?\.\s?[\'|"]/', $name)) {
            $this->output()->error("Using variables as table names (<fg=black;bg=white> {$name} </>) is not supported currentlly, see <href=https://github.com/yuhal/laravel-sync-migration/issues/2> issue#2 </>");
            exit;
        }

        return str_replace(['\'', '"'], '', $name);
    }

    protected function dbUnsyncedColumns()
    {
        return $this->dbColumns()->reject(function ($type, $column) {
            return $this->columnsList()->values()->flatten()->contains($column);
        });
    }

    protected function schemaUnsyncedColumns()
    {
        return $this->columnsList()->reject(function ($column) {
            return $this->dbColumns()->has($column['column']);
        });
    }

    protected function dbColumns()
    {
        $table = DB::getDoctrineSchemaManager()->listTableDetails($this->table);
        $indexes = $table->getIndexes();
        $columns = $table->getColumns();
        return Collection::make(DB::select('DESCRIBE ' . $this->table))
            ->mapWithKeys(function ($column) use ($table, $indexes, $columns)  {
            return [
                $column->Field => [
                    'column' => $column->Field,
                    'type' => $column->Type,
                    'migrate' => $this->tableMigration->column(
                        $table, [$columns[$column->Field]], $indexes)->toString()
                ],
            ];
        });
    }

    protected function columnsList()
    {
        return Collection::make(explode(';', $this->schema->group(2)))->mapWithKeys(function ($line) {
            $line = trim($line);

            if(Str::startsWith($line, ['//', '#', '/*'])) {
                return [];
            }

            try {
                $column = Regex::match('~(["\'])([^"\']+)\1~', $line);
                $column = $column->hasMatch() ? $column->group(2) : null;
                $types = $this->columnsTypes($column);
                $type = Regex::match('/\$.*?->(.*?)\(/', $line)->group(1);
                return [
                    $line => [
                        'column' => in_array($type, array_keys($types)) ? $types[$type] : [$column],
                        'migrate' => $line,
                    ]
                ];
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    protected function columnsTypes($column)
    {
        return  [
            'id' => ['id'],
            'rememberToken' => ['remember_token'],
            'softDeletes' => ['deleted_at'],
            'softDeletesTz' => ['deleted_at'],
            'timestamps' => ['created_at', 'updated_at'],
            'timestampsTz' => ['created_at', 'updated_at'],
            'nullableTimestamps' => ['created_at', 'updated_at'],
            'morphs' => ["{$column}_id", "{$column}_type"],
            'uuidMorphs' => ["{$column}_id", "{$column}_type"],
            'nullableUuidMorphs' => ["{$column}_id", "{$column}_type"],
            'nullableMorphs' => ["{$column}_id", "{$column}_type"],
        ];
    }
}