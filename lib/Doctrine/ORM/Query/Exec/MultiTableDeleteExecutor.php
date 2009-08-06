<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Query\Exec;

use Doctrine\ORM\Query\AST;

/**
 * Executes the SQL statements for bulk DQL DELETE statements on classes in
 * Class Table Inheritance (JOINED).
 *
 * @author      Roman Borschel <roman@code-factory.org>
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        http://www.doctrine-project.org
 * @since       2.0
 * @version     $Revision$
 */
class MultiTableDeleteExecutor extends AbstractSqlExecutor
{
    private $_createTempTableSql;
    private $_dropTempTableSql;
    private $_insertSql;
    
    /**
     * Initializes a new <tt>MultiTableDeleteExecutor</tt>.
     *
     * @param Node $AST The root AST node of the DQL query.
     * @param SqlWalker $sqlWalker The walker used for SQL generation from the AST.
     * @internal Any SQL construction and preparation takes place in the constructor for
     *           best performance. With a query cache the executor will be cached.
     */
    public function __construct(AST\Node $AST, $sqlWalker)
    {
        $em = $sqlWalker->getEntityManager();
        $conn = $em->getConnection();
        
        $primaryClass = $sqlWalker->getEntityManager()->getClassMetadata(
            $AST->deleteClause->abstractSchemaName
        );
        $primaryDqlAlias = $AST->deleteClause->aliasIdentificationVariable;
        $rootClass = $em->getClassMetadata($primaryClass->rootEntityName);
        
        $tempTable = $rootClass->getTemporaryIdTableName();
        $idColumnNames = $rootClass->getIdentifierColumnNames();
        $idColumnList = implode(', ', $idColumnNames);

        // 1. Create an INSERT INTO temptable ... SELECT identifiers WHERE $AST->getWhereClause()
        $this->_insertSql = 'INSERT INTO ' . $tempTable . ' (' . $idColumnList . ')'
                . ' SELECT t0.' . implode(', t0.', $idColumnNames);
        $sqlWalker->setSqlTableAlias($primaryClass->primaryTable['name'] . $primaryDqlAlias, 't0');
        $rangeDecl = new AST\RangeVariableDeclaration($primaryClass, $primaryDqlAlias);
        $fromClause = new AST\FromClause(array(new AST\IdentificationVariableDeclaration($rangeDecl, null, array())));
        $this->_insertSql .= $sqlWalker->walkFromClause($fromClause);
        
        // Append WHERE clause, if there is one.
        if ($AST->whereClause) {
            $this->_insertSql .= $sqlWalker->walkWhereClause($AST->whereClause);
        }

        // 2. Create ID subselect statement used in DELETE .... WHERE ... IN (subselect)
        $idSubselect = 'SELECT ' . $idColumnList . ' FROM ' . $tempTable;

        // 3. Create and store DELETE statements
        $classNames = array_merge($primaryClass->parentClasses, array($primaryClass->name), $primaryClass->subClasses);
        foreach (array_reverse($classNames) as $className) {
            $tableName = $em->getClassMetadata($className)->primaryTable['name'];
            $this->_sqlStatements[] = 'DELETE FROM ' . $conn->quoteIdentifier($tableName)
                    . ' WHERE (' . $idColumnList . ') IN (' . $idSubselect . ')';
        }
    
        // 4. Store DDL for temporary identifier table.
        $columnDefinitions = array();
        foreach ($idColumnNames as $idColumnName) {
            $columnDefinitions[$idColumnName] = array(
                'notnull' => true,
                'type' => \Doctrine\DBAL\Types\Type::getType($rootClass->getTypeOfColumn($idColumnName))
            );
        }
        $this->_createTempTableSql = 'CREATE TEMPORARY TABLE ' . $tempTable . ' ('
                . $conn->getDatabasePlatform()->getColumnDeclarationListSql($columnDefinitions)
                . ', PRIMARY KEY(' . $idColumnList . '))';
        $this->_dropTempTableSql = 'DROP TABLE ' . $tempTable;
    }

    /**
     * Executes all SQL statements.
     *
     * @param Doctrine\DBAL\Connection $conn The database connection that is used to execute the queries.
     * @param array $params The parameters.
     * @override
     */
    public function execute(\Doctrine\DBAL\Connection $conn, array $params)
    {
        $numDeleted = 0;
        
        // Create temporary id table
        $conn->exec($this->_createTempTableSql);
        
        // Insert identifiers
        $numDeleted = $conn->exec($this->_insertSql, $params);

        // Execute DELETE statements
        foreach ($this->_sqlStatements as $sql) {
            $conn->exec($sql);
        }
        
        // Drop temporary table
        $conn->exec($this->_dropTempTableSql);
        
        return $numDeleted;
    }
}