<?php
/**
 * ajax_create_taxonomy.php
 *
 * Schlanker JSON-Endpunkt, über den das Rasse-Formular
 * (admin_add_breed.php) per Fetch-Request neue Tags oder Aktivitäten
 * "inline" anlegen kann, ohne die Seite neu zu laden. Nutzt dieselbe
 * Model-Logik (inkl. Duplikat-Schutz) wie admin_manage_tags.php.
 *
 * Erwartet POST mit: csrf_token, type ('tag'|'activity'), name,
 * optional category (nur bei 'tag').
 *
 * Antwort: JSON { success: bool, message: string, item?: {id, name} }
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Tag.php';
require_once __DIR__ . '/models/Activity.php';

secure_session_start();

header('Content-Type: application/json; charset=utf-8');

// Nicht eingeloggte oder Nicht-Admin-Anfragen werden hier ohne Redirect
// abgelehnt (reiner JSON-Endpunkt, keine HTML-Umleitung sinnvoll).
if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt.']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ungültiges CSRF-Token. Bitte Seite neu laden.']);
    exit;
}

$type = $_POST['type'] ?? '';
$name = $_POST['name'] ?? '';

if ($type === 'tag') {
    $category = $_POST['category'] ?? null;
    $result = Tag::create($name, $category, null);

    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'],
            'item'    => ['id' => $result['tag_id'], 'name' => clean_input($name), 'category' => $category ? clean_input($category) : 'Sonstiges'],
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
    exit;
}

if ($type === 'activity') {
    $result = Activity::create($name, null);

    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'],
            'item'    => ['id' => $result['activity_id'], 'name' => clean_input($name)],
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unbekannter Typ.']);
