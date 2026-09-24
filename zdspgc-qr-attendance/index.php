<?php
/**
 * index.php — dashboard.
 *
 * A live snapshot of the whole attendance operation: how many students are on
 * record, which events are running right now, today's check-ins and the latest
 * scans, plus quick actions for the operator.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();

$stats   = Attendance::dashboardStats();
$today   = Attendance::eventsSummary(12, true);
$live    = Attendance::upcomingEvents(6);
$recent  = Attendance::recentAll(8);
$courses = Attendance::courses();

$PAGE_TITLE   = 'Dashboard';
$PAGE_ACTIVE  = 'dashboard';
$PAGE_SUB     = 'Attendance overview · ' . Helpers::e(date('l, F j, Y'));
$PAGE_ACTIONS = '';
if (Auth::can('run_scanner')) {
    $PAGE_ACTIONS .= '<a class="btn gold" href="' . Helpers::e(Helpers::url('scan.php')) . '">' . icon('scan')
        . '<span>Open scan station</span></a>';
}
if (Auth::can('manage_events')) {
    $PAGE_ACTIONS .= '<a class="btn ghost" href="' . Helpers::e(Helpers::url('events.php')) . '">' . icon('calendar')
        . '<span>Manage events</span></a>';
}

require __DIR__ . '/includes/layout/header.php';
?>

<div class="stat-grid">
  <div class="stat">
    <div class="num"><?= (int) $stats['today_total'] ?></div>
    <div class="lbl">Check-ins today</div>
  </div>
  <div class="stat gold">
    <div class="num"><?= (float) $stats['today_rate'] ?>%</div>
    <div class="lbl">Turnout today (of <?= (int) $stats['students'] ?> active students)</div>
  </div>
  <div class="stat">
    <div class="num"><?= (int) $stats['live_events'] ?></div>
    <div class="lbl">Events running now</div>
  </div>
  <div class="stat grey">
    <div class="num"><?= (int) $stats['today_events'] ?></div>
    <div class="lbl">Events scheduled today</div>
  </div>
  <div class="stat red">
    <div class="num"><?= (int) $stats['today_late'] ?></div>
    <div class="lbl">Late arrivals today</div>
  </div>
  <div class="stat grey">
    <div class="num"><?= (int) $stats['all_time'] ?></div>
    <div class="lbl">Records all time</div>
  </div>
</div>

<div class="grid cols-2 mt">
  <div class="card">
    <h3><?= icon('calendar') ?> Today's events</h3>
    <?php if ($today === []): ?>
      <p class="empty">No events scheduled for today. Add one on the
        <a href="<?= Helpers::e(Helpers::url('events.php')) ?>">Events</a> page.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr><th>Event</th><th>Window</th><th class="num">In</th><th style="width:170px;">Turnout</th><th>Status</th></tr>
          </thead>
          <tbody>
          <?php foreach ($today as $event):
              $state = Attendance::windowState($event);
              $total = (int) $event['total'];
              $rate  = Helpers::percent($total, max(1, (int) $stats['students']));
              $badge = match ($state['state']) {
                  'open'     => '<span class="badge"><span class="dot"></span>Open</span>',
                  'upcoming' => '<span class="badge gold">Upcoming</span>',
                  'ended'    => '<span class="badge grey">Ended</span>',
                  default    => '<span class="badge grey">Closed</span>',
              };
          ?>
            <tr>
              <td>
                <a href="<?= Helpers::e(Helpers::url('event.php', ['id' => (int) $event['id']])) ?>"><strong><?= Helpers::e((string) $event['title']) ?></strong></a><br>
                <span class="small muted mono"><?= Helpers::e((string) $event['code']) ?> · <?= Helpers::e((string) $event['venue']) ?></span>
              </td>
              <td class="small"><?= Helpers::e(Helpers::fmtWindow((string) $event['starts_at'], (string) $event['ends_at'])) ?></td>
              <td class="num"><?= $total ?></td>
              <td>
                <div class="bar<?= $rate < 50 ? ' gold' : '' ?>"><i style="width:<?= (float) $rate ?>%"></i></div>
                <span class="small muted"><?= (float) $rate ?>%</span>
              </td>
              <td><?= $badge ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3><?= icon('scan') ?> Latest check-ins</h3>
    <?php if ($recent === []): ?>
      <p class="empty">Nothing scanned yet. Open the scan station and try a student ID.</p>
    <?php else: ?>
      <div class="log-list">
      <?php foreach ($recent as $row): ?>
        <div class="log-item <?= ((string) $row['status'] === Attendance::LATE) ? 'late' : '' ?>">
          <span>
            <strong><?= Helpers::e((string) $row['student_name']) ?></strong>
            <span class="small muted">· <?= Helpers::e((string) $row['student_no']) ?> · <?= Helpers::e((string) $row['event_code']) ?></span>
          </span>
          <span class="t"><?= Helpers::e(Helpers::fmtTime((string) $row['checked_in_at'])) ?>
            <?= ((string) $row['status'] === Attendance::LATE) ? '<span class="badge gold">late</span>' : '<span class="badge">on time</span>' ?>
          </span>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="small muted mt">Last scan: <?= Helpers::e(Helpers::humanAgo(is_string($stats['last_checkin']) ? $stats['last_checkin'] : null)) ?></p>
  </div>
</div>

<div class="grid cols-2 mt">
  <div class="card">
    <h3><?= icon('clock') ?> Check-in windows open now / next</h3>
    <?php if ($live === []): ?>
      <p class="empty">No open events. Events close automatically once their end time passes.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Event</th><th>Starts</th><th>Grace</th><th>Self</th><th class="right">Action</th></tr></thead>
          <tbody>
          <?php foreach ($live as $event):
              $state    = Attendance::windowState($event);
              $counters = Attendance::counters((int) $event['id']);
          ?>
            <tr>
              <td><strong><?= Helpers::e((string) $event['title']) ?></strong><br><span class="small muted mono"><?= Helpers::e((string) $event['code']) ?></span></td>
              <td class="small"><?= Helpers::e(Helpers::fmtDateTime((string) $event['starts_at'])) ?><br>
                <span class="small muted"><?= $state['state'] === 'open' ? 'open now' : Helpers::e($state['message']) ?></span></td>
              <td class="small"><?= (int) $event['grace_minutes'] ?> min</td>
              <td><?= !empty($event['self_checkin']) ? '<span class="badge">on</span>' : '<span class="badge grey">off</span>' ?></td>
              <td class="right nowrap">
                <a class="btn sm" href="<?= Helpers::e(Helpers::url('scan.php', ['event' => (int) $event['id']])) ?>"><?= icon('scan') ?><span>Station</span></a>
                <span class="badge grey"><?= (int) $counters['total'] ?> in</span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3><?= icon('shield') ?> System status</h3>
    <div class="row">
      <span class="chip"><span class="dot"></span>Signed QR tokens (HMAC)</span>
      <span class="chip"><span class="dot"></span>One check-in per student per event</span>
      <span class="chip"><span class="dot"></span>Role-based access</span>
      <span class="chip"><span class="dot"></span>CSRF + prepared statements</span>
      <span class="chip"><span class="dot"></span>Audit log</span>
      <span class="chip"><span class="dot"></span>Login &amp; scan rate limiting</span>
    </div>
    <table class="tbl mt">
      <tbody>
        <tr><td>Storage driver</td><td class="mono"><?= Helpers::e(Database::driver()) ?></td></tr>
        <tr><td>Grace period (default)</td><td><?= (int) DEFAULT_GRACE_MINUTES ?> minutes</td></tr>
        <tr><td>Early check-in allowed</td><td><?= (int) EARLY_CHECKIN_MINUTES ?> minutes before start</td></tr>
        <tr><td>Course codes on record</td><td><?= Helpers::e($courses === [] ? 'none yet' : implode(', ', $courses)) ?></td></tr>
        <tr><td>Signed in as</td><td><?= Helpers::e(Auth::userName() ?? '') ?> (<?= Helpers::e(Auth::roleLabel()) ?>)</td></tr>
      </tbody>
    </table>
    <p class="small muted mt">Every scan is written to the audit log with the operator name, station label, IP address and timestamp.</p>
  </div>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
