<?php
require "connect.php";

$mysqli = new mysqli($server, $brugernavn, $password, $db_navn);
if ($mysqli->connect_errno) {
printf("Connect failed: %s\n", $mysqli->connect_error);
exit();
}

$query = "SELECT * FROM items";
    $result = $mysqli->query($query);

    if ($result->num_rows > 0)
    {
    $row = $result->fetch_assoc();
    Echo $row['id'];
    Echo $row['column_id'];
    Echo $row['title'];
    Echo $row['position'];

    }
$mysqli->close();
    ?>