<?php

namespace Yuhal\SyncDatabase;

use Carbon\Carbon;
use Spatie\Regex\Regex;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema as LaravelSchema;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Schema\Blueprint;
use MigrationsGenerator\Generators\ColumnGenerator;
use MigrationsGenerator\Generators\IndexGenerator;
use MigrationsGenerator\MigrationsGeneratorSetting;

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
    public function __construct($schema, SyncDatabaseCommand $writeIn)
    {
        $this->schema = $schema;
        $this->writeIn = $writeIn;

        $this->name = $this->getName($schema->group(1));
        $this->table = DB::getTablePrefix() . $this->name;
    }

    public function process()
    {
        $action = $this->tabeExist() ? 'sync' : 'generate';

        $this->$action();
    }

    public function output()
    {
        $this->synced = true;
        return $this->writeIn;
    }

    protected function generate()
    {
        if($this->columnsList()->isEmpty()) {
            return $this->output()->error("Table <fg=black;bg=white> {$this->table} </> does not have any columns");
        }

        LaravelSchema::create($this->name, function (Blueprint $table) {
            eval($this->schema->group(2));
        });

        $this->output()->warn("New table <fg=white;bg=green> {$this->table} </> was created");
    }

    protected function sync()
    {
        $connection = Config::get('database.default');
        $setting = app(MigrationsGeneratorSetting::class);
        $setting->setConnection($connection);
        $setting->setUseDBCollation(false);
        $tableMigration = new TableMigration(
            app(ColumnGenerator::class),
            app(IndexGenerator::class),
            $setting,
        );
        $schemaUnsyncedColumns = $this->schemaUnsyncedColumns()->toArray();
        $dbUnsyncedColumns = $this->dbUnsyncedColumns()->toArray();
        if ($dbUnsyncedColumns && $schemaUnsyncedColumns) {
            foreach ($schemaUnsyncedColumns as $migrate => $column) {
                $schemaUnsyncedColumns[$migrate] = [];
                $schemaUnsyncedColumns[$migrate]['column'] = current($column);
                $schemaUnsyncedColumns[$migrate]['migrate'] = $migrate;
            }
            $schemaUnsyncedColumns = array_values($schemaUnsyncedColumns);
            foreach ($this->writeIn->files as $tableFilename => $tablePath) {
                $tableFilenameArr = explode('_', $tableFilename);
                array_pop($tableFilenameArr);
                $name = implode('_', array_slice($tableFilenameArr, 5));
                if ($name==$this->name) {
                    $table = DB::getDoctrineSchemaManager()->listTableDetails($name);
                    $indexes = $table->getIndexes();
                    $columns = $table->getColumns();
                    foreach ($dbUnsyncedColumns as $column => $type) {
                        if (isset($columns[$column])) {
                            $dbUnsyncedColumns[$column] = [];
                            $dbUnsyncedColumns[$column]['column'] = $column;
                            $dbUnsyncedColumns[$column]['type'] = $type;
                            $dbUnsyncedColumns[$column]['migrate'] = $tableMigration->column(
                                $table, [$columns[$column]], $indexes)->toString();
                        } else {
                            unset($dbUnsyncedColumns[$column]);
                        }
                    }
                    if ($dbUnsyncedColumns) {
                        $this->output()->info($tableFilename);
                        $dbUnsyncedColumns = array_values($dbUnsyncedColumns);
                        $content = File::get($tablePath);
                        foreach ($schemaUnsyncedColumns as $key => $value) {
                            $search = $schemaUnsyncedColumns[$key]['migrate'];
                            $replace = rtrim($dbUnsyncedColumns[$key]['migrate'], ';');
                            $this->output()->info("Pre replace:\n".$search);
                            $this->output()->info("After replace:\n".$replace);
                            $content = str_replace($search, $replace, $content);
                        }
                        File::replace($tablePath, $content);
                    }
                }
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
            return $this->dbColumns()->has($column);
        });
    }

    protected function dbColumns()
    {
        return Collection::make(DB::select('DESCRIBE ' . $this->table))
            ->mapWithKeys(function ($column) {
            return [$column->Field => $column->Type];
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
                $type = Regex::match('/\$.*->(.*)\(/', $line)->group(1);

                return [$line => in_array($type, array_keys($types)) ? $types[$type] : [$column]];
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
    
    protected function tabeExist()
    {
        return DB::getSchemaBuilder()->hasTable($this->name);
    }
}