<?php
// Include the database connection file
include_once("internal/config.php");

$sql = "SELECT * FROM Pieces";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
    echo "id: " . $row["ID"]. " - SizeX: " . $row["sizeX"]. " - Size Y: " . $row["sizeY"]. "<br>";
  }
} else {
  echo "0 results";
}
$conn->close();


?>

<html>
<body>
    <h1>hello</h1>
</body>
</html>
