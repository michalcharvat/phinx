<?php
declare(strict_types=1);

namespace Test\Phinx\Db\Adapter;

use InvalidArgumentException;
use PDO;
use Phinx\Db\Adapter\OracleAdapter;
use Phinx\Db\Table;
use Phinx\Db\Table\Column;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class OracleAdapterTest extends TestCase
{
    /**
     * @var \Phinx\Db\Adapter\OracleAdapter
     */
    private $adapter;

    /**
     * Check if Oracle is enabled in the current PHP
     *
     * @return bool
     */
    private static function isOracleAvailable(): bool
    {
        static $available;

        if ($available === null) {
            $available = in_array('oci8', PDO::getAvailableDrivers(), true);
        }

        return $available;
    }

    protected function setUp(): void
    {
        if (!defined('ORACLE_DB_CONFIG')) {
            $this->markTestSkipped('Oracle tests disabled.');
        }

        if (!self::isOracleAvailable()) {
            $this->markTestSkipped('Oracle is not available.  Please install php-oci8 or equivalent package.');
        }

        $this->adapter = new OracleAdapter(ORACLE_DB_CONFIG, new ArrayInput([]), new NullOutput());
        $this->adapter->getConnection();

        // leave the adapter in a disconnected state for each test
        $this->adapter->disconnect();
    }

    /**
     * Test if it is a valid object
     */
    public function testObject(): void
    {
        $this->assertNotNull($this->adapter);
    }

    /**
     * Test if can connect
     */
    public function testConnection(): void
    {
        $this->assertInstanceOf('PDO', $this->adapter->getConnection());
    }

    /**
     * Tear Down
     */
    protected function tearDown(): void
    {
        unset($this->adapter);
    }

    /**
     * Test if can connect without port
     */
    public function testConnectionWithoutPort()
    {
        $options = $this->adapter->getOptions();
        unset($options['port']);
        $this->adapter->setOptions($options);
        $this->assertInstanceOf('PDO', $this->adapter->getConnection());
    }

    /**
     * Test if cannot connect without Invalid Credentials
     */
    public function testConnectionWithInvalidCredentials(): void
    {
        $options = ['user' => 'invalidu', 'pass' => 'invalid'] + PGSQL_DB_CONFIG;

        try {
            $adapter = new OracleAdapter($options, new ArrayInput([]), new NullOutput());
            $adapter->connect();
            $this->fail('Expected the adapter to throw an exception');
        } catch (InvalidArgumentException $e) {
            $this->assertInstanceOf(
                'InvalidArgumentException',
                $e,
                'Expected exception of type InvalidArgumentException, got ' . get_class($e)
            );
            $this->assertStringContainsString('There was a problem connecting to the database', $e->getMessage());
        }
    }

    public function testGetUpper(): void
    {
        $this->assertTrue($this->adapter->getUpper());
    }

    public function testQuoteSchemaName(): void
    {
        $this->assertEquals(
            $this->adapter->checkUpper('"test_table"'),
            $this->adapter->quoteSchemaName('test_table')
        );
    }

    public function testQuoteSchemaTableName(): void
    {
        $this->assertEquals(
            $this->adapter->checkUpper('"test_schema"."test_table"'),
            $this->adapter->quoteSchemaTableName('test_schema.test_table')
        );

        $this->assertEquals($this->adapter->checkUpper('"test_table"'), $this->adapter->quoteSchemaTableName('.test_table'));

        $options = $this->adapter->getOptions();
        $options['schema'] = 'test_default_schema';
        $this->adapter->setOptions($options);
        $this->assertEquals($this->adapter->checkUpper('"test_default_schema"."test_table"'), $this->adapter->quoteSchemaTableName('test_table'));
    }

    public function testQuoteTableName(): void
    {
        $this->assertEquals($this->adapter->checkUpper('"test_table"'), $this->adapter->quoteTableName('test_table'));
    }

    public function testHasTable(): void
    {
        $this->assertTrue($this->adapter->hasTable('phinxlog'));
    }

    public function testQuoteColumnName(): void
    {
        $this->assertEquals($this->adapter->checkUpper('"test_column"'), $this->adapter->quoteColumnName('test_column'));
    }

    public function testSchemaTableIsCreatedWithPrimaryKey(): void
    {
        $this->adapter->connect();
        $this->assertTrue($this->adapter->hasIndex($this->adapter->getSchemaTableName(), $this->adapter->getUpper() ? ['VERSION'] : ['version']));
        $this->adapter->disconnect();
    }

    public function testCreatingIdentityTableWithDefaultID(): void
    {
        // default UID is set to "UID" based on FreshFlow requirements
        $table = new Table('identity_table_dtest', [], $this->adapter);
        $table->addColumn('email', 'string')
            ->save();
        $this->assertTrue($this->adapter->hasTable('identity_table_dtest'));
        // assert hascolumn
        // drop table

        $this->adapter->dropTable('identity_table_dtest');
        $this->assertFalse($this->adapter->hasTable('identity_table_dtest'));
    }

    public function testCreatingIdentityTableWithCustomID(): void
    {
        $options = $this->adapter->getOptions();
        $options['id'] = 'UberID';
        $table = new Table('identity_table_ctest', $options, $this->adapter);
        $table->addColumn('email', 'string')
            ->save();
        $this->assertTrue($this->adapter->hasTable('identity_table_ctest'));
        // assert hascolumn
        // drop table

        $this->adapter->dropTable('identity_table_ctest');
        $this->assertFalse($this->adapter->hasTable('identity_table_ctest'));
    }

    public function testCreateTable(): void
    {
        $table = new Table('NTABLE', [], $this->adapter);
        $table->addColumn('realname', 'string')
            ->addColumn('email', 'integer')
            ->save();
        $this->assertTrue($this->adapter->hasTable('NTABLE'));
        $this->assertTrue($this->adapter->hasColumn('NTABLE', 'UID'));
        $this->assertTrue($this->adapter->hasColumn('NTABLE', 'realname'));
        $this->assertTrue($this->adapter->hasColumn('NTABLE', 'email'));
        $this->assertFalse($this->adapter->hasColumn('NTABLE', 'address'));
        $this->adapter->dropTable('NTABLE');
    }

    /**
     * @return void
     */
    public function testCreateTableLower(): void
    {
        $this->adapter->setUpper(false);
        $table = new Table('t', [], $this->adapter);
        $table->addColumn('column1', 'integer')
            ->save();
        $newColumn1 = new Column();
        $newColumn1
            ->setType('string')
            ->setDefault(0);
        $table->changeColumn('column1', $newColumn1)->save();
        $columns = $this->adapter->getColumns('t');
        $this->adapter->dropTable('t');
        $this->assertSame(0, (int)$columns['column1']->getDefault());
        $this->adapter->setUpper(true);
    }

    /**
     * @return void
     */
    public function testTableWithoutIndexesByName(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->addColumn('EMAIL', 'string')
            ->save();
        $this->assertFalse($this->adapter->hasIndexByName('TABLE1', strtoupper('MYEMAILINDEX')));
        $this->adapter->dropTable('TABLE1');
    }

    /**
     * @return void
     */
    public function testRenameTable(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->save();
        $this->assertTrue($this->adapter->hasTable('TABLE1'));
        $this->assertFalse($this->adapter->hasTable('TABLE2'));
        $this->adapter->renameTable('TABLE1', 'TABLE2');
        $this->assertFalse($this->adapter->hasTable('TABLE1'));
        $this->assertTrue($this->adapter->hasTable('TABLE2'));
        $this->adapter->dropTable('TABLE2');
    }

    /**
     * @return void
     */
    public function testAddColumn(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('email'));
        $table->addColumn('email', 'string')
            ->save();
        $this->assertTrue($table->hasColumn('email'));
        $this->adapter->dropTable('TABLE1');
    }

    public function testAddColumnWithDefaultValue(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->save();
        $table->addColumn('default_value', 'string', ['default' => 'test'])
            ->save();
        $columns = $this->adapter->getColumns('TABLE1');
        foreach ($columns as $column) {
            if ($column->getName() === $this->adapter->checkUpper('default_value')) {
                $this->assertEquals("'test'", trim($column->getDefault()));
            }
        }
        $this->adapter->dropTable('TABLE1');
    }

    /**
     * @return void
     */
    public function testAddColumnWithDefaultZero(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->save();
        $table->addColumn('default_zero', 'integer', ['default' => 0])
            ->save();
        $columns = $this->adapter->getColumns('TABLE1');
        foreach ($columns as $column) {
            if ($column->getName() === $this->adapter->checkUpper('default_zero')) {
                $this->assertNotNull($column->getDefault());
                $this->assertEquals('0', trim($column->getDefault()));
            }
        }
        $this->adapter->dropTable('TABLE1');
    }

    /**
     * @return void
     */
    public function testAddColumnWithDefaultNull(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->save();
        $table->addColumn('default_null', 'string', ['null' => true, 'default' => null])
            ->save();
        $columns = $this->adapter->getColumns('TABLE1');
        foreach ($columns as $column) {
            if ($column->getName() === $this->adapter->checkUpper('default_null')) {
                $this->assertEquals('', trim($column->getDefault()));
            }
        }
        $this->adapter->dropTable('TABLE1');
    }

    public function testAddColumnWithDefaultOnNull(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->addColumn('default_on_null', 'string', ['null' => false, 'default' => 'test', 'defaultOnNull' => true])
            ->save();
        $columns = $this->adapter->getColumns('TABLE1');
        foreach ($columns as $column) {
            if ($column->getName() === $this->adapter->checkUpper('default_on_null')) {
                $this->assertNotNull($column->getDefault());
            }
        }
        $date = date('Y-m-d H:i:s');
        $table->addColumn('default_on_null_date', 'timestamp', ['null' => false, 'default' => $date, 'defaultOnNull' => true]);
        $table->addColumn('default_1', 'string', ['null' => false, 'default' => 'teststringer', 'defaultOnNull' => true]);
        $data = [
            [
                'default_1' => 'stringer',
            ],
        ];
        $table->save();
        $table->insert($data);
        $table->saveData();
        $row = $this->adapter->fetchRow("SELECT default_on_null,
                                                to_char(default_on_null_date,'YYYY-MM-DD HH24:MI:SS') default_on_null_date
                                         FROM table1");
        $this->assertSame('test', $this->adapter->getUpper() ? $row['DEFAULT_ON_NULL'] : $row['default_on_null']);
        $this->assertSame($date, $this->adapter->getUpper() ? $row['DEFAULT_ON_NULL_DATE'] : $row['default_on_null_date']);
        $this->adapter->dropTable('TABLE1');
    }

    public function testAddColumnWithDefaultBool(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->save();
        $table
            ->addColumn('default_false', 'integer', ['default' => false])
            ->addColumn('default_true', 'integer', ['default' => true])
            ->save();
        $columns = $this->adapter->getColumns('TABLE1');
        foreach ($columns as $column) {
            if ($column->getName() === $this->adapter->checkUpper('default_false')) {
                $this->assertSame(0, (int)trim($column->getDefault()));
            }
            if ($column->getName() === $this->adapter->checkUpper('default_true')) {
                $this->assertSame(1, (int)trim($column->getDefault()));
            }
        }
        $this->adapter->dropTable('TABLE1');
    }

    public function testRenameColumn(): void
    {
        $table = new Table('T', [], $this->adapter);
        $table->addColumn('column1', 'string')
            ->save();
        $this->assertTrue($this->adapter->hasColumn('T', 'column1'));
        $this->assertFalse($this->adapter->hasColumn('T', 'column2'));
        $this->adapter->renameColumn('T', 'column1', 'column2');
        $this->assertFalse($this->adapter->hasColumn('T', 'column1'));
        $this->assertTrue($this->adapter->hasColumn('T', 'column2'));
        $this->adapter->dropTable('T');
    }

    public function testRenamingANonExistentColumn(): void
    {
        $table = new Table('T', [], $this->adapter);
        $table->addColumn('column1', 'string')
            ->save();
        try {
            $this->adapter->renameColumn('T', 'column2', 'column1');
            $this->fail('Expected the adapter to throw an exception');
        } catch (InvalidArgumentException $e) {
            $this->assertInstanceOf(
                'InvalidArgumentException',
                $e,
                'Expected exception of type InvalidArgumentException, got ' . get_class($e)
            );
            $this->assertEquals('The specified column does not exist: column2', $e->getMessage());
        }
        $this->adapter->dropTable('T');
    }

    public function testChangeColumnDefaults(): void
    {
        $table = new Table('T', [], $this->adapter);
        $table->addColumn('column1', 'string', ['default' => 'test'])
            ->save();
        $this->assertTrue($this->adapter->hasColumn('T', 'column1'));
        $columns = $this->adapter->getColumns('T');
        $this->assertSame("'test'", trim($this->adapter->getUpper() ? $columns['COLUMN1']->getDefault() : $columns['column1']->getDefault()));
        $newColumn1 = new Column();
        $newColumn1
            ->setType('string')
            ->setDefault('another test');
        $table->changeColumn('column1', $newColumn1)->save();
        $this->assertTrue($this->adapter->hasColumn('T', 'column1'));
        $columns = $this->adapter->getColumns('T');
        $this->assertSame("'another test'", trim($this->adapter->getUpper() ? $columns['COLUMN1']->getDefault() : $columns['column1']->getDefault()));
        $this->adapter->dropTable('T');
    }

    public function testChangeColumnDefaultToNull(): void
    {
        $table = new Table('T', [], $this->adapter);
        $table->addColumn('column1', 'string', ['null' => false, 'default' => 'test'])
            ->save();
        $newColumn1 = new Column();
        $newColumn1
            ->setType('string')
            ->setNull(true)
            ->setDefault(null);
        $table->changeColumn('column1', $newColumn1)->save();
        $columns = $this->adapter->getColumns('T');
        $this->adapter->dropTable('T');
        $this->assertNull($this->adapter->getUpper() ? $columns['COLUMN1']->getDefault() : $columns['column1']->getDefault());
    }

    public function testChangeColumnDefaultToZero(): void
    {
        $table = new Table('T', [], $this->adapter);
        $table->addColumn('column1', 'integer')
            ->save();
        $newColumn1 = new Column();
        $newColumn1
            ->setType('string')
            ->setDefault(0);
        $table->changeColumn('column1', $newColumn1)->save();
        $columns = $this->adapter->getColumns('T');
        $this->adapter->dropTable('T');
        $this->assertSame(0, $this->adapter->getUpper() ? (int)$columns['COLUMN1']->getDefault() : (int)$columns['column1']->getDefault());
    }

    public function testDropColumn(): void
    {
        $table = new Table('T', [], $this->adapter);
        $table->addColumn('column1', 'string')
            ->save();
        $this->assertTrue($this->adapter->hasColumn('T', 'column1'));
        $this->adapter->dropColumn('T', 'column1');
        $this->adapter->dropTable('T');
        $this->assertFalse($this->adapter->hasColumn('T', 'column1'));
    }

    public function columnsProvider(): array
    {
        return [
            ['column1', 'string', ['null' => true, 'default' => null]],
            ['column2', 'integer', ['default' => 0]],
            ['column3', 'biginteger', ['default' => 5]],
            ['column4', 'text', ['default' => 'text']],
            ['column5', 'float', []],
            ['column6', 'decimal', []],
            ['column7', 'date', []],
            ['column9', 'timestamp', []],
            ['column11', 'blob', []],
            ['column12', 'boolean', []],
            ['column13', 'string', ['limit' => 10]],
        ];
    }

    public function returnTypes(): void
    {
    }

    /**
     * @dataProvider columnsProvider
     */
    public function testGetColumns($colName, $type, array $options): void
    {
        $colName = $this->adapter->checkUpper($colName);

        $table = new Table('T', [], $this->adapter);
        $table->addColumn($colName, $type, $options)->save();

        $columns = $this->adapter->getColumns('T');
        $this->assertEquals($colName, $columns[$colName]->getName());
        $this->assertEquals($type, $columns[$colName]->getType());

        $this->assertNull($this->adapter->getUpper() ? $columns['COLUMN1']->getDefault() : $columns['column1']->getDefault());
    }

    public function testAddForeignKey1(): void
    {
        $table = new Table('f_events', [], $this->adapter);

        if (!$table->hasForeignKey('source_event_id')) {
            $table->addForeignKeyWithName(
                'fk_source_event',
                'source_event_id',
                'f_events',
                'uid',
                [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                ]
            );
            $table->save();
        }
    }

    public function testAddForeignKey2(): void
    {
        $table = new Table('f_events', [], $this->adapter);

        if (!$table->hasForeignKey('source_task_id')) {
            $table->addForeignKeyWithName(
                'fk_source_task',
                'source_task_id',
                'f_tasks',
                'uid',
                [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                ]
            );
            $table->save();
        }
    }

    public function testAddForeignKey(): void
    {
        $refTable = new Table('ref_table', [], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer')
            ->addForeignKey(['ref_table_id'], 'ref_table', ['UID'])
            ->save();
        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), $this->adapter->getUpper() ? ['REF_TABLE_ID'] : ['ref_table_id']));
        $this->adapter->dropTable('table');
        $this->adapter->dropTable('ref_table');
    }

    public function testDropForeignKey(): void
    {
        $refTable = new Table('ref_table', [], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer')
            ->addForeignKey(['ref_table_id'], 'ref_table', ['UID'])
            ->save();
        $table->dropForeignKey($this->adapter->getUpper() ? ['REF_TABLE_ID'] : ['ref_table_id'])->save();
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), $this->adapter->getUpper() ? ['REF_TABLE_ID'] : ['ref_table_id']));
        $this->adapter->dropTable('table');
        $this->adapter->dropTable('ref_table');
    }

    public function testStringDropForeignKey(): void
    {
        $refTable = new Table('ref_table', [], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer')
            ->addForeignKey(['ref_table_id'], 'ref_table', ['UID'])
            ->save();
        $table->dropForeignKey('ref_table_id')->save();
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), ['ref_table_id']));
        $this->adapter->dropTable('table');
        $this->adapter->dropTable('ref_table');
    }

    public function testInvalidSqlType(): void
    {
        $this->adapter->getSqlType('idontexist');
    }

    public function testGetSqlType(): void
    {
        $this->assertEquals(['name' => 'CHAR', 'limit' => 255], $this->adapter->getSqlType('char'));
        $this->assertEquals(['name' => 'TIMESTAMP', 'limit' => 6], $this->adapter->getSqlType('time'));
        $this->assertEquals(['name' => 'BLOB'], $this->adapter->getSqlType('blob'));
        $this->assertEquals(
            [
                'name' => 'RAW',
                'precision' => 16,
                'scale' => 0,
            ],
            $this->adapter->getSqlType('uuid')
        );
    }

    public function testGetPhinxType(): void
    {
        $this->assertEquals('integer', $this->adapter->getPhinxType('NUMBER', 11));
        $this->assertEquals('biginteger', $this->adapter->getPhinxType('NUMBER', 20));
        $this->assertEquals('decimal', $this->adapter->getPhinxType('NUMBER', 18));
        $this->assertEquals('float', $this->adapter->getPhinxType('NUMBER'));
        $this->assertEquals('boolean', $this->adapter->getPhinxType('NUMBER', 5));
        $this->assertEquals('string', $this->adapter->getPhinxType('VARCHAR2'));
        $this->assertEquals('char', $this->adapter->getPhinxType('CHAR'));
        $this->assertEquals('text', $this->adapter->getPhinxType('LONG'));
        $this->assertEquals('timestamp', $this->adapter->getPhinxType('TIMESTAMP(6)'));
        $this->assertEquals('date', $this->adapter->getPhinxType('DATE'));
        $this->assertEquals('blob', $this->adapter->getPhinxType('BLOB'));
    }

    public function testAddColumnComment(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->addColumn('field1', 'string', ['comment' => $comment = 'Comments from column "field1"'])
            ->save();
        $resultComment = $this->adapter->getColumnComment('TABLE1', $this->adapter->getUpper() ? 'FIELD1' : 'field1');
        $this->adapter->dropTable('TABLE1');
        $this->assertEquals($comment, $resultComment, 'Dont set column comment correctly');
    }

    /**
     * @depends testAddColumnComment
     */
    public function testGetColumnCommentEmptyReturn(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->addColumn('field1', 'string', ['comment' => ''])
            ->save();
        $resultComment = $this->adapter->getColumnComment('TABLE1', $this->adapter->getUpper() ? 'FIELD1' : 'field1');
        $this->adapter->dropTable('TABLE1');
        $this->assertEquals('', $resultComment, '');
    }

    /**
     * @depends testAddColumnComment
     */
    public function testChangeColumnComment(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->addColumn('field1', 'string', ['comment' => 'Comments from column "field1"'])
            ->save();
        $table->changeColumn('field1', 'string', ['comment' => $comment = 'New Comments from column "field1"'])
            ->save();
        $resultComment = $this->adapter->getColumnComment('TABLE1', $this->adapter->getUpper() ? 'FIELD1' : 'field1');
        $this->adapter->dropTable('TABLE1');
        $this->assertEquals($comment, $resultComment, 'Dont change column comment correctly');
    }

    /**
     * @depends testAddColumnComment
     */
    public function testRemoveColumnComment(): void
    {
        $table = new Table('TABLE1', [], $this->adapter);
        $table->addColumn('field1', 'string', ['comment' => 'Comments from column "field1"'])
            ->save();
        $table->changeColumn('field1', 'string', ['comment' => ''])
            ->save();
        $resultComment = $this->adapter->getColumnComment('TABLE1', $this->adapter->getUpper() ? 'FIELD1' : 'field1');
        $this->adapter->dropTable('TABLE1');
        $this->assertEmpty($resultComment, 'Dont remove column comment correctly');
    }

    /**
     * Test that column names are properly escaped when creating Foreign Keys
     */
    public function testForignKeysArePropertlyEscaped(): void
    {
        $userId = 'USER123';
        $sessionId = 'SESSION123';
        $local = new Table('USERS', ['primary_key' => $userId, 'id' => $userId], $this->adapter);
        $local->create();
        $foreign = new Table(
            'SESSIONS123',
            ['primary_key' => $sessionId, 'id' => $sessionId],
            $this->adapter
        );
        $foreign->addColumn('USER123', 'integer')
            ->addForeignKey('USER123', 'USERS', $userId, ['constraint' => 'USER_SESSION_ID'])
            ->create();
        $this->assertTrue($foreign->hasForeignKey('USER123'));
        $this->adapter->dropTable('SESSIONS123');
        $this->adapter->dropTable('USERS');
    }

    /**
     * Test that column names are properly escaped when creating Foreign Keys
     */
    public function testDontHasForeignKey(): void
    {
        $userId = 'USER123';
        $sessionId = 'SESSION123';
        $local = new Table('USERS', ['primary_key' => $userId, 'id' => $userId], $this->adapter);
        $local->create();
        $foreign = new Table(
            'SESSIONS123',
            ['primary_key' => $sessionId, 'id' => $sessionId],
            $this->adapter
        );
        $foreign->addColumn('USER123', 'integer')
            ->addForeignKey('USER123', 'USERS', $userId, ['constraint' => 'USER_SESSION_ID'])
            ->create();
        $this->assertFalse($foreign->hasForeignKey('USER123', 'a'));
        $this->adapter->dropTable('SESSIONS123');
        $this->adapter->dropTable('USERS');
    }

    public function testBulkInsertData(): void
    {
        $data = [
            [
                'column1' => 'crievko',
                'column2' => 6,
                'column3' => '2018-06-06',
                'column4' => '2018-06-06 15:45:45',
            ],
            [
                'column1' => 'bambulka',
                'column2' => 7,
                'column3' => '2018-07-06',
                'column4' => '2018-07-06 15:45:45',
            ],
            [
                'column1' => 'stromcek',
                'column2' => 8,
                'column3' => '2018-08-06',
                'column4' => '2018-08-06 15:45:45',
            ],
        ];
        $table = new Table('table1', [], $this->adapter);
        $table->addColumn('column1', 'string', ['default' => 'test'])
            ->addColumn('column2', 'integer', ['null' => false, 'default' => 5])
            ->addColumn('column3', 'date', ['default' => '2018-05-05'])
            ->addColumn('column4', 'timestamp', ['default' => '2018-05-05 15:23:00'])
            ->insert($data)
            ->save();
    }

    public function testShortNames(): void
    {
        $table = new Table('super_long_table_name_which_will_be_shorten', [], $this->adapter);
        $table->addColumn('ultra_long_column_name_which_will_be_shorten', 'string', ['default' => 'test_default_value__which_not_will__be_shorten'])
            ->addColumn('more_ultra_long_column_name_which_will_be_shorten', 'integer', ['null' => false, 'default' => 5])
            ->save();
        var_dump($table->getColumns());
    }
}
