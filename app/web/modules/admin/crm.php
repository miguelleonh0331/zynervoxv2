<?php
require_once __DIR__ . '/../../includes/Auth.php';
use Includes\Auth;

// Mismo nivel de acceso que "Panel de agente": cualquier usuario logueado.
Auth::checkAccess([1, 2, 3, 4, 5, 6, 7, 8, 9]);
$pageTitle = 'CRM';
$activeKey = 'crm';
require __DIR__ . '/_stub.php';
