<?php
declare(strict_types=1);

/**
 * Phinx
 *
 * (The MIT license)
 * Copyright (c) 2015 Rob Morgan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated * documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package    Phinx
 * @subpackage Phinx\Db\Adapter
 */

namespace Phinx\Db\Adapter;

use BadMethodCallException;
use Cake\Database\Connection;
use InvalidArgumentException;
use PDOException;
use PDOOCI\PDO as OracleDriver;
use Phinx\Config\Config;
use Phinx\Db\Table\Column;
use Phinx\Db\Table\ForeignKey;
use Phinx\Db\Table\Index;
use Phinx\Db\Table\Table;
use Phinx\Db\Util\AlterInstructions;
use Phinx\Migration\MigrationInterface;
use Phinx\Util\Literal;
use ReflectionProperty;
use RuntimeException;

/**
 * Phinx Oracle Adapter.
 *
 * @author FreshFlow Systems s.r.o
 */
class OracleAdapter extends PdoAdapter
{
    /**
     * Columns with comments
     *
     * @var \Phinx\Db\Table\Column[]
     */
    protected array $columnsWithComments = [];
    private bool $upper = true;
    private const ORACLE_DEFAULT = '12.1';

    /**
     * @var string
     */
    protected string $schema = 'public';

    /**
     * @return bool
     */
    public function getUpper(): bool
    {
        return $this->upper;
    }

    /**
     * @param bool $upper
     */
    public function setUpper(bool $upper): void
    {
        $this->upper = $upper;
    }

    /**
     * @param string $string
     * @return string $string
     */
    public function checkUpper(string $string): string
    {
        if ($this->getUpper()) {
            return strtoupper($string);
        }

        return $string;
    }

    /**
     * @param string $name
     * @return string $name
     */
    public function getShortName(string $name): string
    {
        if (strlen($name) > $this->getVersionLimit()) {
            $tableNameArray = explode('_', $name);
            $arrayLen = count($tableNameArray);
            $oracleLenLimit = $this->getVersionLimit();
            $chunkLen = floor($oracleLenLimit / $arrayLen) - 1;
            $newArray = array_map(
                static function ($param) use ($chunkLen) {
                    return substr($param, 0, $chunkLen);
                },
                $tableNameArray
            );

            return implode('_', $newArray);
        }

        return $name;
    }

    /**
     * @return int
     */
    public function getVersionLimit(): int
    {
        $options = $this->getOptions();
        if (!isset($options['oracle_version'])) {
            $options['oracle_version'] = static::ORACLE_DEFAULT;
        }
        if ($options['oracle_version'] === '12.1') {
            return 30;
        }
        if ($options['oracle_version'] === '12.2') {
            return 60;
        }
        if ($options['oracle_version'] === 'debug') {
            return 10;
        }

        return 128;
    }

    /**
     * @return string
     */
    public function getDsn(): string
    {
        $options = $this->getOptions();
        $dsn = $options['host'];

        // if port is specified use it, otherwise use the Oracle default
        if (isset($options['port'])) {
            $dsn .= ':' . $options['port'];
        }

        $dsn .= '/' . $options['sid'];

        return $dsn;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(): void
    {
        if ($this->connection === null) {
            if (!extension_loaded('oci8')) {
                // @codeCoverageIgnoreStart
                throw new RuntimeException('You need to enable the OCI8 extension for Phinx to run properly.');
                // @codeCoverageIgnoreEnd
            }

            $options = $this->getOptions();
            $dsn = $this->getDsn();

            try {
                $db = new OracleDriver($dsn, $options['user'], $options['pass']);
            } catch (PDOException $exception) {
                throw new InvalidArgumentException(sprintf(
                    'There was a problem connecting to the database: %s',
                    $exception->getMessage()
                ), $exception->getCode(), $exception);
            }
            $this->setConnection($db);
        }
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * @inheritDoc
     */
    public function hasTransactions(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        $this->execute('BEGIN');
    }

    /**
     * @inheritDoc
     */
    public function commitTransaction(): void
    {
        $this->execute('COMMIT');
    }

    /**
     * @inheritDoc
     */
    public function rollbackTransaction(): void
    {
        $this->execute('ROLLBACK');
    }

    /**
     * Quotes a schema name for use in a query.
     *
     * @param string $schemaName Schema Name
     * @return string
     */
    public function quoteSchemaName(string $schemaName): string
    {
        return $this->checkUpper($this->quoteColumnName($schemaName));
    }

    /**
     * Quotes a schema table name for use in a query.
     *
     * @param string $schemaTableName Schema Name
     * @return string
     */
    public function quoteSchemaTableName(string $schemaTableName): string
    {
        $parts = $this->getSchemaName($schemaTableName);
        if ($parts['schema'] === '') {
            $result = $this->quoteTableName($parts['table']);
        } else {
            $result = $this->quoteSchemaName($parts['schema']) . '.' . $this->quoteTableName($parts['table']);
        }

        return $this->checkUpper($result);
    }

    /**
     * @inheritDoc
     */
    public function quoteTableName(string $tableName): string
    {
        return $this->quoteColumnName($tableName);
    }

    /**
     * @inheritDoc
     */
    public function quoteColumnName(string $columnName): string
    {
        return $this->checkUpper('"' . $columnName . '"');
    }

    /**
     * Quotes a column name for use in a query.
     */
    public function quoteIndexName(string $columnName): string
    {
        return $this->checkUpper("'" . $columnName . "'");
    }

    /**
     * @inheritDoc
     */
    public function hasTable($tableName): bool
    {
        $tableFullName = $this->getSchemaName($tableName);
        $tableSearchName = $tableFullName['schema'] . $tableFullName['table'];

        $result = $this->fetchRow(
            sprintf(
                'SELECT count(*) as count FROM ALL_TABLES WHERE owner || table_name = \'%s\'',
                $tableSearchName
            )
        );

        return $result['COUNT'] > 0;
    }

    /**
     * @inheritDoc
     */
    public function createTable(Table $table, array $columns = [], array $indexes = []): void
    {
        $options = $table->getOptions();

        // Add the default primary key
        if (!isset($options['id']) || ($options['id'] === true)) {
            $column = new Column();
            $column->setName('UID')
                ->setType('biginteger')
                ->setIdentity(true);

            array_unshift($columns, $column);
            $options['primary_key'] = 'UID';
        } elseif (is_string($options['id'])) {
            // Handle id => "field_name" to support AUTO_INCREMENT
            $column = new Column();
            $column->setName($options['id'])
                ->setType('integer')
                ->setOptions(['identity' => true]);

            array_unshift($columns, $column);
            $options['primary_key'] = $options['id'];
        }

        // TODO - process table options like collation etc
        $sql = 'CREATE TABLE ';
        $tableName = $this->getShortName($table->getName());
        $sql .= $this->quoteSchemaTableName($tableName) . ' (';

        $this->columnsWithComments = [];
        foreach ($columns as $column) {
            $sql .= $this->quoteColumnName($this->getShortName($column->getName())) . ' ' . $this->getColumnSqlDefinition($column) . ', ';

            // set column comments, if needed
            if ($column->getComment()) {
                $this->columnsWithComments[] = $column;
            }
        }

        // set the primary key(s)
        if (isset($options['primary_key'])) {
            $sql = rtrim($sql);
            // set the NAME of pkey to internal code -> 12.1 limit to 30char , 12.2 limit to 60char
            $sql .= sprintf(' CONSTRAINT %s PRIMARY KEY (', $this->quoteColumnName($this->getShortName($table->getName()) . '_PK'));
            if (is_string($options['primary_key'])) { // handle primary_key => 'id'
                $sql .= $this->quoteColumnName($options['primary_key']);
            } elseif (is_array($options['primary_key'])) { // handle primary_key => array('tag_id', 'resource_id')
                $sql .= implode(',', array_map([$this, 'quoteColumnName'], $options['primary_key']));
            }
            $sql .= ')';
        } else {
            $sql = rtrim($sql, ', '); // no primary keys
        }

        $sql .= ')';
        $this->execute($sql);

        // process column comments
        if (!empty($this->columnsWithComments)) {
            foreach ($this->columnsWithComments as $column) {
                $sql = $this->getColumnCommentSqlDefinition($column, $table->getName());
                $this->execute($sql);
            }
        }
        // set the indexes
        if (!empty($indexes)) {
            foreach ($indexes as $index) {
                $sql = $this->getIndexSqlDefinition($index, $table->getName());
                $this->execute($sql);
            }
        }

        // process table comments
        if (isset($options['comment'])) {
            $sql = sprintf(
                'COMMENT ON TABLE %s IS %s',
                $this->quoteSchemaTableName($table->getName()),
                $this->getConnection()->quote($options['comment'])
            );
            $this->execute($sql);
        }
    }

    /**
     * @inheritDoc
     */
    protected function getRenameTableInstructions(string $tableName, string $newTableName): AlterInstructions
    {
        $sql = sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $this->quoteSchemaTableName($tableName),
            $this->quoteColumnName($this->getShortName($newTableName))
        );

        return new AlterInstructions([], [$sql]);
    }

    /**
     * @inheritDoc
     */
    public function dropTable(string $tableName): void
    {
        $this->execute(sprintf('DROP TABLE %s', $this->quoteSchemaTableName($tableName)));
    }

    /**
     * @inheritDoc
     */
    protected function getDropTableInstructions(string $tableName): AlterInstructions
    {
        $sql = sprintf(
            'DROP TABLE %s',
            $this->quoteSchemaTableName($tableName)
        );

        return new AlterInstructions([], [$sql]);
    }

    /**
     * @inheritDoc
     */
    public function truncateTable(string $tableName): void
    {
        $sql = sprintf(
            'TRUNCATE TABLE %s',
            $this->quoteSchemaTableName($tableName)
        );

        $this->execute($sql);
    }

    /**
     * @inheritDoc
     */
    public function getColumns(string $tableName): array
    {
        $parts = $this->getSchemaName($tableName);
        $schemaTableName = $parts['schema'] . $parts['table'];
        $columns = [];
        $sql = sprintf(
            "select TABLE_NAME \"table_name\", COLUMN_NAME \"name\", DATA_TYPE \"type\", NULLABLE \"null\",
            DATA_DEFAULT \"default\", DATA_LENGTH \"char_length\", DATA_PRECISION \"limit\", DATA_SCALE \"scale\",
            COLUMN_ID \"ordinal_position\" FROM ALL_TAB_COLUMNS WHERE owner || table_name = '%s'",
            $schemaTableName
        );

        $rows = $this->fetchAll($sql);
        foreach ($rows as $columnInfo) {
            $default = null;
            if (trim($columnInfo['default']) !== 'NULL') {
                $default = trim($columnInfo['default']);
            }
            $column = new Column();
            $column->setName($columnInfo['name'])
                ->setType($this->getPhinxType($columnInfo['type'], $columnInfo['limit'], $columnInfo['scale']))
                ->setNull($columnInfo['null'] !== 'N')
                ->setDefault($default)
                ->setComment($this->getColumnComment($columnInfo['table_name'], $columnInfo['name']));
            if (!empty($columnInfo['char_length'])) {
                $column->setLimit($columnInfo['char_length']);
            }
            $columns[$columnInfo['name']] = $column;

            $lowerName = strtolower($columnInfo['name']);
            $column->setName($lowerName);
            $columns[$lowerName] = $column;
        }

        return $columns;
    }

    /**
     * Get the comment for a column
     *
     * @param string $tableName Table Name
     * @param string $columnName Column Name
     * @return string
     */
    public function getColumnComment(string $tableName, string $columnName): string
    {
        $parts = $this->getSchemaName($tableName);
        $schemaTableName = $parts['schema'] . $parts['table'];
        $sql = sprintf(
            "select COMMENTS from ALL_COL_COMMENTS WHERE COLUMN_NAME = '%s' and OWNER || TABLE_NAME = '%s'",
            $this->checkUpper($columnName),
            $schemaTableName
        );
        $row = $this->fetchRow($sql);

        return $row['COMMENTS'];
    }

    /**
     * @inheritDoc
     */
    public function hasColumn(string $tableName, string $columnName): bool
    {
        $parts = $this->getSchemaName($tableName);
        $schemaTableName = $parts['schema'] . $parts['table'];
        $result = $this->fetchRow(sprintf(
            "SELECT count(*) as count FROM ALL_TAB_COLUMNS WHERE owner || table_name = '%s' and column_name = '%s'",
            $schemaTableName,
            $this->checkUpper($columnName)
        ));

        return $result['COUNT'] > 0;
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return bool
     */
    public function isNotNullColumn(string $tableName, string $columnName): bool
    {
        $parts = $this->getSchemaName($tableName);
        $parts['column'] = $this->checkUpper($columnName);
        $schemaTableColumnName = $parts['schema'] . $parts['table'] . $parts['column'];
        $result = $this->fetchRow(sprintf(
            "select count(*) as count FROM ALL_TAB_COLUMNS WHERE owner || table_name || column_name= '%s' and nullable = 'N'",
            $schemaTableColumnName
        ));

        return $result['COUNT'] > 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function getAddColumnInstructions(Table $table, Column $column): AlterInstructions
    {
        $result = $this->hasTable($table->getName());
        if (!$result) {
            throw new InvalidArgumentException('The specified table does not exist: ' . $table->getName());
        }
        $instructions = sprintf(
            'ALTER TABLE %s ADD %s %s',
            $this->quoteSchemaTableName($table->getName()),
            $this->quoteColumnName($this->getShortName($column->getName())),
            $this->getColumnSqlDefinition($column)
        );

        return new AlterInstructions([], [$instructions]);
    }

    /**
     * @inheritDoc
     */
    protected function getRenameColumnInstructions(string $tableName, string $columnName, string $newColumnName): AlterInstructions
    {
        $result = $this->hasColumn($tableName, $columnName);
        if (!$result) {
            throw new InvalidArgumentException("The specified column does not exist: $columnName");
        }

        $instructions = new AlterInstructions();
        $instructions->addPostStep(
            sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $this->quoteSchematableName($tableName),
                $this->quoteColumnName($columnName),
                $this->quoteColumnName($this->getShortName($newColumnName))
            )
        );

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    protected function getChangeColumnInstructions(string $tableName, string $columnName, Column $newColumn): AlterInstructions
    {
        $result = $this->hasColumn($tableName, $columnName);
        if (!$result) {
            throw new InvalidArgumentException("The specified column does not exist: $columnName");
        }
        // unset default configuration
        $newColumn->unsetDefaultOptions();

        $newInstructions = $this->getColumnSqlDefinition($newColumn);
        if ($this->isNotNullColumn($tableName, $columnName)) {
            $newInstructions = str_ireplace(' NOT NULL', '', $newInstructions);
        }
        $alter = sprintf(
            'ALTER TABLE %s MODIFY(%s %s)',
            $this->quoteSchemaTableName($tableName),
            $this->quoteColumnName($newColumn->getName()),
            $newInstructions
        );
        $sql = $this->getColumnCommentSqlDefinition($newColumn, $tableName);
        $this->execute($sql);

        return new AlterInstructions([], [$alter]);
    }

    /**
     * @inheritDoc
     */
    protected function getDropColumnInstructions(string $tableName, string $columnName): AlterInstructions
    {
        $result = $this->hasColumn($tableName, $columnName);
        if (!$result) {
            throw new InvalidArgumentException("The specified column does not exist: $columnName");
        }
        $alter = sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $this->quoteSchemaTableName($tableName),
            $this->quoteColumnName($columnName)
        );

        return new AlterInstructions([], [$alter]);
    }

    /**
     * Get an array of indexes from a particular table.
     * J.Rosa query => not returning all index names. cannot search hasIndexByName TODO: fix
     *
     * @param string $tableName Table name
     * @return array
     */
    protected function getIndexes(string $tableName): array
    {
        $parts = $this->getSchemaName($tableName);

        $indexes = [];
        $sql = sprintf(
            "SELECT ic.index_name,
       ic.column_name,
       ie.column_expression
FROM all_ind_columns ic
  LEFT JOIN all_ind_expressions ie
    ON ie.index_owner = ic.index_owner
   AND ie.index_name = ic.index_name
   AND ie.column_position = ic.column_position
WHERE ic.table_name = '%s' AND ic.table_owner = '%s' ORDER BY ic.index_name",
            $parts['table'],
            $parts['schema'],
        );

        $rows = $this->fetchAll($sql);
        foreach ($rows as $row) {
            if (!isset($indexes[$row['INDEX_NAME']])) {
                $indexes[$row['INDEX_NAME']] = ['columns' => []];
            }

            if (str_starts_with($row['COLUMN_NAME'], 'SYS_NC')) {
                $indexes[$row['INDEX_NAME']]['columns'][] = str_replace("'", '', $row['COLUMN_EXPRESSION']);
            } else {
                $indexes[$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
            }
        }

        return $indexes;
    }

    /**
     * @inheritDoc
     */
    public function hasIndex(string $tableName, string|array $columns): bool
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        if ($this->getUpper()) {
            $columns = array_map('strtoupper', $columns);
        }
        $indexes = $this->getIndexes($tableName);

        foreach ($indexes as $index) {
            $a = array_diff($columns, $index['columns']);

            if (empty($a)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function hasIndexByName(string $tableName, string $indexName): bool
    {
        $indexName = $this->checkUpper($indexName);
        $indexes = $this->getIndexes($tableName);
        if (array_key_exists($indexName, $indexes)) {
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    protected function getAddIndexInstructions(Table $table, Index $index): AlterInstructions
    {
        $alter = $this->getIndexSqlDefinition($index, $table->getName());

        return new AlterInstructions([], [$alter]);
    }

    /**
     * @inheritDoc
     */
    protected function getDropIndexByColumnsInstructions(string $tableName, $columns): AlterInstructions
    {
        $parts = $this->getSchemaName($tableName);

        if (is_string($columns)) {
            $columns = [$columns]; // str to array
        }

        if ($this->getUpper()) {
            $columns = array_map('strtoupper', $columns);
        }

        $indexes = $this->getIndexes($tableName);
        foreach ($indexes as $indexName => $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                return new AlterInstructions([], [sprintf(
                    'DROP INDEX %s',
                    '"' . ($parts['schema'] . '".' . $this->quoteColumnName($indexName))
                )]);
            }
        }

        throw new InvalidArgumentException(sprintf(
            "The specified index on columns '%s' does not exist",
            implode(',', $columns)
        ));
    }

    /**
     * @inheritDoc
     */
    protected function getDropIndexByNameInstructions(string $tableName, string $indexName): AlterInstructions
    {
        $parts = $this->getSchemaName($tableName);

        $sql = sprintf(
            'DROP INDEX %s',
            '"' . ($parts['schema'] . '".' . $this->quoteColumnName($indexName))
        );

        return new AlterInstructions([], [$sql]);
    }

    /**
     * @inheritDoc
     */
    public function hasForeignKey(string $tableName, $columns, ?string $constraint = null): bool
    {
        $foreignKeys = $this->getForeignKeys($tableName);
        if ($constraint) {
            if (isset($foreignKeys[$constraint])) {
                return !empty($foreignKeys[$constraint]);
            }

            return false;
        }

        if (is_string($columns)) {
            $columns = [$columns];
        }

        if ($this->getUpper()) {
            $columns = array_map('strtoupper', $columns);
        }

        foreach ($foreignKeys as $key) {
            $a = array_diff($columns, $key['columns']);
            if (empty($a)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get an array of foreign keys from a particular table.
     *
     * @param string $tableName Table name
     * @return array
     */
    protected function getForeignKeys(string $tableName): array
    {
        $parts = $this->getSchemaName($tableName);
        $fullTableName = $parts['schema'] . $parts['table'];
        $foreignKeys = [];
        $rows = $this->fetchAll(sprintf(
            "SELECT a.constraint_name, a.owner, a.table_name, a.column_name,
						/* referenced values */
					    c_pk.constraint_name AS referenced_constraint_name,
						c.r_owner AS referenced_owner,
						c_pk.table_name  AS referenced_table_name,
						b.column_name  AS referenced_column_name
  					FROM all_cons_columns a
 					 JOIN all_constraints c ON a.owner = c.owner AND a.constraint_name = c.constraint_name
 					 JOIN all_constraints c_pk ON c.r_owner = c_pk.owner AND c.r_constraint_name = c_pk.constraint_name
 					 JOIN all_cons_columns b ON b.owner = c_pk.owner AND b.constraint_name = c_pk.constraint_name
 					WHERE c.constraint_type = 'R' /* foreign key oracle code */
 					AND a.owner || a.table_name = '%s'",
            $fullTableName
        ));
        foreach ($rows as $row) {
            $foreignKeys[$row['CONSTRAINT_NAME']]['table'] = $row['TABLE_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['columns'][] = $row['COLUMN_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['referenced_table'] = $row['REFERENCED_TABLE_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        return $foreignKeys;
    }

    /**
     * @inheritDoc
     */
    protected function getAddForeignKeyInstructions(Table $table, ForeignKey $foreignKey): AlterInstructions
    {
        $alter = sprintf(
            'ALTER TABLE %s ADD %s',
            $this->quoteSchemaTableName($table->getName()),
            $this->getForeignKeySqlDefinition($foreignKey, $table->getName())
        );

        return new AlterInstructions([], [$alter]);
    }

    /**
     * @inheritDoc
     */
    protected function getDropForeignKeyInstructions(string $tableName, string $constraint): AlterInstructions
    {
        $alter = sprintf(
            'ALTER TABLE %s DROP CONSTRAINT %s',
            $this->quoteSchemaTableName($tableName),
            $this->quoteColumnName($constraint)
        );

        return new AlterInstructions([], [$alter]);
    }

    /**
     * @inheritDoc
     */
    protected function getDropForeignKeyByColumnsInstructions(string $tableName, array $columns): AlterInstructions
    {
        $instructions = new AlterInstructions();

        $parts = $this->getSchemaName($tableName);
        $fullTableName = $parts['schema'] . $parts['table'];
        $sql = "SELECT a.constraint_name
  					FROM all_cons_columns a
 					 JOIN all_constraints c ON a.owner = c.owner AND a.constraint_name = c.constraint_name
 					 JOIN all_constraints c_pk ON c.r_owner = c_pk.owner AND c.r_constraint_name = c_pk.constraint_name
 					WHERE c.constraint_type = 'R' /* foreign key oracle code */
 					AND a.owner || a.table_name = '%s'";

        $array = [];
        foreach ($columns as $col) {
            $array[] = $this->quoteColumnName($col);
        }

        $rows = $this->fetchAll(sprintf(
            $sql,
            $fullTableName,
            implode(',', $array),
            implode(',', $array)
        ));

        foreach ($rows as $row) {
            $newInstr = $this->getDropForeignKeyInstructions($tableName, $row['CONSTRAINT_NAME']);
            $instructions->merge($newInstr);
        }

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    public function getSqlType(Literal|string $type, ?int $limit = null): array
    {
        // https://docs.oracle.com/database/121/SQLRF/sql_elements001.htm#SQLRF00213
        switch ($type) {
            // datetime datatypes
            case static::PHINX_TYPE_TIME:
            case static::PHINX_TYPE_TIMESTAMP:
                return ['name' => 'TIMESTAMP', 'limit' => 6];
            case static::PHINX_TYPE_DATE:
            case static::PHINX_TYPE_DATETIME:
                return ['name' => 'DATE'];
            case static::PHINX_TYPE_INTERVAL:
                return ['name' => 'INTERVAL'];

            // numeric datatypes
            case static::PHINX_TYPE_BOOLEAN:
                return ['name' => 'NUMBER', 'limit' => 1];
            case static::PHINX_TYPE_SMALL_INTEGER:
                return ['name' => 'NUMBER', 'limit' => 6, 'scale' => 0];
            case static::PHINX_TYPE_INTEGER:
                return ['name' => 'NUMBER', 'limit' => 11, 'scale' => 0];
            case static::PHINX_TYPE_DECIMAL:
                return ['name' => 'NUMBER', 'limit' => 18, 'scale' => 0];
            case static::PHINX_TYPE_BIG_INTEGER:
                return ['name' => 'NUMBER', 'limit' => 24, 'scale' => 0];
            case static::PHINX_TYPE_FLOAT:
                return ['name' => 'FLOAT'];
            case static::PHINX_TYPE_DOUBLE:
                return ['name' => 'BINARY_DOUBLE'];

            // character datatypes
            case static::PHINX_TYPE_ENUM:
                return ['name' => 'VARCHAR2', 'limit' => '255 CHAR'];
            case static::PHINX_TYPE_TEXT:
                return ['name' => 'LONG'];
            case static::PHINX_TYPE_STRING:
                return ['name' => 'VARCHAR2', 'limit' => '2000 CHAR'];
            case static::PHINX_TYPE_UUID:
                return ['name' => 'RAW', 'limit' => 16, 'default' => 'SYS_GUID()'];
            case static::PHINX_TYPE_CHAR:
                return ['name' => 'CHAR', 'limit' => 255];

            // large object/binaries datatypes
            case static::PHINX_TYPE_BLOB:
                return ['name' => 'BLOB'];
            case 'CLOB':
                return ['name' => 'CLOB'];
            case static::PHINX_TYPE_BINARY:
                return ['name' => 'RAW', 'limit' => 2000];
            case static::PHINX_TYPE_VARBINARY:
            case static::PHINX_TYPE_FILESTREAM:
                return ['name' => 'varbinary', 'limit' => 'max'];

            // other datatypes
            /* @TODO : json/filestream
             * case static::PHINX_TYPE_JSON:
             * case static::PHINX_TYPE_FILESTREAM:
             * case static::PHINX_TYPE_JSONB:
             * case static::PHINX_TYPE_BIT:
             *
             * // Oracle SDO_GEOMETRY datatypes
             * @TODO : geometry
             * case static::PHINX_TYPE_GEOMETRY:
             * return ['name' => 'geography', 'type' => 'geometry', 'srid' => 4326];
             * case static::PHINX_TYPE_POINT:
             * return ['name' => 'SDO_POINT_TYPE'];
             * case static::PHINX_TYPE_LINESTRING:
             * return ['name' => 'geography', 'type' => 'linestring', 'srid' => 4326];
             * case static::PHINX_TYPE_POLYGON:
             * return ['name' => 'geography', 'type' => 'polygon', 'srid' => 4326];
             */
            default:
                if ($this->isArrayType($type)) {
                    return ['name' => $type];
                }
                // Return array type
                throw new RuntimeException('Column type `' . $type . '` is not supported by Oracle.');
        }
    }

    /**
     * Returns Phinx type by SQL type
     *
     * @param string $sqlType SQL Type definition
     * @param ?int $limit limit of NUMBER type to define Phinx Type.
     * @param ?int $scale Scale of NUMBER type to define Phinx Type.
     * @return string Phinx type
     * @throws \RuntimeException
     */
    public function getPhinxType(string $sqlType, ?int $limit = null, ?int $scale = null): string
    {
        $limit = (int)$limit;
        $scale = (int)$scale;

        if ($sqlType === 'VARCHAR2') {
            return static::PHINX_TYPE_STRING;
        } elseif ($sqlType === 'CHAR') {
            return static::PHINX_TYPE_CHAR;
        } elseif ($sqlType === 'LONG') {
            return static::PHINX_TYPE_TEXT;
        } elseif ($sqlType === 'NUMBER' && $limit === 1) {
            return static::PHINX_TYPE_BOOLEAN;
        } elseif ($sqlType === 'NUMBER' && $limit === 6 && $scale === 0) {
            return static::PHINX_TYPE_SMALL_INTEGER;
        } elseif ($sqlType === 'NUMBER' && $limit === 11 && $scale === 0) {
            return static::PHINX_TYPE_INTEGER;
        } elseif ($sqlType === 'NUMBER' && $limit === 18 && $scale === 0) {
            return static::PHINX_TYPE_DECIMAL;
        } elseif ($sqlType === 'NUMBER' && $limit === 24 && $scale === 0) {
            return static::PHINX_TYPE_BIG_INTEGER;
        } elseif ($sqlType === 'NUMBER') {
            return static::PHINX_TYPE_FLOAT;
        } elseif ($sqlType === 'BINARY_DOUBLE') {
            return static::PHINX_TYPE_DOUBLE;
        } elseif ($sqlType === 'TIMESTAMP') {
            return static::PHINX_TYPE_TIMESTAMP;
        } elseif ($sqlType === 'TIMESTAMP(6)') {
            return static::PHINX_TYPE_TIMESTAMP;
        } elseif ($sqlType === 'TIME') {
            return static::PHINX_TYPE_TIME;
        } elseif ($sqlType === 'DATE') {
            return static::PHINX_TYPE_DATE;
        } elseif ($sqlType === 'INTERVAL') {
            return static::PHINX_TYPE_INTERVAL;
        } elseif ($sqlType === 'BLOB') {
            return static::PHINX_TYPE_BLOB;
        } elseif ($sqlType === 'RAW' && $limit === 16) {
            return static::PHINX_TYPE_UUID;
        } elseif ($sqlType === 'RAW') {
            return static::PHINX_TYPE_BLOB;
        } else {
            throw new RuntimeException('The Oracle type: "' . $sqlType . ')" is not supported');
        }
    }

    /**
     * @inheritDoc
     */
    public function createDatabase(string $name, array $options = []): void
    {
        // @TODO : create SID ???
    }

    /**
     * @inheritDoc
     */
    public function hasDatabase(string $name): bool
    {
        // @TODO : checking another SID
        return true;
    }

    /**
     * @inheritDoc
     */
    public function dropDatabase(string $name): void
    {
        //TODO
    }

    /**
     * Get the definition for a `DEFAULT` statement.
     *
     * @param mixed $default default value
     * @param string|\Phinx\Util\Literal|null $columnType column type added
     * @return string
     */
    protected function getDefaultValueDefinition(mixed $default, string|Literal|null $columnType = null): string
    {
        if (is_string($default) && $default !== 'CURRENT_TIMESTAMP' && $default !== 'CURRENT_DATE') {
            $default = $this->getConnection()->quote($default);
        } elseif (is_bool($default)) {
            $default = $this->castToBool($default);
        } elseif ($columnType === static::PHINX_TYPE_BOOLEAN) {
            $default = $this->castToBool((bool)$default);
        }

        $timestamp = '';
        if (in_array($columnType, ['timestamp', 'time', 'date', 'datetime']) && $default !== 'CURRENT_TIMESTAMP' && $default !== 'CURRENT_DATE') {
            $timestamp = strlen($default) === 12 ? 'date ' : 'timestamp ';
        }

        return isset($default) ? $timestamp . $default : '';
    }

    /**
     * Gets the SQL Column Definition for a Column object.
     *
     * @param \Phinx\Db\Table\Column $column Column
     * @return string
     */
    protected function getColumnSqlDefinition(Column $column): string
    {
        $buffer = [];
        $sqlType = $this->getSqlType($column->getType());
        $buffer[] = strtoupper($sqlType['name']);
        /*
        // integers cant have limits in Oracle
        $noLimits = [
            static::PHINX_TYPE_INTEGER,
            static::PHINX_TYPE_SMALL_INTEGER,
            static::PHINX_TYPE_BIG_INTEGER,
            static::PHINX_TYPE_FLOAT,
            static::PHINX_TYPE_UUID,
            static::PHINX_TYPE_BOOLEAN
        ];
        if (!in_array($column->getType(), $noLimits) && ($column->getLimit() || isset($sqlType['limit']))) {
            $buffer[] = sprintf('(%s)', $column->getLimit() ?: $sqlType['limit']);
        }
        */
        // TODO check isset or NULL
        if ($column->getLimit() !== null || isset($sqlType['limit'])) {
            $buffer[] = '(';
            $buffer[] = $column->getLimit() ?: $sqlType['limit'];
            if ($column->getScale() !== null || isset($sqlType['scale'])) {
                $buffer[] = ',';
                $buffer[] = $column->getScale() ?: $sqlType['scale'];
            }
            $buffer[] = ')';
        }
        if ($column->isIdentity()) {
            $buffer[] = 'GENERATED BY DEFAULT ON NULL AS IDENTITY MINVALUE 1 MAXVALUE 999999999999999999999999 INCREMENT BY 1';
        } else {
            if ($column->getDefault() !== null) {
                $default = $this->getDefaultValueDefinition($column->getDefault(), $column->getType());
                if ($column->getDefaultOnNull()) {
                    $buffer[] = 'DEFAULT ON NULL ' . $default;
                } else {
                    $buffer[] = 'DEFAULT ' . $default . ' NOT NULL';
                }
            } elseif ($column->isNull() !== null) {
                if ($column->isNull()) {
                    $buffer[] = 'NULL';
                }
                if (!$column->isNull()) {
                    $buffer[] = 'NOT NULL';
                }
            }
        }

        return implode(' ', $buffer);
    }

    /**
     * Gets the Oracle Column Comment Definition for a column object.
     *
     * @param \Phinx\Db\Table\Column $column Column
     * @param string $tableName Table name
     * @return string
     */
    protected function getColumnCommentSqlDefinition(Column $column, string $tableName): string
    {
        // passing 'null' is to remove column comment
        $comment = strcasecmp($column->getComment(), '') !== 0
            ? $this->getConnection()->quote($column->getComment())
            : '';

        $sql = sprintf(
            'COMMENT ON COLUMN %s.%s IS ',
            $this->quoteSchemaTableName($tableName),
            $this->quoteColumnName($column->getName())
        );
        if ($comment === '') {
            $sql .= "''";
        } else {
            $sql .= $comment;
        }

        return $sql;
    }

    /**
     * Gets the SQL Index Definition for an Index object.
     *
     * @param \Phinx\Db\Table\Index $index Index
     * @param string $tableName Table name
     * @return string
     */
    protected function getIndexSqlDefinition(Index $index, string $tableName): string
    {
        $parts = $this->getSchemaName($tableName);

        if (is_string($index->getName())) {
            $indexName = $index->getName();
        } else {
            $columnNames = $index->getColumns();
            $indexName = sprintf('%s_%s', $parts['table'], implode('_', $columnNames));
        }
        $def = sprintf(
            'CREATE %s INDEX %s ON %s (%s)',
            ($index->getType() === Index::UNIQUE ? 'UNIQUE' : ''),
            $this->quoteColumnName($this->getShortName($indexName)),
            $this->quoteSchemaTableName($tableName),
            implode(',', array_map([$this, 'quoteIndexName'], $index->getColumns()))
        );

        return $def;
    }

    /**
     * Gets the Oracle Foreign Key Definition for an ForeignKey object.
     *
     * @param \Phinx\Db\Table\ForeignKey $foreignKey Foreign key
     * @param string $tableName Table name
     * @return string
     */
    protected function getForeignKeySqlDefinition(ForeignKey $foreignKey, string $tableName): string
    {
        $parts = $this->getSchemaName($tableName);

        $constraintName = $foreignKey->getConstraint() ?: ($parts['table'] . '_' . implode('_', $foreignKey->getColumns()) . '_FKEY');
        $def = ' CONSTRAINT ' . $this->quoteColumnName($this->getShortName($constraintName)) .
            ' FOREIGN KEY ("' . implode('", "', $this->upper ? array_map('strtoupper', $foreignKey->getColumns()) : $foreignKey->getColumns()) . '")' .
            " REFERENCES {$this->quoteSchemaTableName($foreignKey->getReferencedTable()->getName())} (\"" .
            implode('", "', $this->upper ? array_map('strtoupper', $foreignKey->getReferencedColumns()) : $foreignKey->getReferencedColumns()) . '")';
        if ($foreignKey->getOnDelete()) {
            $def .= " ON DELETE {$foreignKey->getOnDelete()}";
        }
        /*
        if ($foreignKey->getOnUpdate()) {
            $def .= " ON UPDATE {$foreignKey->getOnUpdate()}";
        }
        */
        return $def;
    }

    /**
     * Creates the specified schema.
     *
     * @param string $schemaName Schema Name
     * @return void
     */
    public function createSchema(string $schemaName = 'public'): void
    {
        throw new BadMethodCallException('Creating a schema is not supported');
    }

    /**
     * Checks to see if a schema exists.
     *
     * @param string $schemaName Schema Name
     * @return bool
     */
    public function hasSchema(string $schemaName): bool
    {
        $sql = sprintf(
            "SELECT count(*) as count FROM ALL_TABLES WHERE owner = '%s'",
            $schemaName
        );
        $result = $this->fetchRow($sql);

        return $result['COUNT'] > 0;
    }

    /**
     * Drops the specified schema table.
     *
     * @param string $schemaName Schema name
     * @return void
     */
    public function dropSchema(string $schemaName): void
    {
        // @TODO : delete user/schema
    }

    /**
     * Drops all schemas.
     *
     * @return void
     */
    public function dropAllSchemas(): void
    {
        // @TODO : delete everything
    }

    /**
     * Returns schemas.
     *
     * @return array
     */
    public function getAllSchemas(): array
    {
        $sql = 'SELECT DISTINCT owner FROM ALL_TABLES WHERE owner IS NOT NULL';
        $items = $this->fetchAll($sql);
        $schemaNames = [];
        foreach ($items as $item) {
            $schemaNames[] = $item['owner'];
        }

        return $schemaNames;
    }

    /**
     * @inheritDoc
     */
    public function isValidColumnType(Column $column): bool
    {
        // If not a standard column type, maybe it is array type?
        return parent::isValidColumnType($column) || $this->isArrayType($column->getType());
    }

    /**
     * Check if the given column is an array of a valid type.
     *
     * @param string|\Phinx\Util\Literal $columnType Column type
     * @return bool
     */
    protected function isArrayType(string|Literal $columnType): bool
    {
        if (!preg_match('/^([a-z]+)(?:\[\]){1,}$/', $columnType, $matches)) {
            return false;
        }

        $baseType = $matches[1];

        return in_array($baseType, $this->getColumnTypes(), true);
    }

    /**
     * Gets the schema name.
     *
     * @param string $tableName Table name
     * @return array
     */
    private function getSchemaName(string $tableName): array
    {
        $schema = $this->getGlobalSchemaName();
        $table = $tableName;

        if (str_contains($tableName, '.')) {
            [$schema, $table] = explode('.', $tableName);
        }

        return [
            'schema' => $this->checkUpper($schema),
            'table' => $this->checkUpper($table),
        ];
    }

    /**
     * Gets the default schema name.
     *
     * @return string
     */
    private function getGlobalSchemaName(): string
    {
        $options = $this->getOptions();
        //  set default schema name based on username
        return empty($options['schema']) ? $options['user'] : $options['schema'];
    }

    /**
     * @inheritDoc
     */
    public function castToBool($value): mixed
    {
        return (bool)$value ? 1 : 0;
    }

    /**
     * @inheritDoc
     * @throws \ReflectionException
     */
    public function getDecoratedConnection(): Connection
    {
        if (isset($this->decoratedConnection)) {
            return $this->decoratedConnection;
        }

        $options = $this->getOptions();
        $dsn = $this->getDsn();

        $driver = new OracleDriver($dsn, $options['user'], $options['pass']);
        $prop = new ReflectionProperty($driver, 'pdo');
        $prop->setValue($driver, $this->connection);

        return $this->decoratedConnection = new Connection(['driver' => $driver] + $options);
    }

    /**
     * @inheritDoc
     */
    public function getVersionLog(): array
    {
        $result = [];
        //TODO = Show names instead 0 and show dates to.
        switch ($this->options['version_order']) {
            case Config::VERSION_ORDER_CREATION_TIME:
                $orderBy = '"version" ASC';
                break;
            case Config::VERSION_ORDER_EXECUTION_TIME:
                $orderBy = '"start_time" ASC, "version" ASC';
                break;
            default:
                throw new RuntimeException('Invalid version_order configuration option');
        }
        $rows = $this->fetchAll(sprintf(
            'SELECT * FROM %s ORDER BY %s',
            $this->quoteSchemaTableName($this->getSchemaTableName()),
            $this->checkUpper($orderBy)
        ));
        foreach ($rows as $version) {
            $version = array_change_key_case($version, CASE_LOWER);
            $result[$version['version']] = $version;
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function insert(Table $table, array $row): void
    {
        $sql = sprintf(
            'INSERT INTO %s ',
            $this->quoteSchemaTableName($table->getName())
        );
        $columns = array_keys($row);
        $sql .= '(' . implode(', ', array_map([$this, 'quoteColumnName'], $columns)) . ')';

        foreach ($row as $column => $value) {
            if (is_bool($value)) {
                $row[$column] = $this->castToBool($value);
            }
        }
        $times = [];
        $columnDetails = $this->getColumns($table->getName());
        foreach ($columnDetails as $col) {
            if (in_array($col->getType(), ['timestamp', 'time', 'date', 'datetime'])) {
                $times[] = $col->getName();
            }
        }
        $schema = [];
        foreach ($row as $column => $value) {
            if (in_array(strtoupper($column), $times, true)) {
                if (strlen($value) === 10) {
                    $schema[] = 'date \'' . $value . '\'';
                } else {
                    $schema[] = 'timestamp \'' . $value . '\'';
                }
            } else {
                $schema[] = '\'' . $value . '\'';
            }
        }
        //@TODO : dry run enabled
        //$sql .= ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql .= ' VALUES (' . implode(', ', $schema) . ')';
        $this->execute($sql);
    }

    /**
     * @inheritDoc
     */
    public function bulkinsert(Table $table, $rows): void
    {
        foreach ($rows as $row) {
            $this->insert($table, $row);
        }
    }

    /**
     * @inheritDoc
     */
    public function migrated(MigrationInterface $migration, string $direction, string $startTime, string $endTime): AdapterInterface
    {
        if (strcasecmp($direction, MigrationInterface::UP) === 0) {
            // up
            $sql = sprintf(
                "INSERT INTO %s (%s, %s, %s, %s, %s) VALUES
                ('%s', '%s', timestamp '%s', timestamp '%s', %s)",
                $this->quoteSchemaTableName($this->getSchemaTableName()),
                $this->quoteColumnName('version'),
                $this->quoteColumnName('migration_name'),
                $this->quoteColumnName('start_time'),
                $this->quoteColumnName('end_time'),
                $this->quoteColumnName('breakpoint'),
                $migration->getVersion(),
                substr($migration->getName(), 0, 100),
                $startTime,
                $endTime,
                $this->castToBool(false)
            );
        } else {
            // down
            $sql = sprintf(
                "DELETE FROM %s WHERE %s = '%s'",
                $this->quoteSchemaTableName($this->getSchemaTableName()),
                $this->quoteColumnName('version'),
                $migration->getVersion()
            );
        }

        $this->execute($sql);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function hasPrimaryKey(string $tableName, string|array $columns, ?string $constraint = null): bool
    {
        $primaryKey = $this->getPrimaryKey($tableName);

        if (empty($primaryKey['constraint'])) {
            return false;
        }

        if ($constraint) {
            return $primaryKey['constraint'] === $constraint;
        }

        if (is_string($columns)) {
            $columns = [$columns]; // str to array
        }

        if ($this->getUpper()) {
            $columns = array_map('strtoupper', $columns);
        }

        $missingColumns = array_diff($columns, $primaryKey['columns']);

        return empty($missingColumns);
    }

    /**
     * @param string $tableName
     * @return array
     */
    public function getPrimaryKey(string $tableName): array
    {
        $parts = $this->getSchemaName($tableName);

        $keys = [];
        $sql = sprintf(
            "SELECT
   all_cons_columns.owner as schema_name,
   all_cons_columns.table_name,
   all_cons_columns.column_name,
   all_cons_columns.constraint_name,
   all_cons_columns.position,
   all_constraints.status
FROM all_constraints, all_cons_columns
WHERE
   all_constraints.constraint_type = 'P'
   AND all_constraints.constraint_name = all_cons_columns.constraint_name
   AND all_constraints.owner = all_cons_columns.owner
   AND all_cons_columns.table_name = '%s' AND all_cons_columns.owner = '%s'
ORDER BY
   all_cons_columns.owner,
   all_cons_columns.table_name,
   all_cons_columns.position
    }",
            $parts['table'],
            $parts['schema'],
        );

        $rows = $this->fetchAll($sql);
        foreach ($rows as $row) {
            if (!isset($keys[$row['SCHEMA_NAME']])) {
                $keys[$row['SCHEMA_NAME']] = [
                    'columns' => [],
                    'constraint' => $row['CONSTRAINT_NAME'],
                ];
            }
            $keys[$row['SCHEMA_NAME']]['columns'][] = $row['COLUMN_NAME'];
        }

        return $keys;
    }

    /**
     * @inheritDoc
     */
    public function getChangePrimaryKeyInstructions(Table $table, string|array|null $newColumns): AlterInstructions
    {
        throw new BadMethodCallException('TODO - getChangePrimaryKeyInstructions is not supported');
    }

    /**
     * @inheritDoc
     */
    public function getChangeCommentInstructions(Table $table, ?string $newComment): AlterInstructions
    {
        throw new BadMethodCallException('TODO - getChangeCommentInstructions is not supported');
    }

    /**
     * Returns Oracle column types
     *
     * @return array
     */
    public function getColumnTypes(): array
    {
        //enum -> converted to string
        return array_merge(parent::getColumnTypes(), ['enum']);
    }
}
