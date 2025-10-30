<?php
class OracleDB {
    private $conn;

    public function __construct() {
        $username = "system"; // or ADMIN (for cloud)
        $password = "admin123"; // your password
        $connection_string = "localhost/XEPDB1"; // or your cloud TNS name

        try {
            $this->conn = oci_connect($username, $password, $connection_string);
            if (!$this->conn) {
                $e = oci_error();
                throw new Exception($e['message']);
            }
        } catch (Exception $e) {
            die("Oracle Connection failed: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->conn;
    }
}
?>
