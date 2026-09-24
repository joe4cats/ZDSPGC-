<?php
/**
 * events.php — create, edit, open/close and delete events.
 *
 * Every event carries a signed QR poster token. Re-issuing ("new poster")
 * generates a fresh nonce, which voids the previously printed poster.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('manage_events');

/** Turns a datetime-local input ("2026-03-12T08:00") into "Y-m-d H:i:s". */
function zdspgc_local_dt(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    return ($dt === false || $dt->format('Y-m-d H:i:s') !== $value) ? null : $value;
}

/** Keeps event codes unique (used when the organiser leaves the code empty). */
function zdspgc_unique_event_code(string $base, int $ignoreId = 0): string
{
    $base = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', $base));
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'EVENT';
    }
    $base   = mb_substr($base, 0, 30);
    $code   = $base;
    $suffix = 2;

    while (Database::one(
        'SELECT id FROM events WHERE code = :code AND id <> :id',
        ['code' => $code, 'id' => $ignoreId]
    ) !== null) {
        $code = $base . '-' . $suffix;
        $suffix++;
    }
    return $code;
}

$editId = Helpers::getInt('edit');

$form = [
    'id'            => 0,
    'code'          => '',
    'title'         => '',
    'description'   => '',
    'venue'         => 'ZDSPGC Gymnasium',
    'starts_at'     => '',
    'ends_at'       => '',
    'grace_minutes' => DEFAULT_GRACE_MINUTES,
    'self_checkin'  => DEFAULT_SELF_CHECKIN ? 1 : 0,
    'status'        => 'open',
];

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) Helpers::post('action', 'save');

    if ($action === 'delete') {
        $id    = Helpers::postInt('id');
        $event = Attendance::eventSummary($id);
        if ($event !== null) {
            Database::run('DELETE FROM events WHERE id = :id', ['id' => $id]);
            Security::audit('EVENT_DELETE', 'Deleted ' . $event['code'] . ' (' . $event['title'] . ')', 'events');
            Helpers::flash('success', 'Event "' . $event['title'] . '" and its records were deleted.');
        }
        Helpers::redirect('events.php');
    }

    if ($action === 'toggle') {
        $id    = Helpers::postInt('id');
        $event = Attendance::eventSummary($id);
        if ($event !== null) {
            $newStatus = ($event['status'] === 'open') ? 'closed' : 'open';
            Database::run('UPDATE events SET status = :s WHERE id = :id', ['s' => $newStatus, 'id' => $id]);
            Security::audit('EVENT_STATUS', 'Set ' . $event['code'] . ' to ' . $newStatus, 'events');
            Helpers::flash('success', 'Event "' . $event['title'] . '" is now ' . $newStatus . '.');
        }
        Helpers::redirect('events.php');
    }

    if ($action === 'reissue') {
        $id    = Helpers::postInt('id');
        $event = Attendance::eventSummary($id);
        if ($event !== null) {
            Database::run('UPDATE events SET qr_nonce = :n WHERE id = :id', ['n' => Security::nonce(), 'id' => $id]);
            Security::audit('EVENT_QR_REISSUE', 'New poster QR for ' . $event['code'], 'events');
            Helpers::flash('success', 'A new poster QR was issued — old posters no longer work.');
        }
        Helpers::redirect('events.php');
    }

    $id          = Helpers::postInt('id');
    $title       = Security::clean(Helpers::post('title'), 160);
    $description = Security::clean(Helpers::post('description'), 600);
    $venue       = Security::clean(Helpers::post('venue'), 120);
    $starts      = zdspgc_local_dt(Helpers::post('starts_at'));
    $ends        = zdspgc_local_dt(Helpers::post('ends_at'));
    $grace       = max(0, min(180, Helpers::postInt('grace_minutes', DEFAULT_GRACE_MINUTES)));
    $selfCheckin = Helpers::post('self_checkin') === '1' ? 1 : 0;
    $status      = Helpers::post('status') === 'closed' ? 'closed' : 'open';
    $code        = Security::clean(Helpers::post('code'), 40);

    $errors = [];
    if ($title === '') {
        $errors[] = 'Event title is required.';
    }
    if ($starts === null || $ends === null) {
        $errors[] = 'Please give a valid start and end date/time.';
    } elseif (strtotime($ends) <= strtotime($starts)) {
        $errors[] = 'The end time must be after the start time.';
    }

    if ($errors !== []) {
        foreach ($errors as $message) {
            Helpers::flash('error', $message);
        }
        $form = [
            'id'            => $id,
            'code'          => $code,
            'title'         => $title,
            'description'   => $description,
            'venue'         => $venue,
            'starts_at'     => (string) Helpers::post('starts_at'),
            'ends_at'       => (string) Helpers::post('ends_at'),
            'grace_minutes' => $grace,
            'self_checkin'  => $selfCheckin,
            'status'        => $status,
        ];
        $editId = $id;
    } else {
        $code = $code !== '' ? $code : $title . ' ' . substr((string) $starts, 0, 4);
        $code = zdspgc_unique_event_code($code, $id);

        $row = [
            'code'          => $code,
            'title'         => $title,
            'description'   => $description,
            'venue'         => $venue,
            'starts_at'     => $starts,
            'ends_at'       => $ends,
            'grace_minutes' => $grace,
            'self_checkin'  => $selfCheckin,
            'status'        => $status,
        ];

        if ($id > 0) {
            Database::update('events', $row, 'id = :id', ['id' => $id]);
            Security::audit('EVENT_UPDATE', 'Updated event ' . $code, 'events');
            Helpers::flash('success', 'Event "' . $title . '" was updated.');
        } else {
            $row['qr_nonce']   = Security::nonce();
            $row['created_by'] = Auth::userName() ?? 'staff';
            $row['created_at'] = Helpers::now();
            $newId             = Database::insert('events', $row);
            Security::audit('EVENT_CREATE', 'Created event ' . $code . ' (id ' . $newId . ')', 'events');
            Helpers::flash('success', 'Event "' . $title . '" was created. Print its poster from the event page.');
        }
        Helpers::redirect('events.php');
    }
} elseif ($editId > 0) {
    $existing = Database::one('SELECT * FROM events WHERE id = :id', ['id' => $editId]);
    if ($existing !== null) {
        $form = [
            'id'            => (int) $existing['id'],
            'code'          => (string) $existing['code'],
            'title'         => (string) $existing['title'],
            'description'   => (string) $existing['description'],
            'venue'         => (string) $existing['venue'],
            'starts_at'     => date('Y-m-d\TH:i', (int) strtotime((string) $existing['starts_at'])),
            'ends_at'       => date('Y-m-d\TH:i', (int) strtotime((string) $existing['ends_at'])),
            'grace_minutes' => (int) $existing['grace_minutes'],
            'self_checkin'  => (int) $existing['self_checkin'],
            'status'        => (string) $existing['status'],
        ];
    }
}

$search = Security::clean(Helpers::get('q'), 60);
$events = $search === ''
    ? Attendance::eventsSummary(200)
    : Database::all(
        'SELECT * FROM events WHERE title LIKE :q1 OR code LIKE :q2 OR venue LIKE :q3 ORDER BY starts_at DESC LIMIT 100',
        ['q1' => '%' . $search . '%', 'q2' => '%' . $search . '%', 'q3' => '%' . $search . '%']
    );

$PAGE_TITLE  = 'Events';
$PAGE_ACTIVE = 'events';
$PAGE_SUB    = 'Create an event, set its check-in window and issue its QR poster.';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="grid cols-2">
  <div class="card">
    <h3><?= icon($form['id'] > 0 ? 'edit' : 'plus') ?> <?= $form['id'] > 0 ? 'Edit event' : 'Create a new event' ?></h3>
    <form method="post" action="<?= Helpers::e(Helpers::url('events.php')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

      <label class="field">
        <span>Event title *</span>
        <input type="text" name="title" maxlength="160" required value="<?= Helpers::e((string) $form['title']) ?>"
               placeholder="e.g. Foundation Day 2026 — Opening Program">
      </label>

      <div class="grid cols-2">
        <label class="field">
          <span>Event code (optional)</span>
          <input type="text" name="code" maxlength="40" value="<?= Helpers::e((string) $form['code']) ?>" placeholder="auto from title">
          <span class="hint">Short unique code shown on reports.</span>
        </label>
        <label class="field">
          <span>Venue</span>
          <input type="text" name="venue" maxlength="120" value="<?= Helpers::e((string) $form['venue']) ?>">
        </label>
      </div>

      <div class="grid cols-2">
        <label class="field">
          <span>Starts at *</span>
          <input type="datetime-local" name="starts_at" required data-default-hours="1" value="<?= Helpers::e((string) $form['starts_at']) ?>">
        </label>
        <label class="field">
          <span>Ends at *</span>
          <input type="datetime-local" name="ends_at" required data-default-hours="5" value="<?= Helpers::e((string) $form['ends_at']) ?>">
        </label>
      </div>

      <div class="grid cols-3">
        <label class="field">
          <span>Grace (minutes)</span>
          <input type="number" name="grace_minutes" min="0" max="180" value="<?= (int) $form['grace_minutes'] ?>">
          <span class="hint">Scans after this are LATE.</span>
        </label>
        <label class="field">
          <span>Status</span>
          <select name="status">
            <option value="open" <?= $form['status'] === 'open' ? 'selected' : '' ?>>Open (accepting scans)</option>
            <option value="closed" <?= $form['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
          </select>
        </label>
        <label class="field">
          <span>Self check-in</span>
          <select name="self_checkin">
            <option value="1" <?= (int) $form['self_checkin'] === 1 ? 'selected' : '' ?>>Enabled (poster QR)</option>
            <option value="0" <?= (int) $form['self_checkin'] === 0 ? 'selected' : '' ?>>Disabled (station only)</option>
          </select>
        </label>
      </div>

      <label class="field">
        <span>Description / instructions (optional)</span>
        <textarea name="description" rows="3" maxlength="600"><?= Helpers::e((string) $form['description']) ?></textarea>
      </label>

      <div class="row">
        <button type="submit"><?= icon('check') ?><span><?= $form['id'] > 0 ? 'Save changes' : 'Create event' ?></span></button>
        <?php if ($form['id'] > 0): ?>
          <a class="btn ghost" href="<?= Helpers::e(Helpers::url('events.php')) ?>"><?= icon('x-circle') ?><span>Cancel edit</span></a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <?php if ($events === []): ?>
    <p class="empty">No events found<?= $search !== '' ? ' for "' . Helpers::e($search) . '"' : '' ?>.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Event</th><th>Window</th><th class="num">In</th><th class="num">Late</th><th>State</th><th class="right">Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($events as $event):
            $id    = (int) $event['id'];
            $state = Attendance::windowState($event);
            $badge = match ($state['state']) {
                'open'     => '<span class="badge"><span class="dot"></span>Open</span>',
                'upcoming' => '<span class="badge gold">Upcoming</span>',
                'ended'    => '<span class="badge grey">Ended</span>',
                default    => '<span class="badge grey">Closed</span>',
            };
        ?>
          <tr>
            <td>
              <a href="<?= Helpers::e(Helpers::url('event.php', ['id' => $id])) ?>"><strong><?= Helpers::e((string) $event['title']) ?></strong></a><br>
              <span class="small muted mono"><?= Helpers::e((string) $event['code']) ?></span>
              <?php if (!empty($event['self_checkin'])): ?> <span class="badge blue">self check-in</span><?php endif; ?>
            </td>
            <td class="small">
              <?= Helpers::e(Helpers::fmtWindow((string) $event['starts_at'], (string) $event['ends_at'])) ?><br>
              <span class="small muted"><?= Helpers::e((string) $event['venue']) ?> · grace <?= (int) $event['grace_minutes'] ?>m</span>
            </td>
            <td class="num"><?= (int) ($event['total'] ?? 0) ?></td>
            <td class="num"><?= (int) ($event['late'] ?? 0) ?></td>
            <td><?= $badge ?></td>
            <td class="right nowrap">
              <div class="link-actions" style="justify-content:flex-end;">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('events.php', ['edit' => $id])) ?>"><?= icon('edit') ?><span>Edit</span></a>
                <a class="btn sm" href="<?= Helpers::e(Helpers::url('scan.php', ['event' => $id])) ?>"><?= icon('scan') ?><span>Scan</span></a>
                <form method="post" class="inline-form">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="sm ghost" name="action" value="toggle" type="submit">
                    <?= icon((string) $event['status'] === 'open' ? 'lock' : 'refresh') ?>
                    <span><?= (string) $event['status'] === 'open' ? 'Close' : 'Reopen' ?></span>
                  </button>
                </form>
                <form method="post" class="inline-form" data-confirm="Issue a new poster QR? Old printed posters will stop working.">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="sm ghost" name="action" value="reissue" type="submit"><?= icon('refresh') ?><span>New QR</span></button>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete this event and ALL of its attendance records? This cannot be undone.">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="sm ghost red" name="action" value="delete" type="submit"><?= icon('trash') ?><span>Delete</span></button>
                </form>
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

  <div class="card">
    <h3><?= icon('bell') ?> How the check-in window works</h3>
    <ul class="small" style="padding-left:18px; display:grid; gap:6px;">
      <li>Scans are accepted from <strong><?= (int) EARLY_CHECKIN_MINUTES ?> minutes before</strong> the start time until the end time.</li>
      <li>A scan within <strong>start + grace period</strong> is recorded as <strong>ON TIME</strong>; later scans are <strong>LATE</strong>.</li>
      <li>Each student can only be recorded <strong>once per event</strong> — a repeat scan shows the original time.</li>
      <li>Setting the status to <strong>Closed</strong> blocks every scan instantly, including self check-ins.</li>
      <li><strong>New QR</strong> re-signs the event poster; previously printed posters stop working.</li>
    </ul>
    <p class="hint mt">Server time now: <?= Helpers::e(Helpers::fmtDateTime(Helpers::now())) ?> (<?= Helpers::e(APP_TIMEZONE) ?>)</p>
  </div>
</div>

<h2 class="section-title"><?= icon('calendar') ?><span>All events (<?= count($events) ?>)</span></h2>

<div class="card">
  <form class="filters mb" method="get" action="<?= Helpers::e(Helpers::url('events.php')) ?>">
    <label class="field" style="grid-column: span 3;">
      <span>Search events</span>
      <input type="search" name="q" value="<?= Helpers::e($search) ?>" placeholder="title, code or venue">
    </label>
    <div class="row">
      <button type="submit"><?= icon('search') ?><span>Search</span></button>
      <?php if ($search !== ''): ?>
        <a class="btn ghost" href="<?= Helpers::e(Helpers::url('events.php')) ?>">Clear</a>
      <?php endif; ?>
    </div>
  </form>
