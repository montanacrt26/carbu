<?php
/**
 * CarbuFrance - API d'authentification
 * 
 * Endpoints:
 *   POST /api/auth.php?action=register  - Inscription
 *   POST /api/auth.php?action=login     - Connexion
 *   POST /api/auth.php?action=logout    - Déconnexion
 *   GET  /api/auth.php?action=me        - Infos utilisateur courant
 */

require_once __DIR__ . '/dbconfig.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
// Body en cache (évite le double-read de php://input)
$input  = getRequestBodyJson();

switch ($action) {
    case 'register':
        handleRegister($input);
        break;
    case 'login':
        handleLogin($input);
        break;
    case 'logout':
        handleLogout();
        break;
    case 'me':
        handleMe();
        break;
    default:
        jsonError('Action non reconnue', 400);
}

// ─────────────────────────────────────────────────────────────────────────────
// Handlers
// ─────────────────────────────────────────────────────────────────────────────

function handleRegister($input) {
    $username = trim($input['username'] ?? '');
    $email    = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    
    // Validation
    if (strlen($username) < 3 || strlen($username) > 50) {
        jsonError('Le nom d\'utilisateur doit contenir entre 3 et 50 caractères.');
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
        jsonError('Le nom d\'utilisateur ne peut contenir que des lettres, chiffres, tirets et underscores.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('Adresse e-mail invalide.');
    }
    if (strlen($password) < 8) {
        jsonError('Le mot de passe doit contenir au moins 8 caractères.');
    }
    
    $pdo = getDB();
    
    // Vérifier que l'email n'existe pas déjà
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonError('Un compte existe déjà avec cet e-mail.');
    }
    
    // Vérifier que le username n'existe pas déjà
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        jsonError('Ce nom d\'utilisateur est déjà pris.');
    }
    
    // Hasher le mot de passe
    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    
    // Créer l'utilisateur
    $stmt = $pdo->prepare('
        INSERT INTO users (username, email, password_hash, role, status)
        VALUES (?, ?, ?, "user", "active")
    ');
    $stmt->execute([$username, $email, $passwordHash]);
    $userId = $pdo->lastInsertId();
    
    // Créer une session
    $token = generateToken();
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_DURATION);
    
    $stmt = $pdo->prepare('
        INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $token, getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expiresAt]);
    
    // Logger
    logActivity($userId, 'register', 'Nouveau compte créé');
    
    // Envoyer le cookie
    setcookie(SESSION_COOKIE_NAME, $token, [
        'expires'  => time() + SESSION_DURATION,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    jsonSuccess([
        'user' => [
            'id'       => (int)$userId,
            'username' => $username,
            'email'    => $email,
            'role'     => 'user'
        ],
        'token' => $token
    ], 'Compte créé avec succès');
}

function handleLogin($input) {
    $identifier = trim($input['email'] ?? $input['username'] ?? ''); // email ou username
    $password   = $input['password'] ?? '';
    
    if (!$identifier || !$password) {
        jsonError('Veuillez saisir vos identifiants.');
    }
    
    $pdo = getDB();
    
    // Chercher l'utilisateur par email OU username
    $stmt = $pdo->prepare('
        SELECT id, username, email, password_hash, role, status
        FROM users
        WHERE email = ? OR username = ?
    ');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonError('Identifiants incorrects.');
    }
    
    if ($user['status'] === 'suspended') {
        jsonError('Votre compte a été suspendu. Contactez l\'administrateur.');
    }
    
    if ($user['status'] === 'pending') {
        jsonError('Votre compte est en attente de validation.');
    }
    
    // Vérifier le mot de passe
    if (!password_verify($password, $user['password_hash'])) {
        logActivity($user['id'], 'login_failed', 'Mot de passe incorrect');
        jsonError('Identifiants incorrects.');
    }
    
    // Supprimer les anciennes sessions de cet utilisateur (optionnel, limite à 5 sessions)
    $stmt = $pdo->prepare('
        DELETE FROM sessions 
        WHERE user_id = ? 
        AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 4
            ) AS keep
        )
    ');
    $stmt->execute([$user['id'], $user['id']]);
    
    // Créer une nouvelle session
    $token = generateToken();
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_DURATION);
    
    $stmt = $pdo->prepare('
        INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$user['id'], $token, getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expiresAt]);
    
    // Mettre à jour last_login et login_count
    $stmt = $pdo->prepare('UPDATE users SET last_login = NOW(), login_count = login_count + 1 WHERE id = ?');
    $stmt->execute([$user['id']]);
    
    // Logger
    logActivity($user['id'], 'login', 'Connexion réussie');
    
    // Envoyer le cookie
    setcookie(SESSION_COOKIE_NAME, $token, [
        'expires'  => time() + SESSION_DURATION,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    jsonSuccess([
        'user' => [
            'id'       => (int)$user['id'],
            'username' => $user['username'],
            'email'    => $user['email'],
            'role'     => $user['role']
        ],
        'token' => $token
    ], 'Connexion réussie');
}

function handleLogout() {
    $session = getCurrentSession();
    
    if ($session) {
        $pdo = getDB();
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE token = ?');
        $stmt->execute([$session['token']]);
        
        logActivity($session['user_id'], 'logout', 'Déconnexion');
    }
    
    // Supprimer le cookie
    setcookie(SESSION_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    jsonSuccess([], 'Déconnexion réussie');
}

function handleMe() {
    $session = getCurrentSession();
    
    if (!$session) {
        jsonSuccess(['user' => null], 'Non connecté');
    }
    
    $pdo = getDB();
    
    // Compter les favoris
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
    $stmt->execute([$session['user_id']]);
    $favCount = (int)$stmt->fetchColumn();
    
    jsonSuccess([
        'user' => [
            'id'            => (int)$session['user_id'],
            'username'      => $session['username'],
            'email'         => $session['email'],
            'role'          => $session['role'],
            'favoritesCount'=> $favCount
        ]
    ]);
}
