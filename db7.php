<?php

class DB{

    const DEBUG = false;
    const MSSQL = 'mssql';
    const MYSQL = 'mysql';
    const CONFIG = '/var/www/html/libs/config/.db';
    const PARENPATTERN = '/\)/';

    public $query;
    protected $suite;
    protected $server;
    protected $username;
    protected $password;
    protected $port;
    protected $driver;
    protected $database;
    protected $table;
    protected $config;
    protected $con;
    protected $defaultServer;
    private $internalFunctions = array("NEWID","GETDATE","CAST","NULL","MONTH","YEAR");

    public function __construct()
    {
        $this->_getConfig();
        $this->defaultServer = true;
    }
    protected function _getConfig(){
        if(!is_file(self::CONFIG)){
            throw new Exception('Required Config File Does not Exist');
        }else{
            $this->config = file(self::CONFIG);
        }
        return $this;
    }
    protected function connect(){
        if($this->driver == self::MSSQL){
            $this->server = $this->_env($this->suite . "_DB_HOST");
            $this->username = $this->_env($this->suite . "_DB_USERNAME");
            $this->password = $this->_env($this->suite . "_DB_PASSWORD");
            $this->port = $this->_env($this->suite . "_DB_PORT");
            $connectionInfo = array("UID"=>$this->username,"PWD"=>$this->password);
            $this->con = sqlsrv_connect($this->server,$connectionInfo);
        }else{
            $this->server = $this->_env("MYSQL_DB_HOST");
            $this->username = $this->_env("MYSQL_DB_USERNAME");
            $this->password = $this->_env("MYSQL_DB_PASSWORD");
            $this->con = mysqli_connect($this->server,$this->username,$this->password,$this->database);
        }
        if(!$this->con){
            //print_r($this);
            throw new Exception('Database Connection Failure');
        }
        return $this;
    }
    protected function _getDefaults(){
        if(!isset($this->suite) || empty($this->suite)){
            $this->suite = $this->driver;
        }
        $this->suite = strtoupper($this->suite);
        if ($this->defaultServer) {
            $this->server = $this->_env($this->suite . "_DB_HOST");
        }
        $this->port = $this->_env($this->suite . "_DB_PORT");
        $this->username = $this->_env($this->suite . "_DB_USERNAME");
        $this->password = $this->_env($this->suite . "_DB_PASSWORD");
        return $this;
    }
    protected function _isInternalFunction($str){
        foreach($this->internalFunctions as $internalFunction){
            $pattern = "/" . $internalFunction . "/i";
            if(preg_match($pattern,$str)){
                return true;
            }
        }
        return false;
    }
    private function _env($key)
    {
        foreach ($this->config as $line) {
            if (preg_match("/$key=(.*?)$/", $line, $match)) {
                return $match[1];
            }
        }
        return false;
    }
    public function server($server){
        $this->server = $server;
        $this->defaultServer = false;
        return $this;
    }
    public function suite($suite){
        $this->suite = $suite;
        $this->defaultServer = false;
        return $this;
    }
    public function driver($driver){
        switch (strtolower($driver)){
            case "mssql":
                $this->driver = self::MSSQL;
                break;
            case "mysql":
                $this->driver = self::MYSQL;
                break;
            default:
                throw new Exception('Unsupported Driver');
        }
        return $this;
    }
    public function database($database){
        $this->database = $database;
        return $this;
    }
    public function table($table){
        $this->table = $table;
        $this->_getDefaults()->connect();
        return $this;
    }
    public function select($select){
        if($this->driver == self::MSSQL){
            $this->query = "SELECT " . $select . " FROM " . $this->database . "." . $this->port . "." . $this->table;
        }else{
            $this->query = "SELECT " . $select . " FROM " . $this->database . "." . $this->table;
        }
        return $this;
    }
    public function where($conditional1,$condition,$conditional2){
        $this->query .= " WHERE " . $conditional1 . " " . $condition . " ";
        $internal = $this->_isInternalFunction($conditional2);
        if($internal || is_int($conditional2)){
            $this->query .= $conditional2;
        }else{
            $this->query .= "'" . $conditional2 . "'";
        }
        return $this;
    }
    public function andWhere($conditional1,$condition,$conditional2){
        $this->query .= " AND " . $conditional1 . " " . $condition . " ";
        $internal = $this->_isInternalFunction($conditional2);
        if($internal || is_int($conditional2)){
            $this->query .= $conditional2;
        }elseif(preg_match(self::PARENPATTERN,$conditional2)){
            $pieces = explode(')',$conditional2);
            $this->query .= "'" . $pieces[0] . "')";
        }else{
            $this->query .= "'" . $conditional2 . "'";
        }
        return $this;
    }
    public function orWhere($conditional1,$condition,$conditional2){
        $this->query .= " OR " . $conditional1 . " " . $condition . " ";
        $internal = $this->_isInternalFunction($conditional2);
        if($internal || is_int($conditional2)){
            $this->query .= $conditional2;
        }elseif(preg_match(self::PARENPATTERN,$conditional2)){
            $pieces = explode(')',$conditional2);
            $this->query .= "'" . $pieces[0] . "')";
        }else{
            $this->query .= "'" . $conditional2 . "'";
        }
        return $this;
    }
    public function orderBy($oderBy){
        $this->query .= " ORDER BY " . $oderBy;
        return $this;
    }
    public function take($limit = 0)
    {
        if ($limit > 0) {
            switch ($this->driver) {
                case 'mysql':
                    $this->query .= "\nLIMIT $limit";
                    break;
                case 'mssql':
                    if (preg_match('/DISTINCT/', $this->query)) {
                        $this->query = preg_replace("/DISTINCT/", "DISTINCT TOP $limit", $this->query);
                    } else {
                        $this->query = preg_replace("/SELECT/", "SELECT TOP $limit", $this->query);
                    }
                    break;
            }
        }
        return $this;
    }
    public function get(){
        if($this->driver == self::MSSQL){
            $results = sqlsrv_query($this->con,$this->query,array(),array('Scrollable' => 'buffered'));
        }else{
            $results = mysqli_query($this->con,$this->query);
        }
        if($results == false && $this->driver == self::MSSQL){
            $e = sqlsrv_errors();
            throw new Exception($e[0][2]);
        }elseif($results == false && $this->driver == self::MYSQL){
            print_r(mysqli_error($this->con));
        }
        $this->query = "";
        return $results;
    }
    public function insert($data){
        if($this->driver == self::MSSQL){
            $str = "INSERT INTO " . $this->database . "." . $this->port . "." . $this->table . " (";
        }else{
            $str = "INSERT INTO " . $this->database . "." . $this->table . " (";
        }
        foreach($data as $key=>$value){
            $str .= $key . ",";
        }
        $str .= ")";
        $str = preg_replace('/,([^,]*)$/', '\1', $str);
        $str .= " VALUES (";
        foreach($data as $key=>$value){
            if(!in_array($value,$this->internalFunctions) && !is_numeric($value)){
                $str .= "'" . $value . "',";
            }else{
                $str .= $value . ",";
            }
        }
        $str .= ")";
        $str = preg_replace('/,([^,]*)$/', '\1', $str);
        $this->query = $str;
        return $this;
    }
    public function update($data){
        $colCount = count($data);
        $i = 0;
        if($this->driver == self::MSSQL){
            $str = "UPDATE " . $this->database . "." . $this->port . "." . $this->table . " SET ";
        }else{
            $str = "UPDATE " . $this->database . "." . $this->database . "." . $this->table . " SET ";
        }
        foreach($data as $key=>$value){
            if(++$i == $colCount){
                $str .= $key . " = '" . $value . "'";
            }else{
                $str .= $key . " = '" . $value . "' ,";
            }
        }
        $this->query = $str;
        return $this;
    }
    public function put(){
        if($this->driver == self::MSSQL){
            $results = sqlsrv_query($this->con,$this->query);
        }else{
            $results = mysqli_query($this->con,$this->query);
        }
        if($results == false && $this->driver == self::MSSQL){
            $e = sqlsrv_errors();
            throw new Exception($e[0][2]);
        }elseif($results == false && $this->driver == self::MYSQL){
            print_r(mysqli_error($this->con));
        }
        if($this->driver == self::MSSQL){
            sqlsrv_close($this->con);
        }
        $this->query = "";
        return $this;
    }
    public function includeDatabaseWithNewTable($newTable)
    {
        if ($this->driver == 'mssql') {
            if (!preg_match('/.*?[.]dbo[.]/', $newTable)) {
                $newTable = $this->database . '.dbo.' . $newTable;
            }
        }
        return $newTable;
    }
    public function innerJoin($newTable, $oldTableId, $operator, $newTableId)
    {
        $newTable = $this->includeDatabaseWithNewTable($newTable);
        $this->query .= "\nINNER JOIN $newTable ON $oldTableId $operator $newTableId";
        return $this;
    }
    public function groupBy($args)
    {
        $args = func_get_args();
        for ($i = 0; $i < sizeof($args); $i++) {
            if ($i == 0) {
                $this->query .= "\nGROUP BY $args[$i]";
            } else {
                $this->query .= ", $args[$i]";
            }
        }
        return $this;
    }
}