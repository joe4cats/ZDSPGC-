<?php
/**
 * layout/header.php — the HTML shell: role-aware sidebar navigation, the
 * government strip, the mobile menu bar and flash messages. Pages set these
 * before including this file:
 *
 *   $PAGE_TITLE  (string)  <title> text
 *   $PAGE_ACTIVE (string)  nav key to highlight:
 *                          dashboard|scanner|events|students|records|reports|users|settings
 *   $PAGE_BARE   (bool)    true  -> full-screen layout with no navigation
 *                                 (scanner station & self check-in pages)
 *   $PAGE_SUB    (string)  small line under the page title
 */

declare(strict_types=1);

$PAGE_TITLE  = $PAGE_TITLE  ?? 'Dashboard';
$PAGE_ACTIVE = $PAGE_ACTIVE ?? '';
$PAGE_BARE   = $PAGE_BARE   ?? false;
$PAGE_SUB    = $PAGE_SUB    ?? '';
$flashes     = Helpers::takeFlashes();

/** Nav item helper: keeps the markup in one place. */
function nav_item(string $key, string $href, string $label, string $iconName, string $active, bool $visible = true): void
{
    if (!$visible) {
        return;
    }
    $cls = $key === $active ? ' class="active"' : '';
    echo '<a href="' . Helpers::e(Helpers::url($href)) . '"' . $cls . ' title="' . Helpers::e($label) . '">'
        . icon($iconName) . '<span>' . Helpers::e($label) . '</span></a>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= Helpers::e($PAGE_TITLE) ?> · <?= Helpers::e(APP_SHORT) ?></title>
  <meta name="csrf-token" content="<?= Helpers::e(Security::csrfToken()) ?>">
  <link rel="icon" type="image/png" href="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>">
  <link rel="stylesheet" href="<?= Helpers::e(Helpers::url('assets/css/style.css')) ?>">
</head>
<body class="<?= $PAGE_BARE ? 'bare' : '' ?>">
<?php if (!$PAGE_BARE): ?>
<div class="app-shell">
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="side-head">
      <a class="brand" href="<?= Helpers::e(Helpers::url('index.php')) ?>">
        <img class="brand-logo" src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="42" height="42">
        <span class="brand-text">
          <span class="name">QR Attendance</span>
          <span class="sub">Event check-in system</span>
        </span>
      </a>
    </div>

    <nav class="side-nav">
      <div class="side-heading">Menu</div>
      <?php
      nav_item('dashboard', 'index.php', 'Dashboard', 'dashboard', $PAGE_ACTIVE);
      nav_item('scanner', 'scan.php', 'QR Scanner', 'scan', $PAGE_ACTIVE, Auth::can('run_scanner'));
      nav_item('events', 'events.php', 'Events', 'calendar', $PAGE_ACTIVE, Auth::can('manage_events'));
      nav_item('students', 'students.php', 'Students', 'users', $PAGE_ACTIVE, Auth::can('manage_students'));
      nav_item('records', 'attendance.php', 'Attendance Records', 'list', $PAGE_ACTIVE, Auth::can('view_reports'));
      nav_item('reports', 'reports.php', 'Reports', 'chart', $PAGE_ACTIVE, Auth::can('view_reports'));
      nav_item('users', 'users.php', 'User Management', 'shield', $PAGE_ACTIVE, Auth::can('manage_users'));
      nav_item('settings', 'settings.php', 'Settings', 'settings', $PAGE_ACTIVE);
      ?>
    </nav>

    <div class="side-user">
      <?php $avatarInfo = Auth::avatarFile(); ?>
      <span class="avatar">
        <span aria-hidden="true"><?= Helpers::e(mb_strtoupper(mb_substr(Auth::userName() ?? '?', 0, 1))) ?></span>
        <?php if ($avatarInfo !== null): ?>
          <img class="avatar-img" src="<?= Helpers::e($avatarInfo[0]) ?>" alt="" onerror="this.remove()">
        <?php endif; ?>
      </span>
      <span class="user-meta">
        <strong><?= Helpers::e(Auth::userName() ?? 'Guest') ?></strong>
        <small><?= Helpers::e(Auth::roleLabel()) ?></small>
      </span>
      <form method="post" action="<?= Helpers::e(Helpers::url('logout.php')) ?>" class="inline-form side-logout">
        <?= Security::csrfField() ?>
        <button class="ghost sm" type="submit" title="Sign out"><?= icon('logout') ?><span class="signout-label">Sign out</span></button>
      </form>
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

  <div class="main-col">
    <div class="mobile-bar">
      <button id="menu-btn" class="menu-btn" type="button" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false"><?= icon('menu') ?></button>
      <span class="mobile-title"><?= Helpers::e($PAGE_TITLE) ?></span>
    </div>

    <div class="govbar">
      <span class="flag"><?= flag_ph() ?><?= Helpers::e(SCHOOL_NAME) ?> · <?= Helpers::e(SCHOOL_CAMPUS) ?></span>
      <span class="govbar-right"><?= icon('shield') ?> Official attendance record — <?= Helpers::e(date('F j, Y')) ?></span>
    </div>
<?php endif; ?>

<main class="<?= $PAGE_BARE ? 'bare-main' : '' ?>">
  <div class="<?= $PAGE_BARE ? 'bare-wrap' : 'wrap' ?>">
<?php if (!$PAGE_BARE): ?>
    <div class="page-head">
      <div>
        <h1><?= Helpers::e($PAGE_TITLE) ?></h1>
        <?php if ($PAGE_SUB !== ''): ?><p class="lead"><?= $PAGE_SUB ?></p><?php endif; ?>
      </div>
      <?php if (!empty($PAGE_ACTIONS)): ?>
        <div class="page-actions"><?= $PAGE_ACTIONS ?></div>
      <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($flashes as $flash): ?>
    <div class="flash <?= Helpers::e((string) $flash['type']) ?>">
      <?= icon($flash['type'] === 'error' ? 'alert' : ($flash['type'] === 'success' ? 'check' : 'bell')) ?>
      <span><?= Helpers::e((string) $flash['message']) ?></span>
    </div>
<?php endforeach; ?>
