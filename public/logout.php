<?php
// logout.php - Destroy session and logout user
session_start();
session_destroy();
header('Location: login.php');
exit();
?>