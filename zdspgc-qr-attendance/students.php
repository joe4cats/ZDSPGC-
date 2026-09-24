<?php
/**
 * students.php — the student roster: search, add, edit, deactivate, issue QR IDs.
 *
 * Each student has a signed QR token derived from their student number + a
 * nonce. "New QR" replaces the nonce, which instantly voids an ID that was
 * lost, copied or abused.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('manage_students');

$COURSES = ['BSIT', 'BSED', 'BEED', 'BSBA', 'BSHM', 'BSA', 'BSCrim'];
$YEARS   = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

$editId = Helpers::getInt('edit');
$form   = [
    'id'         => 0,
    'student_no' => '',
    'full_name'  => '',
    'course'     => 'BSIT',
    'year_level' => '1st Year',
    'section'    => '',
    'email'      => '',
    'contact'    => '',
    'status'     => 'active',
];

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) Helpers::post('action', 'save');

    if ($action === 'delete') {
        $id      = Helpers::postInt('id');
        $student = Database::one('SELECT * FROM students WHERE id = :id', ['id' => $id]);
        if ($student !== null) {
            Database::run('DELETE FROM students WHERE id = :id', ['id' => $id]);
            Security::audit('STUDENT_DELETE', 'Deleted ' . $student['student_no'] . ' (' . $student['full_name'] . ')', 'students');
            Helpers::flash('success', 'Student record deleted together with its attendance history.');
        }
        Helpers::redirect('students.php');
    }

    if ($action === 'toggle') {
        $id      = Helpers::postInt('id');
        $student = Database::one('SELECT * FROM students WHERE id = :id', ['id' => $id]);
        if ($student !== null) {
            $new = ($student['status'] === 'active') ? 'inactive' : 'active';
            Database::run('UPDATE students SET status = :s WHERE id = :id', ['s' => $new, 'id' => $id]);
            Security::audit('STUDENT_STATUS', 'Set ' . $student['student_no'] . ' to ' . $new, 'students');
            Helpers::flash('success', $student['full_name'] . ' is now ' . $new . '.');
        }
        Helpers::redirect('students.php');
    }

    if ($action === 'reissue') {
        $id      = Helpers::postInt('id');
        $student = Database::one('SELECT * FROM students WHERE id = :id', ['id' => $id]);
        if ($student !== null) {
            Database::run(
                'UPDATE students SET qr_nonce = :n, qr_issued_at = :t WHERE id = :id',
                ['n' => Security::nonce(), 't' => Helpers::now(), 'id' => $id]
            );
            Security::audit('STUDENT_QR_REISSUE', 'New QR ID for ' . $student['student_no'], 'students');
            Helpers::flash('success', 'A new QR ID was issued for ' . $student['full_name'] . '. The old QR no longer works.');
        }
        Helpers::redirect('students.php');
    }

    /* ---- create / update ---- */
    $id        = Helpers::postInt('id');
    $studentNo = Security::clean(Helpers::post('student_no'), 30);
    $fullName  = Security::clean(Helpers::post('full_name'), 120);
    $course    = Security::clean(Helpers::post('course'), 60);
    $year      = Security::clean(Helpers::post('year_level'), 20);
    $section   = Security::clean(Helpers::post('section'), 40);
    $email     = Security::clean(Helpers::post('email'), 120);
    $contact   = Security::clean(Helpers::post('contact'), 30);
    $status    = Helpers::post('status') === 'inactive' ? 'inactive' : 'active';

    $errors = [];
    if ($studentNo === '') {
        $errors[] = 'Student number is required.';
    }
    if ($fullName === '') {
        $errors[] = 'Student name is required.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email address does not look valid.';
    }
    $duplicate = $studentNo === '' ? null : Database::one(
        'SELECT id FROM students WHERE student_no = :no AND id <> :id',
        ['no' => $studentNo, 'id' => $id]
    );
    if ($duplicate !== null) {
        $errors[] = 'Student number "' . $studentNo . '" is already on record.';
    }

    if ($errors !== []) {
        foreach ($errors as $message) {
            Helpers::flash('error', $message);
        }
        $form = array_merge($form, [
            'id'         => $id,
            'student_no' => $studentNo,
            'full_name'  => $fullName,
            'course'     => $course,
            'year_level' => $year,
            'section'    => $section,
            'email'      => $email,
            'contact'    => $contact,
            'status'     => $status,
        ]);
        $editId = $id;
    } else {
        $row = [
            'student_no' => $studentNo,
            'full_name'  => $fullName,
            'course'     => $course,
            'year_level' => $year,
            'section'    => $section,
            'email'      => $email,
            'contact'    => $contact,
            'status'     => $status,
        ];
        if ($id > 0) {
            Database::update('students', $row, 'id = :id', ['id' => $id]);
            Security::audit('STUDENT_UPDATE', 'Updated ' . $studentNo, 'students');
            Helpers::flash('success', $fullName . ' was updated.');
        } else {
            $row['qr_nonce']     = Security::nonce();
            $row['created_at']   = Helpers::now();
            $row['qr_issued_at'] = Helpers::now();
            Database::insert('students', $row);
            Security::audit('STUDENT_CREATE', 'Added ' . $studentNo . ' (' . $fullName . ')', 'students');
            Helpers::flash('success', $fullName . ' was added. Print the QR ID from the student page or the QR sheet.');
        }
        Helpers::redirect('students.php');
    }
} elseif ($editId > 0) {
    $existing = Database::one('SELECT * FROM students WHERE id = :id', ['id' => $editId]);
    if ($existing !== null) {
        $form = [
            'id'         => (int) $existing['id'],
            'student_no' => (string) $existing['student_no'],
            'full_name'  => (string) $existing['full_name'],
            'course'     => (string) $existing['course'],
            'year_level' => (string) $existing['year_level'],
            'section'    => (string) $existing['section'],
            'email'      => (string) $existing['email'],
            'contact'    => (string) $existing['contact'],
            'status'     => (string) $existing['status'],
        ];
    }
}

/* ---- filters + pagination ---- */
$search  = Security::clean(Helpers::get('q'), 60);
$courseF = Security::clean(Helpers::get('course'), 60);
$statusF = Security::clean(Helpers::get('status'), 20);
$page    = Helpers::page();
$perPage = 25;

$where  = ['1 = 1'];
$params = [];

if ($search !== '') {
    $where[]      = '(s.student_no LIKE :q1 OR s.full_name LIKE :q2 OR s.email LIKE :q3)';
    $needle       = '%' . $search . '%';
    $params['q1'] = $needle;
    $params['q2'] = $needle;
    $params['q3'] = $needle;
}
if ($courseF !== '') {
    $where[]          = 's.course = :course';
    $params['course'] = $courseF;
}
if (in_array($statusF, ['active', 'inactive'], true)) {
    $where[]          = 's.status = :status';
    $params['status'] = $statusF;
}

$whereSql = implode(' AND ', $where);
$total    = (int) Database::scalar('SELECT COUNT(*) FROM students s WHERE ' . $whereSql, $params);
$pages    = max(1, (int) ceil($total / $perPage));
$page     = min($page, $pages);

$students = Database::all(
    'SELECT s.*,
            (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id) AS visits,
            (SELECT MAX(a.checked_in_at) FROM attendance a WHERE a.student_id = s.id) AS last_visit
     FROM students s
     WHERE ' . $whereSql . '
     ORDER BY s.full_name
     LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
    $params
);

$PAGE_TITLE   = 'Students';
$PAGE_ACTIVE  = 'students';
$PAGE_SUB     = $total . ' record(s) on file · QR IDs can be re-issued anytime';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('qr_cards.php')) . '">'
    . icon('qr') . '<span>Print QR ID cards</span></a>';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="grid cols-2">
  <div class="card">
    <h3><?= icon($form['id'] > 0 ? 'edit' : 'plus') ?> <?= $form['id'] > 0 ? 'Edit student' : 'Add a student' ?></h3>
    <form method="post" action="<?= Helpers::e(Helpers::url('students.php')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

      <div class="grid cols-2">
        <label class="field">
          <span>Student number *</span>
          <input type="text" name="student_no" maxlength="30" required value="<?= Helpers::e((string) $form['student_no']) ?>" placeholder="2024-00308">
        </label>
        <label class="field">
          <span>Full name * <span class="muted small">(Surname, First name)</span></span>
          <input type="text" name="full_name" maxlength="120" required value="<?= Helpers::e((string) $form['full_name']) ?>" placeholder="Fuentes, Joshue D.">
        </label>
      </div>

      <div class="grid cols-3">
        <label class="field">
          <span>Course</span>
          <select name="course">
            <?php foreach ($COURSES as $option): ?>
              <option value="<?= Helpers::e($option) ?>" <?= $form['course'] === $option ? 'selected' : '' ?>><?= Helpers::e($option) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Year level</span>
          <select name="year_level">
            <?php foreach ($YEARS as $option): ?>
              <option value="<?= Helpers::e($option) ?>" <?= $form['year_level'] === $option ? 'selected' : '' ?>><?= Helpers::e($option) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Section</span>
          <input type="text" name="section" maxlength="40" value="<?= Helpers::e((string) $form['section']) ?>" placeholder="A">
        </label>
      </div>

      <div class="grid cols-3">
        <label class="field">
          <span>Email</span>
          <input type="text" name="email" maxlength="120" value="<?= Helpers::e((string) $form['email']) ?>">
        </label>
        <label class="field">
          <span>Contact number</span>
          <input type="text" name="contact" maxlength="30" value="<?= Helpers::e((string) $form['contact']) ?>" placeholder="0917-000-0000">
        </label>
        <label class="field">
          <span>Status</span>
          <select name="status">
            <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive (blocked)</option>
          </select>
        </label>
      </div>

      <div class="row">
        <button type="submit"><?= icon('check') ?><span><?= $form['id'] > 0 ? 'Save changes' : 'Add student' ?></span></button>
        <?php if ($form['id'] > 0): ?>
          <a class="btn ghost" href="<?= Helpers::e(Helpers::url('students.php')) ?>"><?= icon('x-circle') ?><span>Cancel edit</span></a>
        <?php endif; ?>
      </div>
      <p class="hint">Saving issues a signed QR ID immediately — print it from the student page.</p>
    </form>
  </div>

  <div class="card">
    <h3><?= icon('search') ?> Find a student</h3>
    <form class="filters" method="get" action="<?= Helpers::e(Helpers::url('students.php')) ?>">
      <label class="field" style="grid-column: span 2;">
        <span>Name, number or email</span>
        <input type="search" name="q" value="<?= Helpers::e($search) ?>">
      </label>
      <label class="field">
        <span>Course</span>
        <select name="course">
          <option value="">All courses</option>
          <?php foreach ($COURSES as $option): ?>
            <option value="<?= Helpers::e($option) ?>" <?= $courseF === $option ? 'selected' : '' ?>><?= Helpers::e($option) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span>Status</span>
        <select name="status">
          <option value="">Any</option>
          <option value="active" <?= $statusF === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $statusF === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </label>
      <div class="row" style="grid-column: 1 / -1;">
        <button type="submit"><?= icon('search') ?><span>Filter</span></button>
        <a class="btn ghost" href="<?= Helpers::e(Helpers::url('students.php')) ?>">Reset</a>
      </div>
    </form>

    <table class="tbl mt">
      <tbody>
        <tr><td>Active students</td><td class="num"><?= Attendance::activeStudentCount() ?></td></tr>
        <tr><td>Filtered result</td><td class="num"><?= (int) $total ?></td></tr>
        <tr><td>Page</td><td class="num"><?= (int) $page ?> of <?= (int) $pages ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<h2 class="section-title"><?= icon('users') ?><span>Roster (<?= count($students) ?> on this page)</span></h2>

<div class="card">
  <?php if ($students === []): ?>
    <p class="empty">No students match those filters.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Student</th><th>Course</th><th class="num">Events attended</th><th>Last scan</th><th>Status</th><th class="right">Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($students as $student): $sid = (int) $student['id']; ?>
          <tr>
            <td>
              <a href="<?= Helpers::e(Helpers::url('student.php', ['id' => $sid])) ?>"><strong><?= Helpers::e((string) $student['full_name']) ?></strong></a><br>
              <span class="small muted mono"><?= Helpers::e((string) $student['student_no']) ?></span>
            </td>
            <td class="small"><?= Helpers::e((string) $student['course']) ?><br>
              <span class="muted"><?= Helpers::e((string) $student['year_level']) ?> <?= Helpers::e((string) $student['section']) ?></span></td>
            <td class="num"><?= (int) $student['visits'] ?></td>
            <td class="small"><?= Helpers::e($student['last_visit'] === null ? 'never' : Helpers::humanAgo((string) $student['last_visit'])) ?></td>
            <td><?= (string) $student['status'] === 'active'
                ? '<span class="badge">active</span>'
                : '<span class="badge grey">inactive</span>' ?></td>
            <td class="right nowrap">
              <div class="link-actions" style="justify-content:flex-end;">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('student.php', ['id' => $sid])) ?>"><?= icon('qr') ?><span>QR ID</span></a>
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('students.php', ['edit' => $sid])) ?>"><?= icon('edit') ?><span>Edit</span></a>
                <form method="post" class="inline-form">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="id" value="<?= $sid ?>">
                  <button class="sm ghost" name="action" value="toggle" type="submit"><?= icon('lock') ?><span>Toggle</span></button>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete this student and their attendance history?">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="id" value="<?= $sid ?>">
                  <button class="sm ghost red" name="action" value="delete" type="submit"><?= icon('trash') ?><span>Delete</span></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="pager">
        <?php
        $baseQuery = array_filter(['q' => $search, 'course' => $courseF, 'status' => $statusF], static fn ($v) => $v !== '');
        for ($p = 1; $p <= $pages; $p++):
            $target = Helpers::url('students.php', $baseQuery + ['page' => $p]);
        ?>
          <a class="btn sm <?= $p === $page ? '' : 'ghost' ?>" href="<?= Helpers::e($target) ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
