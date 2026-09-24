<?php
/**
 * scan.php — the scan station used by an officer at the door.
 *
 * Left: camera / manual entry. Right: big verdict panel, live counters and the
 * rolling log of the last scans. Works with a laptop webcam, a USB QR scanner
 * (types the token + Enter into the manual box) or a phone browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('run_scanner');

$events = Attendance::upcomingEvents(50);
$open   = array_values(array_filter($events, static fn ($e) => Attendance::windowState($e)['state'] === 'open'));

if ($open === []) {
    $open = $events;   // still let staff pick a scheduled event (the reason is shown)
}

$selectedId = Helpers::getInt('event');
$knownIds   = array_map(static fn ($e) => (int) $e['id'], $events);

if ($selectedId === 0 || !in_array($selectedId, $knownIds, true)) {
    $selectedId = $open === [] ? 0 : (int) $open[0]['id'];
}

$event = $selectedId > 0 ? Database::one('SELECT * FROM events WHERE id = :id', ['id' => $selectedId]) : null;

$counters = $event !== null
    ? Attendance::counters($selectedId)
    : ['total' => 0, 'on_time' => 0, 'late' => 0, 'last_at' => null];
$recent = $event !== null ? Attendance::recent($selectedId, 10) : [];
$state  = $event !== null
    ? Attendance::windowState($event)
    : ['state' => 'none', 'message' => 'No event selected.'];

$PAGE_TITLE = 'Scan station';
$PAGE_BARE  = true;
$EXTRA_JS   = ['vendor/html5-qrcode.min.js', 'scanner.js'];

require __DIR__ . '/includes/layout/header.php';
?>

<div class="station-hero mb">
  <div class="station-hero-copy">
    <span class="station-eyebrow"><?= icon('scan') ?><span>Live scan station</span></span>
    <h1>Scan station<?= $event !== null ? ' · ' . Helpers::e((string) $event['code']) : '' ?></h1>
    <p class="station-meta">
      <?php if ($event !== null): ?>
        <strong><?= Helpers::e((string) $event['title']) ?></strong>
        <span><?= Helpers::e(Helpers::fmtWindow((string) $event['starts_at'], (string) $event['ends_at'])) ?></span>
        <span><?= Helpers::e((string) $event['venue']) ?></span>
      <?php else: ?>
        Select an event to begin.
      <?php endif; ?>
    </p>
  </div>
  <div class="station-hero-actions">
    <?php if (count($events) > 1): ?>
      <form method="get" action="<?= Helpers::e(Helpers::url('scan.php')) ?>" class="station-event-form">
        <label class="visually-hidden" for="station-event">Select event</label>
        <select id="station-event" name="event" onchange="this.form.submit()">
          <?php foreach ($events as $option): ?>
            <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === $selectedId ? 'selected' : '' ?>>
              <?= Helpers::e((string) $option['code']) ?> — <?= Helpers::e((string) $option['title']) ?>
              (<?= Helpers::e(Attendance::windowState($option)['state']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <a class="btn ghost station-dashboard-btn" href="<?= Helpers::e(Helpers::url('index.php')) ?>"><?= icon('dashboard') ?><span>Dashboard</span></a>
  </div>
</div>

<div class="station-grid">
  <section class="card station-card scan-camera" aria-labelledby="scan-camera-title">
    <div class="station-card-head">
      <div class="station-title">
        <span class="station-icon" aria-hidden="true"><?= icon('camera') ?></span>
        <div>
          <h3 id="scan-camera-title">Camera scanner</h3>
          <p class="station-card-sub">Point a student QR at the camera for an instant station check-in.</p>
        </div>
      </div>
      <span class="camera-pill idle" id="camera-state"><span class="dot" aria-hidden="true"></span><span id="camera-state-label">Camera idle</span></span>
    </div>
    <div class="camera-controls mb">
      <button id="camera-start" class="primary" type="button"><?= icon('camera') ?><span>Start camera</span></button>
      <button id="camera-stop" class="ghost" type="button" disabled><?= icon('x-circle') ?><span>Stop</span></button>
      <button id="toggle-sound" class="ghost" type="button"><?= icon('bell') ?><span>Sound on</span></button>
    </div>
    <div id="reader" class="scanner-view"
      <?php if ($event !== null): ?>
        data-scanner-mode="station"
        data-scanner-event-id="<?= (int) $event['id'] ?>"
        data-scanner-endpoint="<?= Helpers::e(Helpers::url('api/checkin.php')) ?>"
        data-scanner-stats-url="<?= Helpers::e(Helpers::url('api/stats.php')) ?>"
        data-scanner-csrf="<?= Helpers::e(Security::csrfToken()) ?>"
        data-scanner-sound="true"
        data-scanner-autostart="false"
      <?php endif; ?>
    ></div>
    <p class="small muted mt">Point the camera at the student's QR ID. The station still works when the camera is unavailable — type the student number below.</p>

    <form id="manual-form" class="manual-form mt" autocomplete="off">
      <label class="field">
        <span><?= icon('keyboard') ?> Manual entry / USB QR scanner</span>
        <input type="text" id="manual-input" class="big" placeholder="Student number — then press Enter" autocomplete="off">
        <span class="hint">A USB scanner types the QR token and presses Enter; both formats are accepted.</span>
      </label>
      <div class="manual-actions">
        <button type="submit"><?= icon('check') ?><span>Check in</span></button>
        <label class="field station-field">
          <span>Station label</span>
          <input type="text" id="station-input" maxlength="60" value="Main Gate" autocomplete="off">
        </label>
      </div>
    </form>
  </section>

  <div class="station-side">
    <section class="card station-card scan-result" aria-labelledby="scan-result-title">
      <div class="station-card-head">
        <div class="station-title">
          <span class="station-icon alt" aria-hidden="true"><?= icon('bell') ?></span>
          <div>
            <h3 id="scan-result-title">Result</h3>
            <p class="station-card-sub">The latest scanned student appears here.</p>
          </div>
        </div>
      </div>
      <div class="verdict" id="verdict" aria-live="polite">
        <div class="stamp">Ready</div>
        <div class="meta"><?= Helpers::e($state['message']) ?></div>
      </div>

      <div class="stat-grid compact mt">
        <div class="stat"><div class="num" id="count-total"><?= (int) $counters['total'] ?></div><div class="lbl">Checked in</div></div>
        <div class="stat"><div class="num" id="count-on-time"><?= (int) $counters['on_time'] ?></div><div class="lbl">On time</div></div>
        <div class="stat red"><div class="num" id="count-late"><?= (int) $counters['late'] ?></div><div class="lbl">Late</div></div>
      </div>
      <p class="scan-refresh small muted mt">Last scan: <span id="count-last"><?= Helpers::e($counters['last_at'] === null ? '—' : (string) $counters['last_at']) ?></span>
        · counters refresh every 8 seconds</p>
    </section>

    <section class="card station-card scan-log-card mt" aria-labelledby="recent-scans-title">
      <div class="station-card-head">
        <div class="station-title">
          <span class="station-icon" aria-hidden="true"><?= icon('list') ?></span>
          <div>
            <h3 id="recent-scans-title">Recent scans</h3>
            <p class="station-card-sub">Newest scans appear first.</p>
          </div>
        </div>
      </div>
      <div class="log-list" id="scan-log">
        <?php foreach ($recent as $row): ?>
          <div class="log-item <?= (string) $row['status'] === Attendance::LATE ? 'late' : '' ?>">
            <span><strong><?= Helpers::e((string) $row['student_name']) ?></strong>
              <span class="small muted"><?= Helpers::e((string) $row['student_no']) ?> · <?= Helpers::e((string) $row['course']) ?></span></span>
            <span class="t"><?= Helpers::e(Helpers::fmtTime((string) $row['checked_in_at'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
