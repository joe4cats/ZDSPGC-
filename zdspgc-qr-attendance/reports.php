<?php
/**
 * reports.php — turnout reports per event, per course and the absentee list.
 *
 * Every table here can be exported as CSV (export.php?type=...) for submission
 * to the registrar or for the event's after-activity report.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('view_reports');

$eventId   = Helpers::getInt('event');
$view      = Security::clean(Helpers::get('view'), 20);
$courseF   = Security::clean(Helpers::get('course'), 60);
$onlyToday = Helpers::get('range') === 'today';

$event = $eventId > 0 ? Attendance::eventSummary($eventId) : null;
if ($eventId > 0 && $event === null) {
    Helpers::flash('error', 'That event does not exist.');
    Helpers::redirect('reports.php');
}

$PAGE_TITLE  = 'Reports';
$PAGE_ACTIVE = 'reports';
$PAGE_SUB    = 'Turnout by event, by course and the students who are still missing.';

if ($event !== null && $view === 'absent') {
    /* ---------------- absentee list for one event ---------------- */
    $absentees = Attendance::absentees($eventId, $courseF === '' ? null : $courseF, 500);
    $absent    = Attendance::absenteeCount($eventId, $courseF === '' ? null : $courseF);

    $PAGE_TITLE = 'Absentees · ' . $event['code'];
    $PAGE_ACTIONS = '<a class="btn" href="' . Helpers::e(Helpers::url('event.php', ['id' => $eventId])) . '">'
        . icon('calendar') . '<span>Back to event</span></a>'
        . '<a class="btn ghost" href="' . Helpers::e(Helpers::url('export.php', ['type' => 'absentees', 'event_id' => $eventId, 'course' => $courseF])) . '">'
        . icon('download') . '<span>Export CSV</span></a>';

    require __DIR__ . '/includes/layout/header.php';
    ?>
    <div class="card">
      <div class="row between">
        <div>
          <h3 style="margin:0;"><?= icon('alert') ?> No attendance record — <?= (int) $absent ?> student(s)</h3>
          <p class="small muted"><?= Helpers::e((string) $event['title']) ?> ·
            <?= Helpers::e(Helpers::fmtWindow((string) $event['starts_at'], (string) $event['ends_at'])) ?></p>
        </div>
        <form class="row" method="get" action="<?= Helpers::e(Helpers::url('reports.php')) ?>">
          <input type="hidden" name="event" value="<?= (int) $eventId ?>">
          <input type="hidden" name="view" value="absent">
          <select name="course" onchange="this.form.submit()">
            <option value="">All courses</option>
            <?php foreach (Attendance::courses() as $option): ?>
              <option value="<?= Helpers::e($option) ?>" <?= $courseF === $option ? 'selected' : '' ?>><?= Helpers::e($option) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if ($absentees === []): ?>
        <p class="empty">Everyone on record has been checked in for this event. 🎉</p>
      <?php else: ?>
        <div class="table-wrap mt">
          <table class="tbl">
            <thead><tr><th>Student</th><th>Course</th><th>Year / section</th><th>Contact</th><th class="right">Action</th></tr></thead>
            <tbody>
            <?php foreach ($absentees as $student): ?>
              <tr>
                <td><strong><?= Helpers::e((string) $student['full_name']) ?></strong><br>
                  <span class="small muted mono"><?= Helpers::e((string) $student['student_no']) ?></span></td>
                <td><?= Helpers::e((string) $student['course']) ?></td>
                <td class="small"><?= Helpers::e((string) $student['year_level']) ?> <?= Helpers::e((string) $student['section']) ?></td>
                <td class="small"><?= Helpers::e((string) $student['contact']) ?><br>
                  <span class="muted"><?= Helpers::e((string) $student['email']) ?></span></td>
                <td class="right">
                  <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('student.php', ['id' => (int) $student['id']])) ?>"><?= icon('user') ?><span>Profile</span></a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/layout/footer.php';
    return;
}

/* ---------------- per-event turnout summary ---------------- */
$rows      = Attendance::eventsSummary(200, $onlyToday);
$totals    = ['events' => 0, 'records' => 0, 'on_time' => 0, 'late' => 0];
$active    = Attendance::activeStudentCount();

foreach ($rows as $row) {
    $totals['events']++;
    $totals['records'] += (int) $row['total'];
    $totals['on_time'] += (int) $row['on_time'];
    $totals['late']    += (int) $row['late'];
}

$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('export.php', ['type' => 'summary'])) . '">'
    . icon('download') . '<span>Export summary (CSV)</span></a>';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="stat-grid">
  <div class="stat">
    <div class="num"><?= (int) $totals['events'] ?></div>
    <div class="lbl">Events in this report</div>
  </div>
  <div class="stat">
    <div class="num"><?= (int) $totals['records'] ?></div>
    <div class="lbl">Check-in records</div>
  </div>
  <div class="stat">
    <div class="num"><?= (int) $totals['on_time'] ?></div>
    <div class="lbl">On time</div>
  </div>
  <div class="stat red">
    <div class="num"><?= (int) $totals['late'] ?></div>
    <div class="lbl">Late</div>
  </div>
  <div class="stat gold">
    <div class="num"><?= Helpers::percent((int) $totals['records'], max(1, (int) $totals['events'] * $active)) ?>%</div>
    <div class="lbl">Average turnout (of <?= (int) $active ?> active students)</div>
  </div>
  <div class="stat grey">
    <div class="num"><?= Helpers::percent((int) $totals['on_time'], max(1, (int) $totals['records'])) ?>%</div>
    <div class="lbl">Punctuality</div>
  </div>
</div>

<div class="card mt no-print">
  <div class="row between">
    <div class="row">
      <a class="btn sm <?= $onlyToday ? 'ghost' : '' ?>" href="<?= Helpers::e(Helpers::url('reports.php')) ?>">All events</a>
      <a class="btn sm <?= $onlyToday ? '' : 'ghost' ?>" href="<?= Helpers::e(Helpers::url('reports.php', ['range' => 'today'])) ?>">Today only</a>
    </div>
    <span class="small muted">Rate = checked-in students ÷ active students on record (<?= (int) $active ?>)</span>
  </div>
</div>

<div class="card mt">
  <h3><?= icon('chart') ?> Turnout per event</h3>
  <?php if ($rows === []): ?>
    <p class="empty">No events to report on<?= $onlyToday ? ' for today' : '' ?>.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Event</th><th>Window</th><th class="num">Present</th><th class="num">On time</th>
            <th class="num">Late</th><th style="width:190px;">Turnout</th><th class="right">Details</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $id    = (int) $row['id'];
            $rate  = Helpers::percent((int) $row['total'], max(1, $active));
            $absent = max(0, $active - (int) $row['total']);
            $state = Attendance::windowState($row);
        ?>
          <tr>
            <td>
              <a href="<?= Helpers::e(Helpers::url('event.php', ['id' => $id])) ?>"><strong><?= Helpers::e((string) $row['title']) ?></strong></a><br>
              <span class="small muted mono"><?= Helpers::e((string) $row['code']) ?></span>
              <span class="badge <?= $state['state'] === 'open' ? '' : 'grey' ?>"><?= Helpers::e($state['state']) ?></span>
            </td>
            <td class="small"><?= Helpers::e(Helpers::fmtWindow((string) $row['starts_at'], (string) $row['ends_at'])) ?><br>
              <span class="muted"><?= Helpers::e((string) $row['venue']) ?></span></td>
            <td class="num"><?= (int) $row['total'] ?></td>
            <td class="num"><?= (int) $row['on_time'] ?></td>
            <td class="num"><?= (int) $row['late'] ?></td>
            <td>
              <div class="bar<?= $rate < 50 ? ' gold' : '' ?>"><i style="width:<?= (float) $rate ?>%"></i></div>
              <span class="small muted"><?= (float) $rate ?>% · <?= (int) $absent ?> with no record</span>
            </td>
            <td class="right nowrap">
              <div class="link-actions" style="justify-content:flex-end;">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('reports.php', ['event' => $id, 'view' => 'absent'])) ?>"><?= icon('list') ?><span>Absentees</span></a>
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('export.php', ['type' => 'attendance', 'event_id' => $id])) ?>"><?= icon('download') ?><span>CSV</span></a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
