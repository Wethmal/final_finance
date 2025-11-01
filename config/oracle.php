<?php
class OracleDB {
    private $conn;

    public function __construct() {
        $username = "system";      // your Oracle username
        $password = "admin123";    // your Oracle password
        $connection_string = "localhost/XEPDB1"; // default for Oracle XE

        try {
            $this->conn = oci_connect($username, $password, $connection_string);
            if (!$this->conn) {
                $e = oci_error();
                throw new Exception($e['message']);
            }
        } catch (Exception $e) {
            error_log("Oracle connection failed: " . $e->getMessage());
            $this->conn = null;
        }
    }

    public function getConnection() {
        return $this->conn;
    }

    public function __destruct() {
        if ($this->conn) {
            oci_close($this->conn);
        }
    }
}
?>
