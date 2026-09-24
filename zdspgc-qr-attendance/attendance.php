<?php
/**
 * attendance.php — the full attendance log with filters, pagination and CSV export.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('view_reports');

$filters = [
    'event_id'   => Helpers::getInt('event_id'),
    'student_id' => Helpers::getInt('student_id'),
    'status'     => Security::clean(Helpers::get('status'), 20),
    'method'     => Security::clean(Helpers::get('method'), 20),
    'course'     => Security::clean(Helpers::get('course'), 60),
    'date_from'  => Security::clean(Helpers::get('date_from'), 10),
    'date_to'    => Security::clean(Helpers::get('date_to'), 10),
    'q'          => Security::clean(Helpers::get('q'), 60),
];

$page    = Helpers::page();
$perPage = 25;

$total   = Attendance::countRecords($filters);
$pages   = max(1, (int) ceil($total / $perPage));
$page    = min($page, $pages);
$records = Attendance::listRecords($filters, $perPage, ($page - 1) * $perPage);

$events      = Database::all('SELECT id, code, title, starts_at FROM events ORDER BY starts_at DESC LIMIT 100');
$exportQuery = array_merge(array_filter($filters, static fn ($v) => $v !== '' && $v !== 0), ['type' => 'attendance']);

$PAGE_TITLE   = 'Attendance records';
$PAGE_ACTIVE  = 'records';
$PAGE_SUB     = $total . ' record(s) match the current filters';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('export.php', $exportQuery)) . '">'
    . icon('download') . '<span>Export these rows (CSV)</span></a>';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="card no-print">
  <h3><?= icon('search') ?> Filters</h3>
  <form class="filters" method="get" action="<?= Helpers::e(Helpers::url('attendance.php')) ?>">
    <label class="field">
      <span>Event</span>
      <select name="event_id">
        <option value="">All events</option>
        <?php foreach ($events as $event): ?>
          <option value="<?= (int) $event['id'] ?>" <?= (int) $filters['event_id'] === (int) $event['id'] ? 'selected' : '' ?>>
            <?= Helpers::e((string) $event['code']) ?> — <?= Helpers::e((string) $event['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>Course</span>
      <select name="course">
        <option value="">All courses</option>
        <?php foreach (Attendance::courses() as $option): ?>
          <option value="<?= Helpers::e($option) ?>" <?= $filters['course'] === $option ? 'selected' : '' ?>><?= Helpers::e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>Status</span>
      <select name="status">
        <option value="">Any</option>
        <option value="on_time" <?= $filters['status'] === 'on_time' ? 'selected' : '' ?>>On time</option>
        <option value="late" <?= $filters['status'] === 'late' ? 'selected' : '' ?>>Late</option>
      </select>
    </label>
    <label class="field">
      <span>Method</span>
      <select name="method">
        <option value="">Any</option>
        <option value="qr" <?= $filters['method'] === 'qr' ? 'selected' : '' ?>>QR scan</option>
        <option value="manual" <?= $filters['method'] === 'manual' ? 'selected' : '' ?>>Manual entry</option>
      </select>
    </label>
    <label class="field">
      <span>From date</span>
      <input type="date" name="date_from" value="<?= Helpers::e((string) $filters['date_from']) ?>">
    </label>
    <label class="field">
      <span>To date</span>
      <input type="date" name="date_to" value="<?= Helpers::e((string) $filters['date_to']) ?>">
    </label>
    <label class="field">
      <span>Search</span>
      <input type="search" name="q" value="<?= Helpers::e((string) $filters['q']) ?>" placeholder="name, number, event">
    </label>
    <?php if ((int) $filters['student_id'] > 0): ?>
      <input type="hidden" name="student_id" value="<?= (int) $filters['student_id'] ?>">
    <?php endif; ?>
    <div class="row">
      <button type="submit"><?= icon('search') ?><span>Apply</span></button>
      <a class="btn ghost" href="<?= Helpers::e(Helpers::url('attendance.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="card mt">
  <div class="row between mb">
    <h3 style="margin:0;"><?= icon('list') ?> Records</h3>
    <span class="badge grey"><?= (int) $total ?> total · page <?= (int) $page ?> of <?= (int) $pages ?></span>
  </div>

  <?php if ($records === []): ?>
    <p class="empty">No attendance records match those filters.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl responsive">
        <thead>
          <tr><th>When</th><th>Student</th><th>Course</th><th>Event</th><th>Status</th><th>Method</th><th>Station / operator</th><th>IP</th></tr>
        </thead>
        <tbody>
        <?php foreach ($records as $row): ?>
          <tr>
            <td class="nowrap" data-label="When"><?= Helpers::e(Helpers::fmtDateTime((string) $row['checked_in_at'])) ?></td>
            <td data-label="Student">
              <a href="<?= Helpers::e(Helpers::url('student.php', ['id' => (int) $row['student_id']])) ?>"><strong><?= Helpers::e((string) $row['student_name']) ?></strong></a><br>
              <span class="small muted mono"><?= Helpers::e((string) $row['student_no']) ?></span>
            </td>
            <td class="small" data-label="Course"><?= Helpers::e((string) $row['course']) ?><br>
              <span class="muted"><?= Helpers::e((string) $row['year_level']) ?> <?= Helpers::e((string) $row['section']) ?></span></td>
            <td class="small" data-label="Event">
              <a href="<?= Helpers::e(Helpers::url('event.php', ['id' => (int) $row['event_id']])) ?>"><?= Helpers::e((string) $row['event_code']) ?></a><br>
              <span class="muted"><?= Helpers::e((string) $row['event_title']) ?></span>
            </td>
            <td data-label="Status"><?= (string) $row['status'] === Attendance::LATE
                ? '<span class="badge red">late</span>'
                : '<span class="badge">on time</span>' ?></td>
            <td class="small" data-label="Method"><?= Helpers::e(Attendance::methodLabel((string) $row['method'])) ?><br>
              <span class="muted"><?= (string) $row['source'] === 'self' ? 'student device' : 'station' ?></span></td>
            <td class="small" data-label="Station / operator"><?= Helpers::e((string) $row['station'] !== '' ? (string) $row['station'] : '—') ?><br>
              <span class="muted"><?= Helpers::e((string) $row['scanned_by'] !== '' ? (string) $row['scanned_by'] : '—') ?></span></td>
            <td class="small mono" data-label="IP"><?= Helpers::e((string) ($row['ip'] !== '' ? $row['ip'] : '—')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="pager">
        <?php
        $baseQuery = array_merge(array_filter([
            'event_id'   => $filters['event_id'] ?: '',
            'student_id' => $filters['student_id'] ?: '',
            'status'     => $filters['status'],
            'method'     => $filters['method'],
            'course'     => $filters['course'],
            'date_from'  => $filters['date_from'],
            'date_to'    => $filters['date_to'],
            'q'          => $filters['q'],
        ], static fn ($v) => $v !== '' && $v !== null));
        for ($p = 1; $p <= $pages; $p++):
        ?>
          <a class="btn sm <?= $p === $page ? '' : 'ghost' ?>" href="<?= Helpers::e(Helpers::url('attendance.php', $baseQuery + ['page' => $p])) ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
