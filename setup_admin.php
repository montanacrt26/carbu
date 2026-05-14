<?php
/**
 * CarbuFrance - Script de création du compte administrateur
 * 
 * IMPORTANT: Exécutez ce script UNE SEULE FOIS après avoir créé les tables.
 * Supprimez-le ensuite ou renommez-le pour des raisons de sécurité.
 * 
 * Accédez à: https://votre-site.com/api/setup_admin.php
 */

require_once __DIR__ . '/dbconfig.php';

// Configuration du compte admin
$ADMIN_USERNAME = 'oguzhancrt';
$ADMIN_EMAIL    = 'admin@carbufrance.fr';
$ADMIN_PASSWORD = '92264580';

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CarbuFrance - Setup Admin</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #0f1117; color: #e8e9f0; padding: 2rem; max-width: 600px; margin: 0 auto; }
    .card { background: #1c1f2e; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem; }
    h1 { margin-top: 0; color: #8b5cf6; }
    .success { background: rgba(16,185,129,0.15); border-color: rgba(16,185,129,0.3); color: #10b981; }
    .error { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #ef4444; }
    .warning { background: rgba(245,158,11,0.15); border-color: rgba(245,158,11,0.3); color: #f59e0b; }
    code { background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px; font-size: 0.9em; }
    ul { padding-left: 1.5rem; }
    li { margin: 0.5rem 0; }
    a { color: #8b5cf6; }
  </style>
</head>
<body>
  <h1>CarbuFrance - Setup Admin</h1>';

try {
    $pdo = getDB();
    
    // Vérifier si le compte admin existe déjà
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$ADMIN_USERNAME, $ADMIN_EMAIL]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        echo '<div class="card warning">
            <strong>Compte admin existant</strong>
            <p>Un compte admin existe déjà avec le nom d\'utilisateur <code>' . htmlspecialchars($existing['username']) . '</code> (ID: ' . $existing['id'] . ').</p>
            <p>Si vous devez réinitialiser le mot de passe, supprimez d\'abord ce compte dans phpMyAdmin.</p>
        </div>';
    } else {
        // Créer le compte admin avec le mot de passe hashé
        $passwordHash = password_hash($ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]);
        
        $stmt = $pdo->prepare('
            INSERT INTO users (username, email, password_hash, role, status)
            VALUES (?, ?, ?, "admin", "active")
        ');
        $stmt->execute([$ADMIN_USERNAME, $ADMIN_EMAIL, $passwordHash]);
        $adminId = $pdo->lastInsertId();
        
        echo '<div class="card success">
            <strong>Compte admin créé avec succès!</strong>
            <ul>
                <li><strong>ID:</strong> ' . $adminId . '</li>
                <li><strong>Nom d\'utilisateur:</strong> <code>' . htmlspecialchars($ADMIN_USERNAME) . '</code></li>
                <li><strong>Email:</strong> <code>' . htmlspecialchars($ADMIN_EMAIL) . '</code></li>
                <li><strong>Mot de passe:</strong> <code>' . htmlspecialchars($ADMIN_PASSWORD) . '</code></li>
                <li><strong>Rôle:</strong> <code>admin</code></li>
            </ul>
        </div>';
        
        echo '<div class="card warning">
            <strong>IMPORTANT - Sécurité</strong>
            <p>Pour des raisons de sécurité, vous devriez:</p>
            <ul>
                <li>Supprimer ou renommer ce fichier (<code>setup_admin.php</code>)</li>
                <li>Changer le mot de passe du compte admin depuis le panel</li>
                <li>Mettre à jour <code>dbconfig.php</code> avec vos vrais identifiants de base de données</li>
            </ul>
        </div>';
    }
    
    echo '<div class="card">
        <strong>Prochaines étapes</strong>
        <ul>
            <li><a href="../admin.html">Accéder au Panel Admin</a></li>
            <li><a href="../app.html">Accéder à l\'application</a></li>
        </ul>
    </div>';
    
} catch (Exception $e) {
    echo '<div class="card error">
        <strong>Erreur</strong>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
        <p>Vérifiez que:</p>
        <ul>
            <li>Les tables ont été créées (exécutez <code>setup.sql</code> dans phpMyAdmin)</li>
            <li>Les identifiants dans <code>dbconfig.php</code> sont corrects</li>
            <li>MySQL/MariaDB est en cours d\'exécution</li>
        </ul>
    </div>';
}

echo '</body></html>';
