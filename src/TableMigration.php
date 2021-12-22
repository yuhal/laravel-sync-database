<?php

namespace Yuhal\SyncDatabase;

use Doctrine\DBAL\Schema\Table;
use MigrationsGenerator\MigrationsGeneratorSetting;
use MigrationsGenerator\Generators\ColumnGenerator;
use MigrationsGenerator\Generators\IndexGenerator;
use MigrationsGenerator\Generators\Blueprint\TableBlueprint;

class TableMigration
{
    private $columnGenerator;
    private $indexGenerator;
    private $setting;

    public function __construct(
        ColumnGenerator $columnGenerator,
        IndexGenerator $indexGenerator,
        MigrationsGeneratorSetting $setting
    ) {
        $this->columnGenerator = $columnGenerator;
        $this->indexGenerator  = $indexGenerator;
        $this->setting         = $setting;
    }

    /**
     * Generates `column` schema for table.
     *
     * @param  \Doctrine\DBAL\Schema\Table  $table
     * @param  \Doctrine\DBAL\Schema\Column[]  $columns
     * @param  \Doctrine\DBAL\Schema\Index[]  $indexes
     * @return \MigrationsGenerator\Generators\Blueprint\TableBlueprint
     */
    public function column(Table $table, array $columns, array $indexes): TableBlueprint
    {
        $blueprint = new TableBlueprint();

        // Use $indexes instead.
        $this->indexGenerator->setSpatialFlag($indexes, $table->getName());
        $singleColumnIndexes = $this->indexGenerator->getSingleColumnIndexes($indexes);
        $multiColumnsIndexes = $this->indexGenerator->getCompositeIndexes($indexes);

        foreach ($columns as $column) {
            $method = $this->columnGenerator->generate($table, $column, $singleColumnIndexes);
            $blueprint->setMethod($method);
        }

        $blueprint->mergeTimestamps();

        if ($multiColumnsIndexes->isNotEmpty()) {
            $blueprint->setLineBreak();
            foreach ($multiColumnsIndexes as $index) {
                $method = $this->indexGenerator->generate($table, $index);
                $blueprint->setMethod($method);
            }
        }
        return $blueprint;
    }
}
