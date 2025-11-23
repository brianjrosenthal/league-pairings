<?php
$url = "http://localhost:5001/";

$response = file_get_contents($url);

echo "Python service responded: " . htmlspecialchars($response);
?>

