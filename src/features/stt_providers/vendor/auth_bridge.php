<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/Auth.php';

function stt_require_admin(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user']) || (int)($_SESSION['user_level'] ?? 0) !== 9) {
        $json = isset($_GET['action']) || isset($_POST['action']);
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Sesión administrativa requerida']);
        } else {
            header('Location: ../../../index.php');
        }
        exit;
    }
}
