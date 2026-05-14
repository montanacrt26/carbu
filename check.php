<?php
/**
 * CarbuFrance — Diagnostic du serveur
 * 
 * Acces : https://votre-site.com/api/check.php
 * 
 * Verifie que PHP fonctionne, que la base de donnees est joignable
 * et que les tables existent.
 *
 * IMPORTANT : Supprimez ce fichier apres avoir verifie que tout fonctionne.
 */

header('Content-Type: text/html; charset=utf-8');

function pill($ok, $text) {
    $bg    = $ok ? '#10b981' : '#ef4444';
    $label = $ok ? 'OK'      : 'ERREUR';
    return "<span style='display:inline-block;background:$bg;color:#fff;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:0.04em'>$label</span> $text";
}

$checks = [];
$checks[] = pill(true, 'PHP version : ' . phpversion());
$checks[] = pill(extension_loaded('pdo_mysql'), 'Extension PDO MySQL');
$checks[] = pill(extension_loaded('json'),       'Extension JSON');
$checks[] = pill(extension_loaded('openssl'),    'Extension OpenSSL');
$checks[] = pill(function_exists('password_hash'), 'password_hash()');

// Try to load dbconfig and connect
$dbStatus = null;
$tableStatus = [];
try {
    require_once __DIR__ . '/dbconfig.php';
    $pdo = getDB();
    $dbStatus = pill(true, "Connexion a la base de donnees : " . DB_NAME . " (host: " . DB_HOST . ")");
    
    $tables = ['users', 'sessions', 'favorites', 'activity_logs'];
    foreach ($tables as $t) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $tableStatus[] = pill(true, "Table <code>$t</code> existe (" . (int)$count . " ligne(s))");
        } catch (Throwable $e) {
            $tableStatus[] = pill(false, "Table <code>$t</code> introuvable : " . htmlspecialchars($e->getMessage()));
        }
    }

    // Check admin account
    try {
        $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE username = ? OR email = ?");
        $stmt->execute(['oguzhancrt', 'admin@carbufrance.fr']);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            $tableStatus[] = pill($admin['role'] === 'admin',
                "Compte admin trouve : <code>{$admin['username']}</code> (role : {$admin['role']})");
        } else {
            $tableStatus[] = pill(false,
                "Compte admin <code>oguzhancrt</code> introuvable. Executez <code>setup_admin.php</code>.");
        }
    } catch (Throwable $e) {
        $tableStatus[] = pill(false, 'Lecture admin : ' . htmlspecialchars($e->getMessage()));
    }
} catch (Throwable $e) {
    $dbStatus = pill(false, 'Connexion DB echouee : ' . htmlspecialchars($e->getMessage()));
}

// Test a real JSON output
$jsonTest = json_encode(['success' => true, 'message' => 'PHP renvoie bien du JSON.']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>CarbuFrance &mdash; Diagnostic</title>
<style>
  body { font-family: system-ui, sans-serif; background: #0f1117; color: #eef0f6; padding: 2rem; line-height: 1.6; }
  h1 { font-size: 1.5rem; margin-bottom: 1rem; }
  h2 { font-size: 1rem; margin: 1.5rem 0 0.5rem; color: #b8bcc8; text-transform: uppercase; letter-spacing: 0.06em; }
  ul { list-style: none; padding: 0; }
  li { padding: 0.4rem 0; border-bottom: 1px solid rgba(255,255,255,0.08); }
  code { background: rgba(139,92,246,0.15); color: #c4b5fd; padding: 1px 6px; border-radius: 4px; font-size: 0.85em; }
  .json-test { background: #161922; border: 1px solid rgba(255,255,255,0.08); padding: 1rem; border-radius: 8px; margin-top: 1rem; font-family: monospace; font-size: 0.85rem; }
  .warn { background: rgba(245,158,11,0.12); border: 1px solid rgba(245,158,11,0.35); color: #fbbf24; padding: 0.8rem 1rem; border-radius: 8px; margin: 1rem 0; }
</style>
</head>
<body>
  <h1>CarbuFrance &mdash; Diagnostic du serveur</h1>

  <div class="warn">
    Supprimez ce fichier (<code>api/check.php</code>) apres avoir verifie que tout fonctionne, pour des raisons de securite.
  </div>

  <h2>Environnement PHP</h2>
  <ul>
    <?php foreach ($checks as $c): ?><li><?= $c ?></li><?php endforeach; ?>
  </ul>

  <h2>Base de donnees</h2>
  <ul>
    <li><?= $dbStatus ?></li>
    <?php foreach ($tableStatus as $t): ?><li><?= $t ?></li><?php endforeach; ?>
  </ul>

  <h2>Sortie JSON brute (doit etre du JSON valide)</h2>
  <div class="json-test"><?= htmlspecialchars($jsonTest) ?></div>

  <h2>Prochaines etapes</h2>
  <ol>
    <li>Si une table manque, executez le contenu de <code>api/setup.sql</code> dans phpMyAdmin.</li>
    <li>Si le compte admin manque, ouvrez <code>api/setup_admin.php</code> dans le navigateur.</li>
    <li>Si la connexion DB echoue, editez <code>api/dbconfig.php</code> (DB_HOST, DB_USER, DB_PASS, DB_NAME).</li>
    <li>Une fois tout vert, supprimez <code>api/check.php</code> et <code>api/setup_admin.php</code>.</li>
  </ol>
</body>
</html>
