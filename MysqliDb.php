<?php
/**
 * MysqliDb to PDO Class
 *
 * @category  Database Access
 * @package   PDO
 * @author    Georgi Nikolov <georgi.data@gmail.com>
 * @copyright Copyright (c) 2013
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0
 **/
class MysqliDb
{
    /**
     * Static instance of self
     *
     * @var MysqliDb
     */
    protected static $_instance;
    /**
     * MySQLi instance
     *
     * @var mysqli
     */
    protected $_pdo;
    /**
     * The SQL query to be prepared and executed
     *
     * @var string
     */
    protected $_query;
    /**
     * An array that holds where conditions 'fieldname' => 'value'
     *
     * @var array
     */
    protected $_where = array();
    /**
     * Dynamic type list for where condition values
     *
     * @var array
     */
    protected $_whereTypeList;
    /**
     * Dynamic type list for table data values
     *
     * @var array
     */
    protected $_paramTypeList;
    /**
     * Dynamic array that holds a combination of where condition/table data value types and parameter referances
     *
     * @var array
     */
    protected $_bindParams = array(''); // Create the empty 0 index

    /**
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string $db
     * @param int $port
     */
    public function __construct($host, $username, $password, $db, $port = NULL)
    {        
        $this->_pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $username, $password, array(PDO::ATTR_EMULATE_PREPARES => false,PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $this->_pdo->exec("set names utf8");
        self::$_instance = $this;
    }

    /**
     * A method of returning the static instance to allow access to the
     * instantiated object from within another class.
     * Inheriting this class would require reloading connection info.
     *
     * @uses $db = MySqliDb::getInstance();
     *
     * @return object Returns the current instance.
     */
    public static function getInstance()
    {
        return self::$_instance;
    }

    /**
     * Reset states after an execution
     *
     * @return object Returns the current instance.
     */
    protected function reset()
    {
        $this->_where = array();
        $this->_bindParams = array(''); // Create the empty 0 index
        unset($this->_query);
    }

    /**
     * Pass in a raw query and an array containing the parameters to bind to the prepaird statement.
     *
     * @param string $query      Contains a user-provided query.
     * @param array  $bindParams All variables to bind to the SQL statment.
     *
     * @return array Contains the returned rows from the query.
     */
    public function rawQuery($query, $bindParams = null)
    {
        $this->_query = $query;
        $stmt = $this->_prepareQuery();
        $stmt->execute($bindParams);
        $this->reset();

        return $this->_dynamicBindResults($stmt);
    }

    /**
     *
     * @param string $query   Contains a user-provided select query.
     * @param int    $numRows The number of rows total to return.
     *
     * @return array Contains the returned rows from the query.
     */
    public function query($query, $numRows = null)
    {
        $this->_query = $query;
        $stmt = $this->_buildQuery($numRows);
        $stmt->execute(array_values($this->_where));
        $this->reset();

        return $this->_dynamicBindResults($stmt);
    }

    /**
     * A convenient SELECT * function.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param integer $numRows   The number of rows total to return.
     *
     * @return array Contains the returned rows from the select query.
     */
    public function get($tableName, $numRows = null)
    {
        $this->_query = "SELECT * FROM $tableName";
        $stmt = $this->_buildQuery($numRows);
        $stmt->execute(array_values($this->_where));
        $this->reset();

        return $this->_dynamicBindResults($stmt);
    }

    /**
     *
     * @param <string $tableName The name of the table.
     * @param array $insertData Data containing information for inserting into the DB.
     *
     * @return boolean Boolean indicating whether the insert query was completed succesfully.
     */
    public function insert($tableName, $insertData)
    {
        $this->_query = "INSERT into $tableName";
        $stmt = $this->_buildQuery(null, $insertData);
        $stmt->execute(array_values($insertData));
        $this->reset();

        return ($stmt->rowCount() > 0 ? $this->getInsertId() : false);
    }

    /**
     * Update query. Be sure to first call the "where" method.
     *
     * @param string $tableName The name of the database table to work with.
     * @param array  $tableData Array of data to update the desired row.
     *
     * @return boolean
     */
    public function update($tableName, $tableData)
    {
        $this->_query = "UPDATE $tableName SET ";
        if( is_array($tableData) ){
            $exec_params=array_merge(array_values($tableData),array_values($this->_where));
        }else{
            $exec_params=array_values($this->_where);
        }
        $stmt = $this->_buildQuery(null, $tableData);

        $stmt->execute($exec_params);
        $this->reset();

        return ($stmt->rowCount() > 0);
    }

    /**
     * Delete query. Call the "where" method first.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param integer $numRows   The number of rows to delete.
     *
     * @return boolean Indicates success. 0 or 1.
     */
    public function delete($tableName, $numRows = null)
    {
        $this->_query = "DELETE FROM $tableName";

        $stmt = $this->_buildQuery($numRows);
        $stmt->execute(array_values($this->_where));
        $this->reset();

        return ($stmt->rowCount() > 0);
    }

    /**
     * This method allows you to specify multipl (method chaining optional) WHERE statements for SQL queries.
     *
     * @uses $MySqliDb->where('id', 7)->where('title', 'MyTitle');
     *
     * @param string $whereProp  The name of the database field.
     * @param mixed  $whereValue The value of the database field.
     *
     * @return MysqliDb
     */
    public function where($whereProp, $whereValue)
    {
        $this->_where[$whereProp] = $whereValue;
        return $this;
    }


    /**
     * This methods returns the ID of the last inserted item
     *
     * @return integer The last inserted item ID.
     */
    public function getInsertId()
    {
        return $this->_pdo->lastInsertId(); 
    }

    /**
     * Escape harmful characters which might affect a query.
     *
     * @param string $str The string to escape.
     *
     * @return string The escaped string.
     */
    public function escape($str)
    {
        return $this->_pdo->quote($str);
    }


    /**
     * Abstraction method that will compile the WHERE statement,
     * any passed update data, and the desired rows.
     * It then builds the SQL query.
     *
     * @param int   $numRows   The number of rows total to return.
     * @param array $tableData Should contain an array of data for updating the database.
     *
     * @return mysqli_stmt Returns the $stmt object.
     */
    protected function _buildQuery($numRows = null, $tableData = null)
    {
        $hasTableData = is_array($tableData);
        $hasConditional = !empty($this->_where);

        // Did the user call the "where" method?
        if (!empty($this->_where)) {

            // if update data was passed, filter through and create the SQL query, accordingly.
            if ($hasTableData) {
                $pos = strpos($this->_query, 'UPDATE');
                if ($pos !== false) {
                    foreach ($tableData as $prop => $value) {
                        // prepares the reset of the SQL query.
                        $this->_query .= ($prop . ' = ?, ');
                    }
                    $this->_query = rtrim($this->_query, ', ');
                }
            }

            //Prepair the where portion of the query
            $this->_query .= ' WHERE ';
            foreach ($this->_where as $column => $value) {
                // Prepares the reset of the SQL query.
                $this->_query .= ($column . ' = ? AND ');
            }
            $this->_query = rtrim($this->_query, ' AND ');
        }

        // Determine if is INSERT query
        if ($hasTableData) {
            $pos = strpos($this->_query, 'INSERT');

            if ($pos !== false) {
                //is insert statement
                $keys = array_keys($tableData);
                $values = array_values($tableData);
                $num = count($keys);

                // wrap values in quotes
                foreach ($values as $key => $val) {
                    $values[$key] = "'{$val}'";
                }

                $this->_query .= '(' . implode($keys, ', ') . ')';
                $this->_query .= ' VALUES(';
                while ($num !== 0) {
                    $this->_query .= '?, ';
                    $num--;
                }
                $this->_query = rtrim($this->_query, ', ');
                $this->_query .= ')';
            }
        }

        // Did the user set a limit
        if (isset($numRows)) {
            $this->_query .= ' LIMIT ' . (int)$numRows;
        }

        // Prepare query
        $stmt = $this->_prepareQuery();

        // Prepare table data bind parameters
        if ($hasTableData) {
            foreach ($tableData as $prop => $val) {
                array_push($this->_bindParams, $tableData[$prop]);
            }
        }
        // Prepare where condition bind parameters
        if ($hasConditional) {
            if ($this->_where) {
                foreach ($this->_where as $prop => $val) {
                    array_push($this->_bindParams, $this->_where[$prop]);
                }
            }
        }

        return $stmt;
    }

    /**
     * This helper method takes care of prepared statements' "bind_result method
     * , when the number of variables to pass is unknown.
     *
     * @param mysqli_stmt $stmt Equal to the prepared statement object.
     *
     * @return array The results of the SQL fetch.
     */
    protected function _dynamicBindResults(PDOStatement $stmt)
    {
        $results = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[]=$row;
        }
        return $results;
    }

    /**
     * Method attempts to prepare the SQL query
     * and throws an error if there was a problem.
     *
     * @return mysqli_stmt
     */
    protected function _prepareQuery()
    {
        if (!$stmt = $this->_pdo->prepare($this->_query)) {
            trigger_error("Problem preparing query ($this->_pdo) " . $this->_pdo->errorInfo(), E_USER_ERROR);
        }
        return $stmt;
    }

    /**
     * Close connection
     */
    public function __destruct()
    {
        $this->_pdo=null;
    }

} // END class
