<?php
/**
 * checkin.php — student self check-in.
 *
 * Flow: the student scans the event poster QR (or opens the signed link) on
 * their phone, then scans their own student ID QR on this page. Both tokens
 * are verified server-side before anything is written.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$code  = Security::clean(Helpers::get('event'), 40);
$key   = Helpers::get('k', '');
$event = null;

if ($code !== '') {
    $event = Attendance::eventByCode($code);
    // The link must carry a valid signed token for that event.
    if ($event !== null && Attendance::resolveEventByToken($key) === null) {
        $event   = null;
        $badLink = true;
    }
}

$openEvents = array_values(array_filter(
    Attendance::upcomingEvents(30),
    static fn ($e) => !empty($e['self_checkin'])
        && in_array(Attendance::windowState($e)['state'], ['open', 'upcoming'], true)
));

$state = $event !== null ? Attendance::windowState($event) : ['state' => 'none', 'message' => ''];
$selfCounters = $event !== null
    ? Attendance::counters((int) $event['id'])
    : ['total' => 0, 'on_time' => 0, 'late' => 0];

$PAGE_TITLE = 'Self check-in';
$PAGE_BARE  = true;
$EXTRA_JS   = ['vendor/html5-qrcode.min.js', 'scanner.js'];

require __DIR__ . '/includes/layout/header.php';
?>

<div class="station-hero mb">
  <div class="station-hero-copy">
    <span class="station-eyebrow"><?= icon('qr') ?><span>Student self check-in</span></span>
    <h1>Student self check-in</h1>
    <p class="station-meta">
      <strong><?= Helpers::e(SCHOOL_NAME) ?></strong>
      <span><?= Helpers::e(Helpers::fmtDateTime(Helpers::now())) ?></span>
    </p>
  </div>
</div>

<?php if (isset($badLink)): ?>
  <div class="card">
    <div class="flash error"><?= icon('alert') ?><span>This check-in link is invalid or was re-issued. Please scan the current poster at the venue.</span></div>
  </div>
<?php endif; ?>

<?php if ($event === null): ?>
  <div class="card">
    <h3><?= icon('calendar') ?> Choose the event you are attending</h3>
    <?php if ($openEvents === []): ?>
      <p class="empty">No event is accepting self check-in right now. Please proceed to the officer at the entrance station.</p>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Event</th><th>Window</th><th class="right"></th></tr></thead>
        <tbody>
        <?php foreach ($openEvents as $option): ?>
          <tr>
            <td><strong><?= Helpers::e((string) $option['title']) ?></strong><br>
              <span class="small muted"><?= Helpers::e((string) $option['venue']) ?></span></td>
            <td class="small"><?= Helpers::e(Helpers::fmtWindow((string) $option['starts_at'], (string) $option['ends_at'])) ?></td>
            <td class="right">
              <a class="btn sm" href="<?= Helpers::e(Attendance::checkinUrl($option)) ?>"><?= icon('scan') ?><span>Check in</span></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php else: ?>

  <div class="station-grid">
    <section class="card station-card scan-camera" aria-labelledby="self-camera-title">
      <div class="station-card-head">
        <div class="station-title">
          <span class="station-icon" aria-hidden="true"><?= icon('camera') ?></span>
          <div>
            <h3 id="self-camera-title">Step 2 — scan your student ID</h3>
            <p class="station-card-sub">Tap <strong>Start camera</strong>, allow camera access, then point your phone at the QR code on your student ID card or digital ID.</p>
          </div>
        </div>
        <span class="camera-pill idle" id="camera-state"><span class="dot" aria-hidden="true"></span><span id="camera-state-label">Camera idle</span></span>
      </div>
      <div class="camera-controls mb">
        <button id="camera-start" class="primary" type="button" aria-describedby="camera-state-label"><?= icon('camera') ?><span>Start camera</span></button>
        <button id="camera-stop" class="ghost" type="button" disabled><?= icon('x-circle') ?><span>Stop</span></button>
        <button id="toggle-sound" class="ghost" type="button" aria-pressed="true"><?= icon('bell') ?><span>Sound on</span></button>
      </div>
      <div id="reader" class="scanner-view"
        data-scanner-mode="self"
        data-scanner-event-id="<?= (int) $event['id'] ?>"
        data-scanner-endpoint="<?= Helpers::e(Helpers::url('api/checkin.php')) ?>"
        data-scanner-stats-url="<?= Helpers::e(Helpers::url('api/stats.php')) ?>"
        data-scanner-csrf="<?= Helpers::e(Security::csrfToken()) ?>"
        data-scanner-sound="true"
        data-scanner-autostart="false"
      ></div>
      <p class="small muted mt">Ask the officer at the station to scan your ID if the camera won't work.</p>
    </section>

    <div class="station-side">
      <section class="card station-card scan-result" aria-labelledby="self-result-title">
        <div class="station-card-head">
          <div class="station-title">
            <span class="station-icon alt" aria-hidden="true"><?= icon('bell') ?></span>
            <div>
              <h3 id="self-result-title">Your result</h3>
              <p class="station-card-sub">The latest scan result appears here.</p>
            </div>
          </div>
        </div>
        <div class="verdict" id="verdict" aria-live="polite">
          <div class="stamp">Ready</div>
          <div class="meta"><?= Helpers::e($state['message']) ?></div>
        </div>

        <div class="stat-grid compact mt">
          <div class="stat"><div class="num" id="count-total"><?= (int) $selfCounters['total'] ?></div><div class="lbl">Checked in</div></div>
          <div class="stat"><div class="num" id="count-on-time"><?= (int) $selfCounters['on_time'] ?></div><div class="lbl">On time</div></div>
          <div class="stat red"><div class="num" id="count-late"><?= (int) $selfCounters['late'] ?></div><div class="lbl">Late</div></div>
        </div>
        <p class="scan-refresh small muted mt">Keep this page open until you see your name and the confirmation stamp.</p>
      </section>

      <section class="card station-card scan-log-card mt" aria-labelledby="self-event-title">
        <div class="station-card-head">
          <div class="station-title">
            <span class="station-icon" aria-hidden="true"><?= icon('calendar') ?></span>
            <div>
              <h3 id="self-event-title"><?= Helpers::e((string) $event['title']) ?></h3>
              <p class="station-card-sub">Event details for your reference.</p>
            </div>
          </div>
        </div>
        <table class="tbl">
          <tbody>
            <tr><td>Code</td><td class="mono"><?= Helpers::e((string) $event['code']) ?></td></tr>
            <tr><td>Venue</td><td><?= Helpers::e((string) $event['venue']) ?></td></tr>
            <tr><td>Window</td><td><?= Helpers::e(Helpers::fmtWindow((string) $event['starts_at'], (string) $event['ends_at'])) ?></td></tr>
            <tr><td>On time until</td><td><?= Helpers::e(Helpers::fmtDateTime(date('Y-m-d H:i:s', (int) strtotime((string) $event['starts_at']) + ((int) $event['grace_minutes'] * 60)))) ?>
              <span class="small muted">(grace <?= (int) $event['grace_minutes'] ?> min)</span></td></tr>
          </tbody>
        </table>
      </section>
    </div>
  </div>

  <div class="card mt">
    <h3><?= icon('shield') ?> Privacy note</h3>
    <p class="small muted">Only your name, student number, course and the scan time are stored for this event.
      Self check-in is a convenience: the officer at the station may still verify your identity.
      Never share a photo of your QR ID — anyone holding it could check in as you.</p>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
