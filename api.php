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

            case 'resumeGame':
                try {
                    if (!isset($input['gameToken'], $input['playerToken'])) {
                        echo json_encode([
                            "success" => false,
                            "message" => "GameToken and PlayerToken are required."
                        ]);
                        break;
                    }

                    $gameToken = $input['gameToken'];
                    $playerToken = $input['playerToken'];

                    $conn = getDatabaseConnection();

                    // Validate the game token
                    $stmt = $conn->prepare("SELECT ID, current_turn FROM Games WHERE game_Token = ? AND game_Token != 'FINISHED'");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare statement: " . $conn->error);
                    }
                    $stmt->bind_param("s", $gameToken);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows === 0) {
                        echo json_encode(["success" => false, "message" => "Invalid or finished game token."]);
                        break;
                    }

                    $gameData = $result->fetch_assoc();
                    $gameId = $gameData['ID'];
                    $currentTurn = $gameData['current_turn'];
                    $result->free();
                    $stmt->close();

                    // Validate the player's token
                    $stmt = $conn->prepare("SELECT 1 FROM Players WHERE token = ? AND ID IN (
            SELECT player1 FROM Games WHERE ID = ?
            UNION
            SELECT player2 FROM Games WHERE ID = ?
        )");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare statement: " . $conn->error);
                    }
                    $stmt->bind_param("sii", $playerToken, $gameId, $gameId);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows === 0) {
                        echo json_encode(["success" => false, "message" => "Invalid player token."]);
                        break;
                    }

                    $result->free();
                    $stmt->close();

                    // Fetch player details and set in session if not already set
                    $stmt = $conn->prepare("SELECT ID, username FROM Players WHERE ID IN (
            SELECT player1 FROM Games WHERE ID = ? UNION SELECT player2 FROM Games WHERE ID = ?)");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare statement for fetching player details: " . $conn->error);
                    }
                    $stmt->bind_param("ii", $gameId, $gameId);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    while ($row = $result->fetch_assoc()) {
                        if (!isset($_SESSION['player1Id']) && $row['ID'] === $currentTurn) {
                            $_SESSION['player1Id'] = $row['ID'];
                            $_SESSION['player1Name'] = $row['username'];
                        } elseif (!isset($_SESSION['player2Id'])) {
                            $_SESSION['player2Id'] = $row['ID'];
                            $_SESSION['player2Name'] = $row['username'];
                        }
                    }

                    $result->free();
                    $stmt->close();

                    // Reconstruct the board
                    $board = reconstructBoard($gameId);

                    // Print the board
                    echo "<h3>Resumed Game Board</h3>";
                    printBoard($board);

                    // Announce the next player's turn
                    $nextPlayerName = ($currentTurn === $_SESSION['player1Id']) ? $_SESSION['player1Name'] : $_SESSION['player2Name'];
                    echo "<h3 style='font-weight: bold; margin-top: 20px;'>Next Turn: $nextPlayerName</h3>";

                    // Fetch and display available pieces for the current player
                    $stmt = $conn->prepare("CALL GetAvailablePieces(?, ?)");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare statement for fetching available pieces: " . $conn->error);
                    }
                    $stmt->bind_param("ii", $currentTurn, $gameId);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    $availablePieces = [];
                    while ($row = $result->fetch_assoc()) {
                        $availablePieces[] = $row;
                    }

                    echo "<h3>Available Pieces</h3>";
                    printAvailablePieces($availablePieces);
                } catch (Exception $e) {
                    echo json_encode(["success" => false, "message" => $e->getMessage()]);
                } finally {
                    if (isset($conn)) {
                        $conn->close();
                    }
                }
                break;

            case 'logout':
                try {
                    // Ensure the playerToken is provided
                    if (!isset($input['playerToken'])) {
                        echo json_encode([
                            "success" => false,
                            "message" => "Player token is required for logout."
                        ]);
                        break;
                    }

                    $playerToken = $input['playerToken'];

                    $conn = getDatabaseConnection();

                    // Validate the player's token and fetch the username
                    $stmt = $conn->prepare("SELECT username FROM Players WHERE token = ?");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare statement: " . $conn->error);
                    }
                    $stmt->bind_param("s", $playerToken);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows === 0) {
                        echo json_encode(["success" => false, "message" => "Invalid player token."]);
                        break;
                    }

                    $playerData = $result->fetch_assoc();
                    $username = $playerData['username'];
                    $result->free();
                    $stmt->close();

                    // Update the token in the database to null
                    $stmt = $conn->prepare("UPDATE Players SET token = NULL WHERE token = ?");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare statement to clear token: " . $conn->error);
                    }
                    $stmt->bind_param("s", $playerToken);
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to execute statement to clear token: " . $stmt->error);
                    }

                    // Display logout message
                    echo "<h3 style='font-weight: bold; color: green;'>$username has logged out, hope to see you soon!</h3>";
                } catch (Exception $e) {
                    echo json_encode(["success" => false, "message" => $e->getMessage()]);
                } finally {
                    if (isset($conn)) {
                        $conn->close();
                    }
                }
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