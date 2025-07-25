<?php
/**
 * Area Commerciale - Index
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/session.php';

$auth = new Auth();
$session = Session::getInstance();

// Verifica autenticazione e ruolo
if (!$auth->isAuthenticated() || !$auth->hasRole('commerciale')) {
    header('Location: ../index.php');
    exit;
}

// Redirect alla dashboard
header('Location: dashboard.php');
exit;
