<?php
/**
 * logout.php — ends the staff session. POST only, CSRF-protected.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!Helpers::isPost()) {
    Helpers::redirect('index.php');
}

Security::requireCsrf();
Auth::logout('User signed out');
Helpers::flash('info', 'You have been signed out.');
Helpers::redirect('login.php');
