<?php
/**
 * CarbuFrance - API Admin
 * 
 * Endpoints (tous protégés par authentification admin):
 *   GET  /api/admin.php?action=stats           - Statistiques globales
 *   GET  /api/admin.php?action=users           - Liste des utilisateurs
 *   GET  /api/admin.php?action=user&id=<id>    - Détails d'un utilisateur
 *   POST /api/admin.php?action=user_update     - Modifier un utilisateur
 *   POST /api/admin.php?action=user_delete     - Supprimer un utilisateur
 *   GET  /api/admin.php?action=logs            - Logs d'activité
 *   GET  /api/admin.php?action=sessions        - Sessions actives
 */

require_once __DIR__ . '/dbconfig.php';

// Toutes les actions admin requièrent d'être admin
$session = requireAdmin();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'stats':
        getStats();
        break;
    case 'users':
        getUsers();
        break;
    case 'user':
        getUser();
        break;
    case 'user_update':
        updateUser();
        break;
    case 'user_delete':
        deleteUser();
        break;
    case 'logs':
        getLogs();
        break;
    case 'sessions':
        getSessions();
        break;
    default:
        jsonError('Action admin non reconnue', 400);
}

// ─────────────────────────────────────────────────────────────────────────────
// Handlers
// ─────────────────────────────────────────────────────────────────────────────

function getStats() {
    $pdo = getDB();
    
    // Utilisateurs
    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    $totalUsers = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM users WHERE status = "active"');
    $activeUsers = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM users WHERE status = "suspended"');
    $suspendedUsers = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $newUsersWeek = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $newUsersMonth = (int)$stmt->fetchColumn();
    
    // Favoris
    $stmt = $pdo->query('SELECT COUNT(*) FROM favorites');
    $totalFavorites = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(DISTINCT station_id) FROM favorites');
    $uniqueStations = (int)$stmt->fetchColumn();
    
    // Top stations favorites
    $stmt = $pdo->query('
        SELECT station_id, station_name, station_city, COUNT(*) as count
        FROM favorites
        GROUP BY station_id
        ORDER BY count DESC
        LIMIT 10
    ');
    $topStations = $stmt->fetchAll();
    
    // Sessions actives
    $stmt = $pdo->query('SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()');
    $activeSessions = (int)$stmt->fetchColumn();
    
    // Activité récente
    $stmt = $pdo->query('
        SELECT action, COUNT(*) as count
        FROM activity_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY action
        ORDER BY count DESC
    ');
    $activityToday = $stmt->fetchAll();
    
    // Inscriptions par jour (30 derniers jours)
    $stmt = $pdo->query('
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM users
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ');
    $registrationsTrend = $stmt->fetchAll();
    
    jsonSuccess([
        'users' => [
            'total'         => $totalUsers,
            'active'        => $activeUsers,
            'suspended'     => $suspendedUsers,
            'newThisWeek'   => $newUsersWeek,
            'newThisMonth'  => $newUsersMonth
        ],
        'favorites' => [
            'total'          => $totalFavorites,
            'uniqueStations' => $uniqueStations,
            'topStations'    => $topStations
        ],
        'sessions' => [
            'active' => $activeSessions
        ],
        'activity' => [
            'today' => $activityToday
        ],
        'trends' => [
            'registrations' => $registrationsTrend
        ]
    ]);
}

function getUsers() {
    $pdo = getDB();
    
    // Pagination
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    
    // Filtres
    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? '';
    $role   = $_GET['role'] ?? '';
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = '(username LIKE ? OR email LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($status && in_array($status, ['active', 'suspended', 'pending'])) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($role && in_array($role, ['user', 'admin'])) {
        $where[] = 'role = ?';
        $params[] = $role;
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereClause");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    
    // Users with favorites count
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.role, u.status, u.created_at, u.last_login, u.login_count,
               (SELECT COUNT(*) FROM favorites f WHERE f.user_id = u.id) as favorites_count
        FROM users u
        $whereClause
        ORDER BY u.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    
    jsonSuccess([
        'users'      => $users,
        'pagination' => [
            'page'       => $page,
            'limit'      => $limit,
            'total'      => $total,
            'totalPages' => ceil($total / $limit)
        ]
    ]);
}

function getUser() {
    $userId = (int)($_GET['id'] ?? 0);
    if (!$userId) {
        jsonError('ID utilisateur requis');
    }
    
    $pdo = getDB();
    
    // User info
    $stmt = $pdo->prepare('
        SELECT id, username, email, role, status, created_at, last_login, login_count
        FROM users WHERE id = ?
    ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonError('Utilisateur non trouvé', 404);
    }
    
    // Favorites
    $stmt = $pdo->prepare('
        SELECT station_id, station_name, station_city, created_at
        FROM favorites
        WHERE user_id = ?
        ORDER BY created_at DESC
    ');
    $stmt->execute([$userId]);
    $favorites = $stmt->fetchAll();
    
    // Recent activity
    $stmt = $pdo->prepare('
        SELECT action, details, ip_address, created_at
        FROM activity_logs
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ');
    $stmt->execute([$userId]);
    $activity = $stmt->fetchAll();
    
    // Active sessions
    $stmt = $pdo->prepare('
        SELECT id, ip_address, user_agent, created_at, expires_at
        FROM sessions
        WHERE user_id = ? AND expires_at > NOW()
        ORDER BY created_at DESC
    ');
    $stmt->execute([$userId]);
    $sessions = $stmt->fetchAll();
    
    jsonSuccess([
        'user'      => $user,
        'favorites' => $favorites,
        'activity'  => $activity,
        'sessions'  => $sessions
    ]);
}

function updateUser() {
    global $session;
    
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = (int)($input['id'] ?? 0);
    
    if (!$userId) {
        jsonError('ID utilisateur requis');
    }
    
    $pdo = getDB();
    
    // Vérifier que l'utilisateur existe
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $targetUser = $stmt->fetch();
    
    if (!$targetUser) {
        jsonError('Utilisateur non trouvé', 404);
    }
    
    // Empêcher de modifier son propre statut/rôle (sécurité)
    if ($targetUser['id'] == $session['user_id']) {
        if (isset($input['status']) || isset($input['role'])) {
            jsonError('Vous ne pouvez pas modifier votre propre statut ou rôle.');
        }
    }
    
    $updates = [];
    $params  = [];
    
    // Champs modifiables
    if (isset($input['username'])) {
        $username = trim($input['username']);
        if (strlen($username) < 3 || strlen($username) > 50) {
            jsonError('Nom d\'utilisateur invalide');
        }
        $updates[] = 'username = ?';
        $params[] = $username;
    }
    
    if (isset($input['email'])) {
        $email = trim(strtolower($input['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonError('E-mail invalide');
        }
        $updates[] = 'email = ?';
        $params[] = $email;
    }
    
    if (isset($input['status']) && in_array($input['status'], ['active', 'suspended', 'pending'])) {
        $updates[] = 'status = ?';
        $params[] = $input['status'];
    }
    
    if (isset($input['role']) && in_array($input['role'], ['user', 'admin'])) {
        $updates[] = 'role = ?';
        $params[] = $input['role'];
    }
    
    // Nouveau mot de passe
    if (!empty($input['new_password'])) {
        if (strlen($input['new_password']) < 8) {
            jsonError('Le mot de passe doit contenir au moins 8 caractères');
        }
        $updates[] = 'password_hash = ?';
        $params[] = password_hash($input['new_password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }
    
    if (!$updates) {
        jsonError('Aucune modification fournie');
    }
    
    $params[] = $userId;
    $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?');
    $stmt->execute($params);
    
    logActivity($session['user_id'], 'admin_user_update', "Utilisateur #$userId modifié");
    
    jsonSuccess([], 'Utilisateur mis à jour');
}

function deleteUser() {
    global $session;
    
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = (int)($input['id'] ?? 0);
    
    if (!$userId) {
        jsonError('ID utilisateur requis');
    }
    
    if ($userId == $session['user_id']) {
        jsonError('Vous ne pouvez pas supprimer votre propre compte.');
    }
    
    $pdo = getDB();
    
    // Vérifier que l'utilisateur existe
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $targetUser = $stmt->fetch();
    
    if (!$targetUser) {
        jsonError('Utilisateur non trouvé', 404);
    }
    
    // Supprimer (CASCADE supprimera sessions et favoris)
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    
    logActivity($session['user_id'], 'admin_user_delete', "Utilisateur #{$targetUser['id']} ({$targetUser['username']}) supprimé");
    
    jsonSuccess([], 'Utilisateur supprimé');
}

function getLogs() {
    $pdo = getDB();
    
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(200, max(20, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    
    $userId = (int)($_GET['user_id'] ?? 0);
    $action = trim($_GET['action_filter'] ?? '');
    
    $where = [];
    $params = [];
    
    if ($userId) {
        $where[] = 'l.user_id = ?';
        $params[] = $userId;
    }
    if ($action) {
        $where[] = 'l.action = ?';
        $params[] = $action;
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs l $whereClause");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    
    // Logs with user info
    $stmt = $pdo->prepare("
        SELECT l.*, u.username
        FROM activity_logs l
        LEFT JOIN users u ON l.user_id = u.id
        $whereClause
        ORDER BY l.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    
    // Liste des actions distinctes (pour le filtre)
    $stmt = $pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action');
    $actions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    jsonSuccess([
        'logs'       => $logs,
        'actions'    => $actions,
        'pagination' => [
            'page'       => $page,
            'limit'      => $limit,
            'total'      => $total,
            'totalPages' => ceil($total / $limit)
        ]
    ]);
}

function getSessions() {
    $pdo = getDB();
    
    $stmt = $pdo->query('
        SELECT s.*, u.username, u.email
        FROM sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.expires_at > NOW()
        ORDER BY s.created_at DESC
        LIMIT 100
    ');
    $sessions = $stmt->fetchAll();
    
    jsonSuccess(['sessions' => $sessions]);
}
