<?php

require_once 'GameFunctions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['method'])) {
        echo json_encode(["success" => false, "message" => "'method' parameter is required."]);
        exit;
    }

    $method = $input['method'];

    try {
        switch ($method) {

            case 'register':
                if (isset($input['username']) && isset($input['password'])) {
                    registerPlayer($input['username'], $input['password']);
                } else {
                    echo json_encode(["success" => false, "message" => "'username' and 'password' are required for registration."]);
                }
                break;

            case 'login':
                if (isset($input['username']) && isset($input['password'])) {
                    loginPlayer($input['username'], $input['password']);
                    // debugSession();
                } else {
                    echo json_encode(["success" => false, "message" => "'username' and 'password' are required for login."]);
                }
                break;

            case 'createGame':
                try {
                    if (!isset($input['player1Id'], $input['player2Id'], $input['player1Token'], $input['player2Token'])) {
                        echo json_encode([
                            "success" => false,
                            "message" => "Player1Id, Player2Id, Player1Token, and Player2Token are required."
                        ]);
                        break;
                    }

                    $player1Id = $input['player1Id'];
                    $player2Id = $input['player2Id'];
                    $player1Token = $input['player1Token'];
                    $player2Token = $input['player2Token'];

                    $conn = getDatabaseConnection();

                    $stmt = $conn->prepare("CALL InitializeGame(?, ?, ?, ?, @gameId, @gameToken)");
                    $stmt->bind_param("iiss", $player1Id, $player2Id, $player1Token, $player2Token);
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to execute stored procedure: " . $stmt->error);
                    }

                    while ($conn->more_results() && $conn->next_result()) {
                        if ($result = $conn->store_result()) {
                            $result->free();
                        }
                    }

                    $result = $conn->query("SELECT @gameId AS game_id, @gameToken AS game_token");
                    if (!$result) {
                        throw new Exception("Failed to fetch output parameters.");
                    }

                    $gameData = $result->fetch_assoc();
                    $_SESSION['gameId'] = $gameData['game_id'];
                    $_SESSION['gameToken'] = $gameData['game_token'];

                    $stmt = $conn->prepare("SELECT ID, username FROM Players WHERE ID IN (?, ?)");
                    $stmt->bind_param("ii", $player1Id, $player2Id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        if ($row['ID'] == $player1Id) {
                            $_SESSION['player1Name'] = $row['username'];
                        } elseif ($row['ID'] == $player2Id) {
                            $_SESSION['player2Name'] = $row['username'];
                        }
                    }

                    $_SESSION['player1Id'] = $player1Id;
                    $_SESSION['player2Id'] = $player2Id;
                    $_SESSION['currentTurn'] = $player1Id;

                    echo "Game Created Successfully!<br>"
                    . "Game ID: {$_SESSION['gameId']}<br>"
                    . "Game Token: {$_SESSION['gameToken']}<br>";

                    setupGame();
                } catch (Exception $e) {
                    echo json_encode([
                        "success" => false,
                        "message" => $e->getMessage()
                    ]);
                } finally {
                    if (isset($conn)) {
                        $conn->close();
                    }
                }
                break;

            case 'makeMove':
                try {
                    // Ensure all required parameters are provided
                    if (!isset($input['gameId'], $input['playerId'], $input['gameToken'], $input['pieceId'], $input['startX'], $input['startY'])) {
                        echo json_encode([
                            "success" => false,
                            "message" => "Missing parameters. Required: 'gameId', 'playerId', 'gameToken', 'pieceId', 'startX', 'startY'."
                        ]);
                        break;
                    }

                    // Extract parameters
                    $gameId = $input['gameId'];
                    $playerId = $input['playerId'];
                    $gameToken = $input['gameToken'];
                    $pieceId = $input['pieceId'];
                    $startX = $input['startX'];
                    $startY = $input['startY'];

                    // Call the makeMove function
                    makeMove($gameId, $playerId, $gameToken, $pieceId, $startX, $startY);
                } catch (Exception $e) {
                    echo json_encode([
                        "success" => false,
                        "message" => $e->getMessage()
                    ]);
                }

                break;

            case 'printCurrentBoard':
                try {
                    // Ensure gameId is provided
                    if (!isset($input['gameId'])) {
                        echo json_encode([
                            "success" => false,
                            "message" => "gameId is required."
                        ]);
                        break;
                    }

                    $gameId = $input['gameId'];
                    $conn = getDatabaseConnection();

                    // Fetch the current turn
                    $stmt = $conn->prepare("SELECT current_turn FROM Games WHERE ID = ?");
                    $stmt->bind_param("i", $gameId);
                    $stmt->execute();

                    $result = $stmt->get_result();
                    if (!$result || $result->num_rows === 0) {
                        throw new Exception("Game ID not found.");
                    }

                    $gameData = $result->fetch_assoc();
                    $currentTurn = $gameData['current_turn'];

                    // Determine the player's name for the current turn
                    $stmt = $conn->prepare("SELECT username FROM Players WHERE ID = ?");
                    $stmt->bind_param("i", $currentTurn);
                    $stmt->execute();

                    $result = $stmt->get_result();
                    if (!$result || $result->num_rows === 0) {
                        throw new Exception("Player not found for current turn.");
                    }

                    $playerData = $result->fetch_assoc();
                    $currentPlayerName = $playerData['username'];

                    // Reconstruct the board
                    $board = reconstructBoard($gameId); // Reuse existing reconstruct logic

                    echo "<h3>Current Board for Game ID: $gameId</h3>";
                    printBoard($board); // Reuse existing printBoard function
                    // Display the current player's turn
                    echo "<h3>It's $currentPlayerName's turn!</h3>";
                } catch (Exception $e) {
                    echo json_encode([
                        "success" => false,
                        "message" => $e->getMessage()
                    ]);
                }
                break;

            case 'calculateWinner':
                if (!isset($input['gameId'])) {
                    echo json_encode([
                        "success" => false,
                        "message" => "gameId is required."
                    ]);
                    break;
                }

                $gameId = $input['gameId'];
                $userToken = $input['userToken'];
                calculateWinner($gameId, $userToken);
                break;

            default:
                echo json_encode(["success" => false, "message" => "Unknown method '$method'."]);
                break;
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request method. Please use POST."]);
}

function registerPlayer($username, $password) {
    try {
        $conn = getDatabaseConnection();

        // Call the stored procedure for registration
        $stmt = $conn->prepare("CALL RegisterPlayer(?, ?, @playerId)");
        $stmt->bind_param("ss", $username, $password);
        if (!$stmt->execute()) {
            throw new Exception("Failed to register player: " . $stmt->error);
        }

        // Fetch the output parameter
        $result = $conn->query("SELECT @playerId AS playerId");
        if (!$result) {
            throw new Exception("Failed to fetch player ID after registration.");
        }

        $playerData = $result->fetch_assoc();
        if (!isset($playerData['playerId'])) {
            throw new Exception("Player ID not returned from stored procedure.");
        }

        echo json_encode([
            "success" => true,
            "message" => "Player registered successfully.",
            "playerId" => $playerData['playerId'],
            "username" => $username
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
}

function loginPlayer($username, $password) {
    try {
        $conn = getDatabaseConnection();

        // Call the stored procedure for login
        $stmt = $conn->prepare("CALL LoginPlayer(?, ?, @playerId, @token)");
        $stmt->bind_param("ss", $username, $password);
        if (!$stmt->execute()) {
            throw new Exception("Failed to log in player: " . $stmt->error);
        }

        // Process all result sets to avoid 'commands out of sync' error
        while ($conn->more_results() && $conn->next_result()) {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        }

        // Fetch the player ID from the output parameter
        $result = $conn->query("SELECT @playerId AS playerId, @token AS token");
        if (!$result) {
            throw new Exception("Failed to fetch player ID and token during login.");
        }

        $playerData = $result->fetch_assoc();
        if (!isset($playerData['playerId'], $playerData['token'])) {
            throw new Exception("Player ID or token not returned from stored procedure.");
        }

        // Store in session
        $_SESSION['playerId'] = $playerData['playerId'];
        $_SESSION['username'] = $username;
        $_SESSION['token'] = $playerData['token'];

        // Send response
        echo json_encode([
            "success" => true,
            "message" => "Player logged in successfully.",
            "playerId" => $playerData['playerId'],
            "username" => $username,
            "token" => $playerData['token']
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
}

function debugSession() {
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
}

?>