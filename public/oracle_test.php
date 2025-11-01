<?php
$conn = oci_connect('system', 'admin123', 'localhost/XEPDB1');
if ($conn) {
    echo "✅ Oracle connected successfully!";
    oci_close($conn);
} else {
    $e = oci_error();
    echo "❌ Connection failed: " . $e['message'];
}
?>
