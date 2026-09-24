<?php
/**
 * student.php — one student: profile, printable QR ID and attendance history.
 *
 * Reprinting the QR ID is always possible because the token is derived from the
 * student number + the nonce stored in the database. "New QR ID" changes the
 * nonce, which immediately invalidates any old printout or photo of the code.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('manage_students');

$id      = Helpers::getInt('id');
$student = Database::one('SELECT * FROM students WHERE id = :id', ['id' => $id]);

if ($student === null) {
    Helpers::flash('error', 'That student record does not exist.');
    Helpers::redirect('students.php');
}

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) Helpers::post('action', 'reissue');

    if ($action === 'reissue') {
        Database::run(
            'UPDATE students SET qr_nonce = :n, qr_issued_at = :t WHERE id = :id',
            ['n' => Security::nonce(), 't' => Helpers::now(), 'id' => $id]
        );
        Security::audit('STUDENT_QR_REISSUE', 'New QR ID for ' . $student['student_no'], 'students');
        Helpers::flash('success', 'New QR ID issued. The previous QR code no longer works.');
        Helpers::redirect('student.php?id=' . $id);
    }

    if ($action === 'toggle') {
        $new = ($student['status'] === 'active') ? 'inactive' : 'active';
        Database::run('UPDATE students SET status = :s WHERE id = :id', ['s' => $new, 'id' => $id]);
        Security::audit('STUDENT_STATUS', 'Set ' . $student['student_no'] . ' to ' . $new, 'students');
        Helpers::flash('success', $student['full_name'] . ' is now ' . $new . '.');
        Helpers::redirect('student.php?id=' . $id);
    }
}

$history = Attendance::studentHistory($id, 100);
$token   = Attendance::studentToken($student);
$visits  = count($history);
$late    = count(array_filter($history, static fn ($row) => (string) $row['status'] === Attendance::LATE));

$PAGE_TITLE  = (string) $student['full_name'];
$PAGE_ACTIVE = 'students';
$PAGE_SUB    = '<span class="mono">' . Helpers::e((string) $student['student_no']) . '</span> · '
    . Helpers::e((string) $student['course']) . ' ' . Helpers::e((string) $student['year_level'])
    . ((string) $student['section'] !== '' ? ' · Section ' . Helpers::e((string) $student['section']) : '');

$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('students.php', ['edit' => $id])) . '">'
    . icon('edit') . '<span>Edit details</span></a>'
    . '<button class="btn ghost" data-print>' . icon('print') . '<span>Print</span></button>';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="grid cols-2">
  <div class="card">
    <h3><?= icon('user') ?> Student profile</h3>
    <table class="tbl">
      <tbody>
        <tr><td>Student number</td><td class="mono"><?= Helpers::e((string) $student['student_no']) ?></td></tr>
        <tr><td>Name</td><td><?= Helpers::e((string) $student['full_name']) ?></td></tr>
        <tr><td>Course / year</td><td><?= Helpers::e((string) $student['course']) ?> · <?= Helpers::e((string) $student['year_level']) ?> <?= Helpers::e((string) $student['section']) ?></td></tr>
        <tr><td>Email</td><td><?= Helpers::e((string) $student['email'] !== '' ? (string) $student['email'] : '—') ?></td></tr>
        <tr><td>Contact</td><td><?= Helpers::e((string) $student['contact'] !== '' ? (string) $student['contact'] : '—') ?></td></tr>
        <tr><td>Status</td><td><?= (string) $student['status'] === 'active'
            ? '<span class="badge">active</span>'
            : '<span class="badge grey">inactive — scans are rejected</span>' ?></td></tr>
        <tr><td>QR issued</td><td><?= Helpers::e(Helpers::fmtDateTime($student['qr_issued_at'] === null ? null : (string) $student['qr_issued_at'])) ?></td></tr>
        <tr><td>Record created</td><td><?= Helpers::e(Helpers::fmtDateTime((string) $student['created_at'])) ?></td></tr>
      </tbody>
    </table>

    <div class="stat-grid mt">
      <div class="stat"><div class="num"><?= $visits ?></div><div class="lbl">Events attended</div></div>
      <div class="stat red"><div class="num"><?= $late ?></div><div class="lbl">Late arrivals</div></div>
      <div class="stat"><div class="num"><?= Helpers::percent($visits - $late, max(1, $visits)) ?>%</div><div class="lbl">Punctuality</div></div>
    </div>

    <div class="row mt">
      <form method="post" class="inline-form" data-confirm="Issue a new QR ID? The student's current QR (and any photo of it) will stop working.">
        <?= Security::csrfField() ?>
        <button class="ghost red" name="action" value="reissue" type="submit"><?= icon('refresh') ?><span>New QR ID</span></button>
      </form>
      <form method="post" class="inline-form">
        <?= Security::csrfField() ?>
        <button class="ghost" name="action" value="toggle" type="submit"><?= icon('lock') ?>
          <span><?= (string) $student['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?></span></button>
      </form>
      <a class="btn ghost" href="<?= Helpers::e(Helpers::url('attendance.php', ['student_id' => $id])) ?>"><?= icon('list') ?><span>All records</span></a>
    </div>
  </div>

  <div class="card">
    <h3><?= icon('qr') ?> QR ID (signed)</h3>
    <div class="id-card">
      <div class="id-qr">
        <div id="student-qr" class="qr card-qr" data-print-qr data-qr="<?= Helpers::e($token) ?>"
             data-qr-name="qr-id-<?= Helpers::e((string) $student['student_no']) ?>"
             data-qr-label="<?= Helpers::e((string) $student['full_name']) ?>" data-qr-cell="4">
        </div>
      </div>
      <div>
        <h4><?= Helpers::e((string) $student['full_name']) ?></h4>
        <div class="id-no"><?= Helpers::e((string) $student['student_no']) ?></div>
        <div class="small muted"><?= Helpers::e((string) $student['course']) ?> · <?= Helpers::e((string) $student['year_level']) ?> <?= Helpers::e((string) $student['section']) ?></div>
        <div class="small mt"><strong><?= Helpers::e(SCHOOL_NAME) ?></strong><br>
          <span class="muted">Official event attendance ID</span></div>
        <div class="id-foot">Scan at the event check-in station. Do not share — anyone holding this code can check in as the student.</div>
      </div>
    </div>

    <div class="qr-tools mt no-print">
      <button class="sm ghost" type="button" data-print-qr-target="student-qr"><?= icon('print') ?><span>Print QR</span></button>
      <button class="sm ghost" type="button" data-save-qr-target="student-qr"><?= icon('download') ?><span>Save PNG</span></button>
      <button class="sm ghost" type="button" data-copy="<?= Helpers::e($token) ?>"><?= icon('link') ?><span>Copy token</span></button>
    </div>

    <p class="small muted mt no-print">The token is an HMAC-signed string
      (<span class="mono">1|S|student_no|nonce</span>). The server verifies the signature <em>and</em> that the nonce
      still matches the database, so a revoked ID is rejected even if the QR image was copied.</p>
  </div>
</div>

<h2 class="section-title"><?= icon('list') ?><span>Attendance history (<?= $visits ?>)</span></h2>

<div class="card">
  <?php if ($history === []): ?>
    <p class="empty">This student has not been recorded in any event yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl responsive">
        <thead>
          <tr><th>When</th><th>Event</th><th>Venue</th><th>Status</th><th>Method</th><th>Recorded by</th></tr>
        </thead>
        <tbody>
        <?php foreach ($history as $row): ?>
          <tr>
            <td class="nowrap" data-label="When"><?= Helpers::e(Helpers::fmtDateTime((string) $row['checked_in_at'])) ?></td>
            <td data-label="Event"><a href="<?= Helpers::e(Helpers::url('event.php', ['id' => (int) $row['event_id']])) ?>"><?= Helpers::e((string) $row['event_title']) ?></a><br>
              <span class="small muted mono"><?= Helpers::e((string) $row['event_code']) ?></span></td>
            <td class="small" data-label="Venue"><?= Helpers::e((string) $row['venue']) ?></td>
            <td data-label="Status"><?= (string) $row['status'] === Attendance::LATE
                ? '<span class="badge red">late</span>'
                : '<span class="badge">on time</span>' ?></td>
            <td class="small" data-label="Method"><?= Helpers::e(Attendance::methodLabel((string) $row['method'])) ?>
              <br><span class="muted"><?= (string) $row['source'] === 'self' ? 'self check-in' : 'station' ?></span></td>
            <td class="small" data-label="Recorded by"><?= Helpers::e((string) $row['scanned_by'] !== '' ? (string) $row['scanned_by'] : '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
