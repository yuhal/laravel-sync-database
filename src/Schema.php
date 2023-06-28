<?php

namespace Yuhal\SyncDatabase;

use Spatie\Regex\Regex;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use MigrationsGenerator\Generators\ColumnGenerator;
use MigrationsGenerator\Generators\IndexGenerator;
use MigrationsGenerator\Generators\FilenameGenerator;
use MigrationsGenerator\Generators\Writer\MigrationWriter;

class Schema
{
    public $schema;
    public $name;
    public $table;
    public $writeIn;
    public $file;
    public $tableMigration;
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
        $this->name = $this->writeIn->getTableName($schema->group(1));
        $this->table = DB::getTablePrefix() . $this->name;
        $this->writeIn->setting->setTableFilename(Config::get('generators.config.filename_pattern.table'));
        $this->tableMigration = new TableMigration(
            app(ColumnGenerator::class),
            app(IndexGenerator::class),
            $this->writeIn->setting
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
                $this->writeIn->setting->getStubPath(),
                $filenameGenerator->makeTableClassName($this->name),
                $up,
                $down
            );
            $this->output()->info($this->file);
            foreach ($dbUnsyncedColumns as $column) {
                $this->output()->info(
                    "Update migration column: <fg=black;bg=white>".$column['column']."</>"
                );
            }
            foreach ($schemaUnsyncedColumns as $column) {
                $this->output()->info(
                    "Delete migration column: <fg=black;bg=yellow>".$column['column'][0]."</>"
                );
            }
        }
    }

    protected function dbUnsyncedColumns()
    {
        return $this->dbColumns()->reject(function ($type, $column) {
            $migrate = str_replace(';', '', $type['migrate']);
            return $this->columnsList()->values()->pluck('migrate')->contains($migrate);
        });
    }

    protected function schemaUnsyncedColumns()
    {
        return $this->columnsList()->reject(function ($type) {
            return $this->dbColumns()->has($type['column']);
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