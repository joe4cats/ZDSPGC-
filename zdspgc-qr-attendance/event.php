<?php
/**
 * event.php — one event: live numbers, poster QR, attendance list and walk-in.
 *
 * Actions here (all CSRF-protected and RBAC-checked):
 *   • manual / walk-in check-in by student number (staff)
 *   • undo a check-in (with an audit entry)
 *   • re-issue the poster QR
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('view_reports');

$id    = Helpers::getInt('id');
$event = Database::one('SELECT * FROM events WHERE id = :id', ['id' => $id]);

if ($event === null) {
    Helpers::flash('error', 'That event does not exist.');
    Helpers::redirect('events.php');
}

$canManage = Auth::can('manage_events');

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) Helpers::post('action', 'walkin');

    if ($action === 'walkin') {
        Auth::requireCapability('run_scanner');
        $studentNo = Security::clean(Helpers::post('student_no'), 30);
        $remark    = Security::clean(Helpers::post('remark'), 200);
        $student   = Attendance::studentByNumber($studentNo);

        if ($student === null) {
            Helpers::flash('error', 'No student found with number "' . $studentNo . '".');
        } else {
            $result = Attendance::checkIn($event, $student, 'manual', 'station', 'Front desk (walk-in)', Auth::user(), $remark);
            Helpers::flash(
                $result['ok'] ? 'success' : ($result['code'] === 'duplicate' ? 'warning' : 'error'),
                $student['full_name'] . ' — ' . $result['message']
            );
        }
        Helpers::redirect('event.php?id=' . $id);
    }

    if ($action === 'undo') {
        Auth::requireCapability('manage_events');
        $recordId = Helpers::postInt('record_id');
        $record   = Database::one(
            'SELECT a.*, s.full_name AS student_name, s.student_no
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             WHERE a.id = :id AND a.event_id = :event',
            ['id' => $recordId, 'event' => $id]
        );
        if ($record !== null) {
            Database::run('DELETE FROM attendance WHERE id = :id', ['id' => $recordId]);
            Security::audit('CHECKIN_UNDO', 'Removed ' . $record['student_name'] . ' (' . $record['student_no'] . ') from ' . $event['code'], 'attendance');
            Helpers::flash('success', 'Removed ' . $record['student_name'] . ' from this event.');
        }
        Helpers::redirect('event.php?id=' . $id);
    }

    if ($action === 'reissue' && $canManage) {
        Database::run('UPDATE events SET qr_nonce = :n WHERE id = :id', ['n' => Security::nonce(), 'id' => $id]);
        Security::audit('EVENT_QR_REISSUE', 'New poster QR for ' . $event['code'], 'events');
        Helpers::flash('success', 'A new poster QR was issued — the old link and printouts stopped working.');
        Helpers::redirect('event.php?id=' . $id);
    }
}

$summary   = Attendance::eventSummary($id) ?? [];
$counters  = $summary['counters'] ?? Attendance::counters($id);
$state     = Attendance::windowState($event);
$records   = Attendance::listRecords(['event_id' => $id], 500);
$breakdown = Attendance::courseBreakdown($id);
$absent    = Attendance::absenteeCount($id);
$posterUrl = Attendance::checkinUrl($event);

$PAGE_TITLE  = (string) $event['title'];
$PAGE_ACTIVE = 'events';
$PAGE_SUB    = '<span class="mono">' . Helpers::e((string) $event['code']) . '</span> · '
    . Helpers::e(Helpers::fmtWindow((string) $event['starts_at'], (string) $event['ends_at']))
    . ' · ' . Helpers::e((string) $event['venue']);

$PAGE_ACTIONS = Auth::can('run_scanner')
    ? '<a class="btn" href="' . Helpers::e(Helpers::url('scan.php', ['event' => $id])) . '">' . icon('scan') . '<span>Open scan station</span></a>'
    : '';
$PAGE_ACTIONS .= '<a class="btn ghost" href="' . Helpers::e(Helpers::url('export.php', ['type' => 'attendance', 'event_id' => $id])) . '">'
    . icon('download') . '<span>Export CSV</span></a>';
$PAGE_ACTIONS .= '<button class="btn ghost" data-print>' . icon('print') . '<span>Print poster</span></button>';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="stat-grid">
  <div class="stat">
    <div class="num"><?= (int) $counters['total'] ?></div>
    <div class="lbl">Checked in</div>
  </div>
  <div class="stat">
    <div class="num"><?= (int) $counters['on_time'] ?></div>
    <div class="lbl">On time</div>
  </div>
  <div class="stat red">
    <div class="num"><?= (int) $counters['late'] ?></div>
    <div class="lbl">Late</div>
  </div>
  <div class="stat grey">
    <div class="num"><?= (int) $absent ?></div>
    <div class="lbl">No record yet</div>
  </div>
  <div class="stat">
    <div class="num"><?= (float) ($summary['rate'] ?? 0) ?>%</div>
    <div class="lbl">Turnout of <?= (int) ($summary['registered'] ?? 0) ?> active students</div>
  </div>
  <div class="stat grey">
    <div class="num"><?= (int) $counters['qr'] ?> / <?= (int) $counters['manual'] ?></div>
    <div class="lbl">QR scans / manual entries</div>
  </div>
</div>

<div class="grid cols-2 mt">
  <div class="card">
    <h3><?= icon('calendar') ?> Event details</h3>
    <table class="tbl">
      <tbody>
        <tr><td>Code</td><td class="mono"><?= Helpers::e((string) $event['code']) ?></td></tr>
        <tr><td>Window</td><td><?= Helpers::e(Helpers::fmtWindow((string) $event['starts_at'], (string) $event['ends_at'])) ?></td></tr>
        <tr><td>Grace period</td><td><?= (int) $event['grace_minutes'] ?> minutes after start</td></tr>
        <tr><td>Status</td><td>
          <?= $state['state'] === 'open'
              ? '<span class="badge"><span class="dot"></span>Open</span>'
              : '<span class="badge grey">' . Helpers::e(ucfirst($state['state'])) . '</span>' ?>
          <span class="small muted"><?= Helpers::e($state['message']) ?></span>
        </td></tr>
        <tr><td>Self check-in</td><td><?= !empty($event['self_checkin']) ? '<span class="badge">enabled</span>' : '<span class="badge grey">disabled</span>' ?></td></tr>
        <tr><td>Created by</td><td><?= Helpers::e((string) $event['created_by']) ?> · <?= Helpers::e(Helpers::fmtDateTime((string) $event['created_at'])) ?></td></tr>
        <tr><td>Last scan</td><td><?= Helpers::e($counters['last_at'] === null ? '—' : Helpers::fmtDateTime($counters['last_at'])) ?></td></tr>
      </tbody>
    </table>
    <?php if ((string) $event['description'] !== ''): ?>
      <p class="mt small"><?= nl2br(Helpers::e((string) $event['description'])) ?></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3><?= icon('qr') ?> Poster &amp; self check-in link</h3>
    <div class="row" style="align-items:flex-start; gap:20px;">
      <div class="qr card-qr" data-qr="<?= Helpers::e($posterUrl) ?>"
           data-qr-name="poster-<?= Helpers::e((string) $event['code']) ?>"
           data-qr-label="<?= Helpers::e((string) $event['title']) ?>" data-qr-cell="5">
        <div class="qr-tools">
          <button class="sm ghost" type="button" onclick="ZDSPGCQr.print(this)"><?= icon('print') ?><span>Print</span></button>
          <button class="sm ghost" type="button" onclick="ZDSPGCQr.download(this)"><?= icon('download') ?><span>PNG</span></button>
        </div>
      </div>
      <div class="stack" style="flex:1; min-width:230px;">
        <p class="small muted">Students who scan this poster land on the self check-in page and then scan their own student ID.
          The link is signed — a poster copied from another event will not work.</p>
        <label class="field">
          <span>Public link</span>
          <input type="text" class="mono" readonly value="<?= Helpers::e($posterUrl) ?>" onclick="this.select()">
        </label>
        <div class="row">
          <button class="sm ghost" type="button" data-copy="<?= Helpers::e($posterUrl) ?>"><?= icon('link') ?><span>Copy link</span></button>
          <?php if (!empty($event['self_checkin'])): ?>
            <a class="btn sm" href="<?= Helpers::e($posterUrl) ?>" target="_blank" rel="noopener"><?= icon('scan') ?><span>Open self check-in</span></a>
          <?php endif; ?>
        </div>
        <?php if ($canManage): ?>
          <form method="post" class="inline-form" data-confirm="Issue a new poster QR? Old printed posters and this link will stop working.">
            <?= Security::csrfField() ?>
            <button class="sm ghost red" name="action" value="reissue" type="submit"><?= icon('refresh') ?><span>Re-issue poster QR</span></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="grid cols-2 mt">
  <div class="card">
    <h3><?= icon('keyboard') ?> Walk-in check-in (manual entry)</h3>
    <?php if (Auth::can('run_scanner')): ?>
      <form method="post" action="<?= Helpers::e(Helpers::url('event.php', ['id' => $id])) ?>">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="walkin">
        <label class="field">
          <span>Student number</span>
          <input type="text" name="student_no" class="big" required placeholder="2024-00308" autocomplete="off">
          <span class="hint">Use this when a student's phone/ID cannot be scanned. It is logged as a manual entry with your name.</span>
        </label>
        <label class="field">
          <span>Reason / remark (optional)</span>
          <input type="text" name="remark" maxlength="200" placeholder="e.g. ID not yet claimed">
        </label>
        <button type="submit"><?= icon('check') ?><span>Record check-in</span></button>
      </form>
    <?php else: ?>
      <p class="muted">Your role is not allowed to record manual check-ins.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3><?= icon('chart') ?> Attendance by course</h3>
    <?php if ($breakdown === []): ?>
      <p class="empty">No active students on record yet.</p>
    <?php else: ?>
      <div class="stack">
        <?php foreach ($breakdown as $row):
            $registered = (int) $row['registered'];
            $present    = (int) $row['present'];
            $rate       = Helpers::percent($present, $registered);
        ?>
          <div class="bar-row">
            <strong><?= Helpers::e((string) $row['course']) ?></strong>
            <div class="bar<?= $rate < 50 ? ' low' : '' ?>"><i style="width:<?= (float) $rate ?>%"></i></div>
            <span class="small muted"><?= $present ?>/<?= $registered ?> · <?= (float) $rate ?>%</span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mt small">
        <a href="<?= Helpers::e(Helpers::url('reports.php', ['event' => $id, 'view' => 'absent'])) ?>">
          <?= icon('list') ?> View the <?= (int) $absent ?> students with no record for this event
        </a>
      </p>
    <?php endif; ?>
  </div>
</div>

<h2 class="section-title"><?= icon('list') ?><span>Attendance records (<?= count($records) ?>)</span></h2>

<div class="card">
  <div class="row between mb">
    <label class="field" style="flex:1; min-width:240px; margin:0;">
      <span>Filter this list</span>
      <input type="search" data-filter-table="#records-table" data-filter-count="#records-count" placeholder="type a name, number or course">
    </label>
    <span class="badge grey" id="records-count"><?= count($records) ?> shown</span>
  </div>

  <?php if ($records === []): ?>
    <p class="empty">Nobody has been recorded for this event yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl responsive" id="records-table">
        <thead>
          <tr>
            <th>Time</th><th>Student</th><th>Course</th><th>Status</th><th>Method</th><th>Station / operator</th><th>Remark</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $row): ?>
          <tr>
            <td class="nowrap" data-label="Time"><?= Helpers::e(Helpers::fmtTime((string) $row['checked_in_at'])) ?><br>
              <span class="small muted"><?= Helpers::e(Helpers::humanAgo((string) $row['checked_in_at'])) ?></span></td>
            <td data-label="Student"><a href="<?= Helpers::e(Helpers::url('student.php', ['id' => (int) $row['student_id']])) ?>"><strong><?= Helpers::e((string) $row['student_name']) ?></strong></a>
              <br><span class="small muted mono"><?= Helpers::e((string) $row['student_no']) ?></span></td>
            <td class="small" data-label="Course"><?= Helpers::e((string) $row['course']) ?><br>
              <span class="muted"><?= Helpers::e((string) $row['year_level']) ?> <?= Helpers::e((string) $row['section']) ?></span></td>
            <td data-label="Status"><?= (string) $row['status'] === Attendance::LATE
                ? '<span class="badge red">late</span>'
                : '<span class="badge">on time</span>' ?></td>
            <td class="small" data-label="Method"><?= Helpers::e(Attendance::methodLabel((string) $row['method'])) ?><br>
              <span class="muted"><?= (string) $row['source'] === 'self' ? 'student device' : 'station' ?></span></td>
            <td class="small" data-label="Station / operator"><?= Helpers::e((string) $row['station'] !== '' ? (string) $row['station'] : '—') ?><br>
              <span class="muted"><?= Helpers::e((string) $row['scanned_by'] !== '' ? (string) $row['scanned_by'] : '—') ?></span></td>
            <td class="small" data-label="Remark"><?= Helpers::e((string) $row['remark'] !== '' ? (string) $row['remark'] : '—') ?></td>
            <td class="right" data-label="">
              <?php if ($canManage): ?>
                <form method="post" class="inline-form" data-confirm="Remove this check-in from the event?">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="undo">
                  <input type="hidden" name="record_id" value="<?= (int) $row['id'] ?>">
                  <button class="sm ghost red" type="submit" title="Undo check-in"><?= icon('trash') ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
