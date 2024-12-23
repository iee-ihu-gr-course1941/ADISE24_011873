<?php
require_once 'GameFunctions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['method'])) {
        echo "Error: 'method' parameter is required.";
        exit;
    }

    $method = $_POST['method'];

    try {
        switch ($method) {
            case 'initializeGame':
                if (isset($_POST['player1']) && isset($_POST['player2'])) {
                    $player1 = $_POST['player1'];
                    $player2 = $_POST['player2'];
                    initializeGame($player1, $player2);
                    printSessionVariables();
                } else {
                    echo "Error: Both 'player1' and 'player2' parameters are required.";
                }
                break;

            default:
                echo "Error: Unknown method '$method'.";
                break;
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
} else {
    echo "Error: Invalid request method. Please use POST.";
}
?>
