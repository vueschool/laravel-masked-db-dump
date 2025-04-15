<?php

namespace BeyondCode\LaravelMaskedDumper;

use Illuminate\Console\OutputStyle;
use BeyondCode\LaravelMaskedDumper\TableDefinitions\TableDefinition;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Illuminate\Database\Connection as DatabaseConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

class LaravelMaskedDump
{
    /** @var DumpSchema */
    protected $definition;

    /** @var OutputStyle */
    protected $output;

    /** @var AbstractPlatform */
    protected $platform;

    /** @var string */
    protected $escapeString = "`";

    public function __construct(DumpSchema $definition, OutputStyle $output)
    {
        $this->definition = $definition;
        $this->output = $output;
        $this->platform = $this->getPlatform($this->definition->getConnection());

        if($this->platform instanceof PostgreSQLPlatform) {
            $this->escapeString = '"';
        }
    }

    public function dump()
    {
        $tables = $this->definition->getDumpTables();

        $query = '';

        $overallTableProgress = $this->output->createProgressBar(count($tables));

        foreach ($tables as $tableName => $table) {
            if ($table->shouldDumpData()) {
                $query .= $this->dumpTableData($table);
            }

            $overallTableProgress->advance();
        }

        return $query;
    }

    protected function transformResultForInsert($row, TableDefinition $table)
    {
        return collect($row)->map(function ($value, $column) use ($table) {
            if ($columnDefinition = $table->findColumn($column)) {
                $value = $columnDefinition->modifyValue($value);
            }

            if ($value === null) {
                return 'NULL';
            }
            if ($value === '') {
                return '""';
            }

            return $this->platform->quoteStringLiteral($value);
        })->toArray();
    }

    protected function getPlatform(DatabaseConnection $connection)
    {
        switch ($connection->getDriverName()) {
            case 'mysql':
                return new MySQLPlatform;
            case 'pgsql':
                return new PostgreSQLPlatform;
            case 'sqlite':
                return new SqlitePlatform;
            case 'mariadb':
                return new MariaDBPlatform;
            default:
                throw new \RuntimeException("Unsupported platform: {$connection->getDriverName()}. Please check the documentation for more information.");
        }
    }

    protected function dumpTableData(TableDefinition $table)
    {
        $query = '';
        $chunkedQueries = [];

        $queryBuilder = $this->definition->getConnection()->table($table->getDoctrineTable()->getName());
        $table->modifyQuery($queryBuilder);

        $tableName = $table->getDoctrineTable()->getName();
        $tableName = "$this->escapeString$tableName$this->escapeString";

        if ($table->getChunkSize() > 0) {
            // Get all columns for ordering
            $columns = $table->getDoctrineTable()->getColumns();
            if ($primaryKey = $table->getDoctrineTable()->getPrimaryKey()) {
                // If primary key exists, use it for ordering
                $queryBuilder->orderBy($primaryKey->getColumns()[0]);
            } else {
                // Otherwise order by all columns to ensure consistent chunking
                foreach ($columns as $column) {
                    $queryBuilder->orderBy($column->getName());
                }
            }

            // Get the first row to determine column names
            $firstRow = $queryBuilder->first();
            if (!$firstRow) {
                return "";
            }
            
            $columns = array_keys((array)$firstRow);
            $column_names = "($this->escapeString" . join("$this->escapeString, $this->escapeString", $columns) . "$this->escapeString)";

            $values = [];
            $queryBuilder->lazy()->each(function($row) use ($table, &$values, $tableName, $column_names, &$chunkedQueries) {
                $row = $this->transformResultForInsert((array)$row, $table);
                $values[] = '(' . join(', ', $row) . ')';
                
                if (count($values) >= $table->getChunkSize()) {
                    $chunkedQueries[] = "INSERT INTO $tableName $column_names VALUES " . implode(', ', $values) . ';';
                    $values = [];
                }
            });

            // Add any remaining values
            if (!empty($values)) {
                $chunkedQueries[] = "INSERT INTO $tableName $column_names VALUES " . implode(', ', $values) . ';';
            }
            
            return implode(PHP_EOL, $chunkedQueries) . PHP_EOL;
        } else {
            $queryBuilder->get()->each(function ($row, $index) use ($table, &$query, $tableName) {
                $row = $this->transformResultForInsert((array)$row, $table);

                $query .= "INSERT INTO $tableName ($this->escapeString" . implode("$this->escapeString, $this->escapeString", array_keys($row)) . "$this->escapeString) VALUES ";

                $query .= "(";
                $query .= implode(', ', $row);
                $query .= ");" . PHP_EOL;
            });
        }

        return $query;
    }
}
