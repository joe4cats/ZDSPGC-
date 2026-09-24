<?php
/**
 * qr_cards.php — a print-optimised sheet of student QR ID cards.
 *
 * Filters: course, year level, and free-text search. Rendered 2-up on A4/Letter
 * (with the browser's print dialog), each card carrying the signed QR token.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireCapability('manage_students');

$courseF = Security::clean(Helpers::get('course'), 60);
$yearF   = Security::clean(Helpers::get('year'), 20);
$search  = Security::clean(Helpers::get('q'), 60);

$where  = ["s.status = 'active'"];
$params = [];

if ($courseF !== '') {
    $where[]          = 's.course = :course';
    $params['course'] = $courseF;
}
if ($yearF !== '') {
    $where[]         = 's.year_level = :year';
    $params['year']  = $yearF;
}
if ($search !== '') {
    $where[]      = '(s.student_no LIKE :q1 OR s.full_name LIKE :q2)';
    $needle       = '%' . $search . '%';
    $params['q1'] = $needle;
    $params['q2'] = $needle;
}

$students = Database::all(
    'SELECT * FROM students s WHERE ' . implode(' AND ', $where) . ' ORDER BY s.course ASC, s.full_name ASC',
    $params
);

$PAGE_TITLE = 'QR ID cards';
$PAGE_SUB   = count($students) . ' active student(s) ready to print';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="card no-print">
  <h3><?= icon('qr') ?> Print sheet</h3>
  <form class="filters" method="get" action="<?= Helpers::e(Helpers::url('qr_cards.php')) ?>">
    <label class="field">
      <span>Course</span>
      <select name="course">
        <option value="">All courses</option>
        <?php foreach (Attendance::courses() as $option): ?>
          <option value="<?= Helpers::e($option) ?>" <?= $courseF === $option ? 'selected' : '' ?>><?= Helpers::e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>Year level</span>
      <select name="year">
        <option value="">All years</option>
        <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $option): ?>
          <option value="<?= Helpers::e($option) ?>" <?= $yearF === $option ? 'selected' : '' ?>><?= Helpers::e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>Search</span>
      <input type="search" name="q" value="<?= Helpers::e($search) ?>" placeholder="name or number">
    </label>
    <div class="row">
      <button type="submit"><?= icon('search') ?><span>Apply</span></button>
      <button type="button" data-print><?= icon('print') ?><span>Print sheet</span></button>
      <a class="btn ghost" href="<?= Helpers::e(Helpers::url('qr_cards.php')) ?>">Reset</a>
    </div>
  </form>
  <p class="hint">Tip: in the print dialog choose <strong>A4 / Letter</strong>, margins <em>Default</em>, and under
    <strong>More settings</strong> turn <strong>Headers and footers</strong> off — otherwise the browser prints the
    date, URL and page numbers on every sheet. Cards are laid out <?= 2 ?> per row and cut along the border.</p>
</div>

<?php if ($students === []): ?>
  <p class="empty no-print">No active students match those filters.</p>
<?php else:
  // One section per course (alphabetical), students alphabetical by name inside it.
  $byCourse = [];
  foreach ($students as $student) {
      $key = (string) $student['course'];
      $byCourse[$key !== '' ? $key : 'Unassigned'][] = $student;
  }
  ksort($byCourse);
?>
  <?php foreach ($byCourse as $course => $courseStudents): ?>
    <div class="course-band">
      <strong><?= Helpers::e($course) ?></strong>
      <span><?= count($courseStudents) ?> student(s)</span>
    </div>
    <div class="cards-grid">
      <?php foreach ($courseStudents as $student): ?>
        <div class="id-card">
          <div class="id-qr">
            <div class="qr" data-qr="<?= Helpers::e(Attendance::studentToken($student)) ?>"
                 data-qr-cell="3" data-qr-name="qr-id-<?= Helpers::e((string) $student['student_no']) ?>"></div>
          </div>
          <div>
            <h4><?= Helpers::e((string) $student['full_name']) ?></h4>
            <div class="id-no"><?= Helpers::e((string) $student['student_no']) ?></div>
            <div class="small muted"><?= Helpers::e((string) $student['course']) ?> · <?= Helpers::e((string) $student['year_level']) ?>
              <?= (string) $student['section'] !== '' ? ' · Sec. ' . Helpers::e((string) $student['section']) : '' ?></div>
            <div class="small mt"><strong><?= Helpers::e(SCHOOL_CAMPUS) ?></strong><br>
              <span class="muted"><?= Helpers::e(APP_SHORT) ?></span></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
