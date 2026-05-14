<?php
/**
 * CarbuFrance - Configuration de la base de données
 *
 * IMPORTANT: En production, déplacez ce fichier en dehors du dossier public
 * et utilisez des variables d'environnement pour les credentials.
 */

// Mode debug (passe à false en production)
define('DEBUG_MODE', false);

// Capturer les erreurs fatales et les renvoyer en JSON propre
// (sinon Apache renvoie une page HTML 500 et le frontend ne sait pas quoi faire)
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        $message = DEBUG_MODE
            ? ('PHP Fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'])
            : 'Erreur serveur PHP. Activez DEBUG_MODE pour le détail.';
        echo json_encode(['success' => false, 'message' => $message, 'data' => null]);
    }
});
set_exception_handler(function ($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $message = (defined('DEBUG_MODE') && DEBUG_MODE)
        ? ('PHP Exception: ' . $e->getMessage())
        : 'Exception serveur PHP.';
    echo json_encode(['success' => false, 'message' => $message, 'data' => null]);
    exit;
});

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'carburname1');
define('DB_USER', 'carburuser1');          // Modifier selon votre configuration
define('DB_PASS', 'Montana95870@');              // Modifier selon votre configuration
define('DB_CHARSET', 'utf8mb4');

// Configuration des sessions
define('SESSION_DURATION', 7 * 24 * 60 * 60); // 7 jours en secondes
define('SESSION_COOKIE_NAME', 'cf_session');

// Configuration de sécurité
define('BCRYPT_COST', 10);
define('TOKEN_LENGTH', 32);

// Headers CORS (ajuster selon votre domaine en production)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

// Gérer les requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Connexion PDO
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                jsonError('Erreur de connexion à la base de données: ' . $e->getMessage(), 500);
            } else {
                jsonError('Erreur de connexion à la base de données', 500);
            }
        }
    }
    return $pdo;
}

// Fonctions utilitaires pour les réponses JSON
function jsonSuccess($data = [], $message = 'OK') {
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'data'    => null
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Générer un token sécurisé
function generateToken($length = TOKEN_LENGTH) {
    return bin2hex(random_bytes($length));
}

// Obtenir l'adresse IP du client
function getClientIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            return trim($ips[0]);
        }
    }
    return '0.0.0.0';
}

// Lire le body brut UNE SEULE FOIS et le cacher pour éviter le double-read de php://input
// (sur beaucoup de configurations PHP/FastCGI, php://input ne se lit qu'une fois)
function getRequestBodyRaw() {
    static $cached = null;
    if ($cached === null) {
        $cached = file_get_contents('php://input');
        if ($cached === false) $cached = '';
    }
    return $cached;
}
function getRequestBodyJson() {
    static $parsed = null;
    if ($parsed === null) {
        $raw = getRequestBodyRaw();
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $parsed = is_array($decoded) ? $decoded : [];
    }
    return $parsed;
}

// Vérifier et récupérer la session courante
function getCurrentSession() {
    // Chercher le token dans le header Authorization ou dans un cookie
    $token = null;

    // Header Authorization: Bearer <token>
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $token = $matches[1];
    }

    // Ou dans le cookie
    if (!$token && isset($_COOKIE[SESSION_COOKIE_NAME])) {
        $token = $_COOKIE[SESSION_COOKIE_NAME];
    }

    // Ou dans le body/query (fallback) — utilise la version cachée
    if (!$token) {
        $input = getRequestBodyJson();
        $token = $input['token'] ?? $_GET['token'] ?? null;
    }
    
    if (!$token) {
        return null;
    }
    
    // Vérifier le token en base
    $pdo = getDB();
    $stmt = $pdo->prepare('
        SELECT s.*, u.id as user_id, u.username, u.email, u.role, u.status
        FROM sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.token = ? AND s.expires_at > NOW()
    ');
    $stmt->execute([$token]);
    $session = $stmt->fetch();
    
    if (!$session) {
        return null;
    }
    
    // Vérifier que l'utilisateur est actif
    if ($session['status'] !== 'active') {
        return null;
    }
    
    return $session;
}

// Exiger une session valide (sinon erreur 401)
function requireAuth() {
    $session = getCurrentSession();
    if (!$session) {
        jsonError('Authentification requise', 401);
    }
    return $session;
}

// Exiger un rôle admin
function requireAdmin() {
    $session = requireAuth();
    if ($session['role'] !== 'admin') {
        jsonError('Accès réservé aux administrateurs', 403);
    }
    return $session;
}

// Logger une activité
function logActivity($userId, $action, $details = null) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('
            INSERT INTO activity_logs (user_id, action, details, ip_address)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $action, $details, getClientIP()]);
    } catch (Exception $e) {
        // Silencieux - le log ne doit pas bloquer l'app
    }
}

// Nettoyer les sessions expirées (appelé occasionnellement)
function cleanExpiredSessions() {
    if (rand(1, 100) <= 5) { // 5% de chance à chaque requête
        try {
            $pdo = getDB();
            $pdo->exec('DELETE FROM sessions WHERE expires_at < NOW()');
        } catch (Exception $e) {
            // Silencieux
        }
    }
}

cleanExpiredSessions();
