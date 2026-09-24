<?php
/**
 * users.php — staff accounts (administrators only).
 *
 * Handles: create account, edit role/name, reset password, activate/deactivate.
 * Passwords are stored as bcrypt hashes; a password is never shown or logged.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('manage_users');

$editId = Helpers::getInt('edit');
$form   = [
    'id'        => 0,
    'username'  => '',
    'full_name' => '',
    'role'      => 'faculty',
    'status'    => 'active',
];

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) Helpers::post('action', 'save');

    if ($action === 'toggle') {
        $id   = Helpers::postInt('id');
        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if ($user !== null) {
            if ((int) $user['id'] === (int) Auth::id()) {
                Helpers::flash('error', 'You cannot deactivate the account you are signed in with.');
                Helpers::redirect('users.php');
            }
            $new = ($user['status'] === 'active') ? 'inactive' : 'active';
            Database::run('UPDATE users SET status = :s WHERE id = :id', ['s' => $new, 'id' => $id]);
            Security::audit('USER_STATUS', 'Set ' . $user['username'] . ' to ' . $new, 'users');
            Helpers::flash('success', $user['full_name'] . ' is now ' . $new . '.');
        }
        Helpers::redirect('users.php');
    }

    if ($action === 'delete') {
        $id   = Helpers::postInt('id');
        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if ($user !== null) {
            $adminsLeft = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
            if ((int) $user['id'] === (int) Auth::id()) {
                Helpers::flash('error', 'You cannot delete your own account.');
            } elseif ($user['role'] === 'admin' && $adminsLeft <= 1) {
                Helpers::flash('error', 'At least one active administrator must remain.');
            } else {
                Database::run('DELETE FROM users WHERE id = :id', ['id' => $id]);
                Security::audit('USER_DELETE', 'Deleted account ' . $user['username'], 'users');
                Helpers::flash('success', 'Account "' . $user['username'] . '" was deleted.');
            }
        }
        Helpers::redirect('users.php');
    }

    if ($action === 'password') {
        $id       = Helpers::postInt('id');
        $password = (string) (Helpers::post('password') ?? '');
        $user     = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);

        if (strlen($password) < 8) {
            Helpers::flash('error', 'The new password must be at least 8 characters.');
        } elseif ($user === null) {
            Helpers::flash('error', 'That account no longer exists.');
        } else {
            Database::run(
                'UPDATE users SET password_hash = :h WHERE id = :id',
                ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]
            );
            Security::audit('USER_PASSWORD_RESET', 'Password reset for ' . $user['username'], 'users');
            Helpers::flash('success', 'Password updated for ' . $user['full_name'] . '.');
        }
        Helpers::redirect('users.php');
    }

    /* ---- create / update ---- */
    $id       = Helpers::postInt('id');
    $username = strtolower(Security::clean(Helpers::post('username'), 60));
    $fullName = Security::clean(Helpers::post('full_name'), 120);
    $role     = Security::clean(Helpers::post('role'), 20);
    $status   = Helpers::post('status') === 'inactive' ? 'inactive' : 'active';
    $password = (string) (Helpers::post('password') ?? '');

    $errors = [];
    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }
    if ($id === 0 && !preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
        $errors[] = 'Username must be 3–60 characters (letters, numbers, dot, dash, underscore).';
    }
    if (!in_array($role, Auth::ROLES, true)) {
        $errors[] = 'Choose a valid role.';
    }
    if (($id === 0 || $password !== '') && strlen($password) < 8) {
        $errors[] = 'The password must be at least 8 characters.';
    }
    if ($username !== '') {
        $duplicate = Database::one(
            'SELECT id FROM users WHERE username = :u AND id <> :id',
            ['u' => $username, 'id' => $id]
        );
        if ($duplicate !== null) {
            $errors[] = 'That username is already taken.';
        }
    }

    if ($errors !== []) {
        foreach ($errors as $message) {
            Helpers::flash('error', $message);
        }
        $form = [
            'id'        => $id,
            'username'  => $username,
            'full_name' => $fullName,
            'role'      => $role,
            'status'    => $status,
        ];
        $editId = $id;
    } else {
        if ($id > 0) {
            $row = [
                'full_name' => $fullName,
                'role'      => $role,
                'status'    => $status,
            ];
            if ($password !== '') {
                $row['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            Database::update('users', $row, 'id = :id', ['id' => $id]);
            Security::audit('USER_UPDATE', 'Updated account ' . $username, 'users');
            Helpers::flash('success', 'Account "' . $username . '" was updated.');
        } else {
            Database::insert('users', [
                'username'      => $username,
                'full_name'     => $fullName,
                'role'          => $role,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status'        => $status,
                'created_at'    => Helpers::now(),
                'last_login_at' => null,
            ]);
            Security::audit('USER_CREATE', 'Created account ' . $username . ' (' . $role . ')', 'users');
            Helpers::flash('success', 'Account "' . $username . '" was created. Share the password privately.');
        }
        Helpers::redirect('users.php');
    }
} elseif ($editId > 0) {
    $existing = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $editId]);
    if ($existing !== null) {
        $form = [
            'id'        => (int) $existing['id'],
            'username'  => (string) $existing['username'],
            'full_name' => (string) $existing['full_name'],
            'role'      => (string) $existing['role'],
            'status'    => (string) $existing['status'],
        ];
    }
}

$users = Database::all('SELECT * FROM users ORDER BY role, full_name');
$audit = Database::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 40');

$PAGE_TITLE  = 'Staff accounts';
$PAGE_ACTIVE = 'users';
$PAGE_SUB    = count($users) . ' account(s) · roles: administrator, officer, faculty';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="grid cols-2">
  <div class="card">
    <h3><?= icon($form['id'] > 0 ? 'edit' : 'plus') ?> <?= $form['id'] > 0 ? 'Edit account' : 'Create an account' ?></h3>
    <form method="post" action="<?= Helpers::e(Helpers::url('users.php')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

      <div class="grid cols-2">
        <label class="field">
          <span>Username *</span>
          <input type="text" name="username" maxlength="60" required <?= $form['id'] > 0 ? 'readonly' : '' ?>
                 value="<?= Helpers::e((string) $form['username']) ?>" placeholder="j.delacruz">
        </label>
        <label class="field">
          <span>Full name *</span>
          <input type="text" name="full_name" maxlength="120" required value="<?= Helpers::e((string) $form['full_name']) ?>" placeholder="Juan D. Dela Cruz">
        </label>
      </div>

      <div class="grid cols-3">
        <label class="field">
          <span>Role</span>
          <select name="role">
            <?php foreach (Auth::ROLES as $role): ?>
              <option value="<?= Helpers::e($role) ?>" <?= $form['role'] === $role ? 'selected' : '' ?>>
                <?= Helpers::e(Auth::roleLabel($role)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Status</span>
          <select name="status">
            <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </label>
        <label class="field">
          <span><?= $form['id'] > 0 ? 'New password (optional)' : 'Initial password *' ?></span>
          <input type="password" name="password" minlength="8" autocomplete="new-password"
                 <?= $form['id'] > 0 ? '' : 'required' ?>>
          <span class="hint">At least 8 characters · stored as a bcrypt hash.</span>
        </label>
      </div>

      <div class="row">
        <button type="submit"><?= icon('check') ?><span><?= $form['id'] > 0 ? 'Save changes' : 'Create account' ?></span></button>
        <?php if ($form['id'] > 0): ?>
          <a class="btn ghost" href="<?= Helpers::e(Helpers::url('users.php')) ?>"><?= icon('x-circle') ?><span>Cancel edit</span></a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h3><?= icon('lock') ?> Roles &amp; permissions</h3>
    <table class="tbl">
      <thead><tr><th>Capability</th><th class="center">Admin</th><th class="center">Officer</th><th class="center">Faculty</th></tr></thead>
      <tbody>
        <tr><td>Manage staff accounts</td><td class="center">yes</td><td class="center">—</td><td class="center">—</td></tr>
        <tr><td>Manage students &amp; QR IDs</td><td class="center">yes</td><td class="center">yes</td><td class="center">—</td></tr>
        <tr><td>Manage events</td><td class="center">yes</td><td class="center">yes</td><td class="center">—</td></tr>
        <tr><td>Run the scan station</td><td class="center">yes</td><td class="center">yes</td><td class="center">yes</td></tr>
        <tr><td>View &amp; export reports</td><td class="center">yes</td><td class="center">yes</td><td class="center">yes</td></tr>
      </tbody>
    </table>
    <p class="hint mt">Every sign-in, scan, edit, delete and export is appended to the audit log below with the operator
      name, IP address and timestamp. The log cannot be edited from the interface.</p>
  </div>
</div>

<h2 class="section-title"><?= icon('users') ?><span>Accounts (<?= count($users) ?>)</span></h2>

<div class="card">
  <div class="table-wrap">
    <table class="tbl responsive">
      <thead>
        <tr><th>Account</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Created</th><th class="right">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $user): $uid = (int) $user['id']; ?>
        <tr>
          <td data-label="Account"><strong><?= Helpers::e((string) $user['full_name']) ?></strong><br>
            <span class="small muted mono"><?= Helpers::e((string) $user['username']) ?></span>
            <?php if ($uid === (int) Auth::id()): ?> <span class="badge blue">you</span><?php endif; ?></td>
          <td class="small" data-label="Role"><?= Helpers::e(Auth::roleLabel((string) $user['role'])) ?></td>
          <td data-label="Status"><?= (string) $user['status'] === 'active'
              ? '<span class="badge">active</span>'
              : '<span class="badge grey">inactive</span>' ?></td>
          <td class="small" data-label="Last sign-in"><?= Helpers::e($user['last_login_at'] === null ? 'never' : Helpers::humanAgo((string) $user['last_login_at'])) ?></td>
          <td class="small" data-label="Created"><?= Helpers::e(Helpers::fmtDate((string) $user['created_at'])) ?></td>
          <td class="right nowrap" data-label="Actions">
            <div class="link-actions" style="justify-content:flex-end;">
              <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('users.php', ['edit' => $uid])) ?>"><?= icon('edit') ?><span>Edit</span></a>
              <form method="post" class="inline-form">
                <?= Security::csrfField() ?>
                <input type="hidden" name="id" value="<?= $uid ?>">
                <button class="sm ghost" name="action" value="toggle" type="submit"><?= icon('lock') ?>
                  <span><?= (string) $user['status'] === 'active' ? 'Disable' : 'Enable' ?></span></button>
              </form>
              <form method="post" class="inline-form" data-confirm="Delete this staff account?">
                <?= Security::csrfField() ?>
                <input type="hidden" name="id" value="<?= $uid ?>">
                <button class="sm ghost red" name="action" value="delete" type="submit"><?= icon('trash') ?><span>Delete</span></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mt">
  <h3><?= icon('shield') ?> Audit log (latest <?= count($audit) ?>)</h3>
  <div class="row between mb">
    <input type="search" data-filter-table="#audit-table" data-filter-count="#audit-count" placeholder="filter the log">
    <span class="badge grey" id="audit-count"><?= count($audit) ?> shown</span>
  </div>
  <?php if ($audit === []): ?>
    <p class="empty">Nothing logged yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl responsive" id="audit-table">
        <thead><tr><th>When</th><th>Actor</th><th>Role</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($audit as $row): ?>
          <tr>
            <td class="small nowrap" data-label="When"><?= Helpers::e(Helpers::fmtDateTime((string) $row['created_at'])) ?></td>
            <td class="small" data-label="Actor"><?= Helpers::e((string) $row['actor']) ?></td>
            <td class="small" data-label="Role"><?= Helpers::e((string) $row['role']) ?></td>
            <td class="small mono" data-label="Action"><?= Helpers::e((string) $row['action']) ?></td>
            <td class="small" data-label="Detail"><?= Helpers::e((string) $row['detail']) ?></td>
            <td class="small mono" data-label="IP"><?= Helpers::e((string) ($row['ip'] !== '' ? $row['ip'] : '—')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
