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
                } else {
                    echo json_encode(["success" => false, "message" => "'username' and 'password' are required for login."]);
                }
                break;

            case 'createGame':
                if (
                        isset($_SESSION['player1Id'], $_SESSION['player2Id']) &&
                        isset($_SESSION['player1Name'], $_SESSION['player2Name'])
                ) {
                    try {
                        $player1Id = $_SESSION['player1Id'];
                        $player2Id = $_SESSION['player2Id'];

                        // Call InitializeGame to create a new game
                        $conn = getDatabaseConnection();

                        $stmt = $conn->prepare("CALL InitializeGame(?, ?, @gameID, @gameToken)");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare stored procedure: " . $conn->error);
                        }

                        $stmt->bind_param("ii", $player1Id, $player2Id);
                        if (!$stmt->execute()) {
                            throw new Exception("Failed to execute stored procedure: " . $stmt->error);
                        }

                        // Clear any remaining results
                        while ($conn->more_results() && $conn->next_result()) {
                            $result = $conn->store_result();
                            if ($result) {
                                $result->free();
                            }
                        }

                        // Fetch the output parameters
                        $result = $conn->query("SELECT @gameID AS game_id, @gameToken AS game_token");
                        if (!$result) {
                            throw new Exception("Failed to fetch output parameters: " . $conn->error);
                        }

                        $gameData = $result->fetch_assoc();
                        $gameId = $gameData['game_id'];
                        $gameToken = $gameData['game_token'];

                        // Store game data in the session
                        $_SESSION['gameId'] = $gameId;
                        $_SESSION['gameToken'] = $gameToken;

                        echo json_encode([
                            "success" => true,
                            "message" => "Game created successfully.",
                            "gameId" => $gameId,
                            "gameToken" => $gameToken
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
                } else {
                    echo json_encode([
                        "success" => false,
                        "message" => "Both players must be logged in before creating a game."
                    ]);
                }
                break;

                function resumeGame($gameToken) {
                    try {
                        $conn = getDatabaseConnection();

                        // Fetch game data using the token
                        $stmt = $conn->prepare("CALL GetGameByToken(?, @gameID, @player1ID, @player2ID, @currentTurn)");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare statement: " . $conn->error);
                        }
                        $stmt->bind_param("s", $gameToken);
                        if (!$stmt->execute()) {
                            throw new Exception("Failed to execute statement: " . $stmt->error);
                        }

                        // Clear remaining result sets
                        while ($conn->more_results() && $conn->next_result()) {
                            $result = $conn->store_result();
                            if ($result) {
                                $result->free();
                            }
                        }

                        // Retrieve output parameters
                        $result = $conn->query("SELECT @gameID AS game_id, @player1ID AS player1_id, @player2ID AS player2_id, @currentTurn AS current_turn");
                        if (!$result) {
                            throw new Exception("Failed to fetch game data: " . $conn->error);
                        }

                        $gameData = $result->fetch_assoc();
                        $gameId = $gameData['game_id'];
                        $player1Id = $gameData['player1_id'];
                        $player2Id = $gameData['player2_id'];
                        $currentTurn = $gameData['current_turn'];

                        if (!$gameId || !$player1Id || !$player2Id) {
                            throw new Exception("Invalid game token or game data is incomplete.");
                        }

                        // Restore session data
                        $_SESSION['gameId'] = $gameId;
                        $_SESSION['gameToken'] = $gameToken;
                        $_SESSION['player1Id'] = $player1Id;
                        $_SESSION['player2Id'] = $player2Id;
                        $_SESSION['currentTurn'] = $currentTurn;

                        // Fetch and display the board state
                        $board = reconstructBoard($gameId);
                        echo "<h3>Resumed Board</h3>";
                        printBoard($board);

                        // Fetch and display available pieces for the current player
                        $stmt = $conn->prepare("CALL GetAvailablePieces(?, ?)");
                        $stmt->bind_param("ii", $currentTurn, $gameId);
                        if (!$stmt->execute()) {
                            throw new Exception("Failed to fetch available pieces: " . $stmt->error);
                        }

                        // Clear remaining result sets after fetching available pieces
                        while ($conn->more_results() && $conn->next_result()) {
                            $result = $conn->store_result();
                            if ($result) {
                                $result->free();
                            }
                        }

                        $result = $stmt->get_result();
                        $availablePieces = [];
                        while ($row = $result->fetch_assoc()) {
                            $availablePieces[] = $row;
                        }

                        echo "<h3>Available Pieces</h3>";
                        printAvailablePieces($availablePieces);

                        echo "<h3>Current Turn: Player " . ($currentTurn === $player1Id ? "1" : "2") . "</h3>";
                    } catch (Exception $e) {
                        echo "Error: " . $e->getMessage();
                    } finally {
                        if (isset($conn)) {
                            $conn->close();
                        }
                    }
                }

            case 'makeMove':
                if (isset($_POST['game_id']) && isset($_POST['player_id']) &&
                        isset($_POST['piece_id']) && isset($_POST['startX']) && isset($_POST['startY'])) {

                    $gameId = $_POST['game_id'];
                    $playerId = $_POST['player_id'];
                    $pieceId = $_POST['piece_id'];
                    $startX = $_POST['startX'];
                    $startY = $_POST['startY'];

                    makeMove($gameId, $playerId, $pieceId, $startX, $startY);
                } else {
                    echo "Error: Missing parameters. Required: 'game_id', 'player_id', 'piece_id', 'startX', 'startY'.";
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
        $stmt = $conn->prepare("CALL LoginPlayer(?, ?, @playerId)");
        $stmt->bind_param("ss", $username, $password);
        if (!$stmt->execute()) {
            throw new Exception("Failed to log in player: " . $stmt->error);
        }

        // Fetch the output parameter
        $result = $conn->query("SELECT @playerId AS playerId");
        if (!$result) {
            throw new Exception("Failed to fetch player ID during login.");
        }

        $playerData = $result->fetch_assoc();
        if (!isset($playerData['playerId'])) {
            throw new Exception("Player ID not returned from stored procedure.");
        }

        // Generate a token for the session
        $token = generateToken();

        // Store in session
        $_SESSION['playerId'] = $playerData['playerId'];
        $_SESSION['username'] = $username;
        $_SESSION['token'] = $token;

        echo json_encode([
            "success" => true,
            "message" => "Player logged in successfully.",
            "playerId" => $playerData['playerId'],
            "username" => $username,
            "token" => $token
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

function generateToken() {
    return hash('sha256', random_bytes(32));
}

?>