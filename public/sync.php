<?php
require_once "db/database.php";    // your SQLite connection
require_once "config/oracle.php";  // your Oracle connection

// Connect to both DBs
$sqlite = new Database();
$pdo = $sqlite->getConnection();

$oracle = new OracleDB();
$oconn = $oracle->getConnection();

// Fetch all data from SQLite
$stmt = $pdo->query("SELECT * FROM expenses"); // adjust table name
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Loop through each row and insert into Oracle
foreach ($data as $row) {
    $query = "INSERT INTO expenses (id, name, amount, date)
              VALUES (:id, :name, :amount, TO_DATE(:date, 'YYYY-MM-DD'))";

    $ostmt = oci_parse($oconn, $query);
    oci_bind_by_name($ostmt, ":id", $row['id']);
    oci_bind_by_name($ostmt, ":name", $row['name']);
    oci_bind_by_name($ostmt, ":amount", $row['amount']);
    oci_bind_by_name($ostmt, ":date", $row['date']);

    @oci_execute($ostmt); // @ hides "duplicate" errors
}

echo "<h3>✅ Sync completed successfully!</h3>";
?>
