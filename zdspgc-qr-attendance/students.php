<?php
/**
 * students.php — the student roster: search, add, edit, deactivate, issue QR IDs,
 * plus CSV import for the whole campus roster (per-row validation; student
 * numbers already on file are skipped unless the officer asks to update them).
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
$SECTIONS = ['A', 'B', 'C', 'C1', 'C2', 'C3'];

/* ---- CSV import helpers (used by the "Import CSV" button) ---------------- */

/**
 * Column names the importer understands, keyed by internal field. Matching
 * ignores case, spaces, underscores and dashes, so "Student Number",
 * "student_no" and "StudentID" all resolve to the same column.
 *
 * @return array<string,array<int,string>>
 */
function import_column_aliases(): array
{
    return [
        'student_no'  => ['studentno', 'studentid', 'studentnumber', 'idnumber', 'lrn'],
        'full_name'   => ['fullname', 'name', 'studentname'],
        'first_name'  => ['firstname', 'givenname'],
        'middle_name' => ['middlename', 'middleinitial', 'mi'],
        'last_name'   => ['lastname', 'surname', 'familyname'],
        'course'      => ['course', 'program', 'degree'],
        'year_level'  => ['yearlevel', 'year', 'level'],
        'section'     => ['section', 'block'],
        'email'       => ['email', 'emailaddress'],
        'contact'     => ['contact', 'contactnumber', 'phone', 'mobilenumber', 'mobile', 'cp'],
        'status'      => ['status'],
    ];
}

/** "Student Number" -> "studentnumber" */
function import_header_key(string $header): string
{
    return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));
}

/**
 * @param array<int,string> $header
 * @return array<string,int> field name => column index
 */
function import_map_columns(array $header): array
{
    $columns = [];
    foreach (array_values($header) as $i => $cell) {
        $key = import_header_key($cell);
        if ($key !== '' && !isset($columns[$key])) {
            $columns[$key] = $i;
        }
    }

    $map = [];
    foreach (import_column_aliases() as $field => $aliases) {
        foreach ($aliases as $alias) {
            if (isset($columns[$alias])) {
                $map[$field] = $columns[$alias];
                break;
            }
        }
    }
    return $map;
}

/** @param array<int,string> $row */
function import_cell(array $row, array $map, string $field): string
{
    $index = $map[$field] ?? null;
    return $index === null || !isset($row[$index]) ? '' : trim((string) $row[$index]);
}

/**
 * Builds the campus name format "Surname, First M." — either from a single
 * full-name column or from split first / middle / last name columns.
 */
function import_full_name(string $fullName, string $first, string $middle, string $last): string
{
    $fullName = trim($fullName);
    if ($fullName !== '') {
        return Security::clean($fullName, 120);
    }
    $first  = trim($first);
    $middle = trim($middle);
    $last   = trim($last);
    if ($first === '' && $last === '') {
        return '';
    }
    $name = ($last !== '' && $first !== '') ? $last . ', ' . $first : ($last !== '' ? $last : $first);
    if ($middle !== '') {
        $name .= ' ' . mb_strtoupper(mb_substr($middle, 0, 1)) . '.';
    }
    return Security::clean($name, 120);
}

/** "1", "1st", "Year 2", "first year" ... -> "1st Year" ... "4th Year". */
function import_year_level(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $compact  = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($raw));
    $byNumber = ['1' => '1st Year', '2' => '2nd Year', '3' => '3rd Year', '4' => '4th Year'];
    $byWord   = ['first' => '1st Year', 'second' => '2nd Year', 'third' => '3rd Year', 'fourth' => '4th Year'];

    if (preg_match('/^[1-4](st|nd|rd|th)?(year|yr)?$/', $compact) === 1) {
        return $byNumber[$compact[0]];
    }
    if (preg_match('/^(year|yr|level)([1-4])$/', $compact, $m) === 1) {
        return $byNumber[$m[2]];
    }
    foreach ($byWord as $word => $canon) {
        if (preg_match('/^' . $word . '(year|yr)?$/', $compact) === 1) {
            return $canon;
        }
    }
    return Security::clean($raw, 20);
}

/**
 * Student numbers: letters and digits plus . _ - / space (e.g. 2026-001003).
 * Returns '' when the value is missing or uses characters that cannot be one.
 */
function import_student_no(string $raw): string
{
    $no = Security::clean($raw, 30);
    if ($no === '' || preg_match('#^[A-Za-z0-9][A-Za-z0-9 ._/-]*$#', $no) !== 1) {
        return '';
    }
    return $no;
}

/**
 * Imports the rows of an uploaded CSV (header already mapped) inside one
 * transaction — either every valid row lands or nothing does. Student numbers
 * already on file are counted as duplicates and skipped unless $updateExisting.
 *
 * @param resource $handle
 * @param array<string,int> $map field name => column index
 * @return array{imported:int, updated:int, duplicates:int, problems:array<int,string>}
 */
function import_run($handle, array $map, bool $updateExisting): array
{
    /** @var array<string,int> $existing lowercased student number => id */
    $existing = [];
    foreach (Database::all('SELECT id, student_no FROM students') as $row) {
        $existing[mb_strtolower((string) $row['student_no'])] = (int) $row['id'];
    }

    $imported   = 0;
    $updated    = 0;
    $duplicates = 0;
    $problems   = [];
    $seen       = [];
    $rowNo      = 1;   // the header row is row 1
    $maxRows    = 10000;
    $pdo        = Database::conn();

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rowNo++;
            if ($rowNo > $maxRows) {
                $problems[] = 'the file has more than ' . $maxRows . ' rows - the rest were skipped';
                break;
            }
            $cells = array_map(static fn ($cell): string => (string) $cell, is_array($row) ? $row : []);
            if (trim(implode('', $cells)) === '') {
                continue;
            }

            $studentNo = import_student_no(import_cell($cells, $map, 'student_no'));
            $fullName  = import_full_name(
                import_cell($cells, $map, 'full_name'),
                import_cell($cells, $map, 'first_name'),
                import_cell($cells, $map, 'middle_name'),
                import_cell($cells, $map, 'last_name')
            );
            $course  = Security::clean(import_cell($cells, $map, 'course'), 60);
            $year    = import_year_level(import_cell($cells, $map, 'year_level'));
            $section = Security::clean(import_cell($cells, $map, 'section'), 40);
            $email   = Security::clean(import_cell($cells, $map, 'email'), 120);
            $contact = Security::clean(import_cell($cells, $map, 'contact'), 30);
            $status  = in_array(mb_strtolower(import_cell($cells, $map, 'status')), ['inactive', 'blocked', 'disabled'], true)
                ? 'inactive' : 'active';

            if ($studentNo === '') {
                $problems[] = 'row ' . $rowNo . ': missing or invalid student number';
                continue;
            }
            if ($fullName === '') {
                $problems[] = 'row ' . $rowNo . ' (' . $studentNo . '): missing name';
                continue;
            }
            if ($course === '') {
                $problems[] = 'row ' . $rowNo . ' (' . $studentNo . '): missing course';
                continue;
            }
            $cleared = '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $email   = '';
                $cleared = ' (invalid email was cleared)';
            }

            $key = mb_strtolower($studentNo);
            if (isset($seen[$key])) {
                $problems[] = 'row ' . $rowNo . ': ' . $studentNo . ' appears twice in this file';
                continue;
            }
            $seen[$key] = true;

            if (isset($existing[$key])) {
                if (!$updateExisting) {
                    $duplicates++;
                    continue;
                }
                Database::update('students', [
                    'full_name'  => $fullName,
                    'course'     => $course,
                    'year_level' => $year,
                    'section'    => $section,
                    'email'      => $email,
                    'contact'    => $contact,
                    'status'     => $status,
                ], 'id = :id', ['id' => $existing[$key]]);
                $updated++;
                if ($cleared !== '') {
                    $problems[] = 'row ' . $rowNo . ' (' . $studentNo . ')' . $cleared;
                }
                continue;
            }

            Database::insert('students', [
                'student_no'   => $studentNo,
                'full_name'    => $fullName,
                'course'       => $course,
                'year_level'   => $year,
                'section'      => $section,
                'email'        => $email,
                'contact'      => $contact,
                'qr_nonce'     => Security::nonce(),
                'status'       => $status,
                'created_at'   => Helpers::now(),
                'qr_issued_at' => Helpers::now(),
            ]);
            $imported++;
            if ($cleared !== '') {
                $problems[] = 'row ' . $rowNo . ' (' . $studentNo . ')' . $cleared;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'imported'   => $imported,
        'updated'    => $updated,
        'duplicates' => $duplicates,
        'problems'   => $problems,
    ];
}

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

/* ---- CSV Template Download ---- */
if (Helpers::get('download') === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zdspgc_student_import_template.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel
    fputcsv($out, ['student_id', 'first_name', 'middle_name', 'last_name', 'course', 'year_level', 'section', 'email']);
    // Sample rows
    fputcsv($out, ['2026-001003', 'Juan', 'Santos', 'Dela Cruz', 'BSIT', '1st Year', 'C1', 'juan.delacruz@zdspgc.edu.ph']);
    fputcsv($out, ['2026-001004', 'Maria', 'Reyes', 'Santos', 'BSIT', '1st Year', 'C1', 'maria.santos@zdspgc.edu.ph']);
    fclose($out);
    exit;
}

/* ---- Sample roster download (500 random students, for demo/training) ----- */
if (Helpers::get('download') === 'sample') {
    $sample = __DIR__ . '/database/sample_500_students.csv';
    if (!is_readable($sample)) {
        Helpers::flash('error', 'The sample roster file is missing. Regenerate it with: php generate_students.php 500 database/sample_500_students.csv 2001');
        Helpers::redirect('students.php?import=1');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zdspgc_sample_students.csv"');
    header('Content-Length: ' . (string) filesize($sample));
    header('Cache-Control: no-store');
    readfile($sample);
    exit;
}

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

    /* ---- bulk CSV import (button beside "Print QR ID cards") -------------- */
    if ($action === 'import') {
        $upload = $_FILES['csv_file'] ?? null;

        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Helpers::flash('error', 'Choose a CSV file first, then press Import CSV.');
            Helpers::redirect('students.php?import=1');
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp) || (int) ($upload['size'] ?? 0) <= 0 || (int) $upload['size'] > 5 * 1024 * 1024) {
            Helpers::flash('error', 'The upload must be a non-empty CSV file of at most 5 MB.');
            Helpers::redirect('students.php?import=1');
        }

        $handle = @fopen($tmp, 'rb');
        if ($handle === false) {
            Helpers::flash('error', 'The uploaded file could not be read.');
            Helpers::redirect('students.php?import=1');
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header) || $header === []) {
            fclose($handle);
            Helpers::flash('error', 'That CSV file is empty.');
            Helpers::redirect('students.php?import=1');
        }
        $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $map       = import_map_columns(array_map(static fn ($cell): string => (string) $cell, $header));

        if (!isset($map['student_no']) || (!isset($map['full_name']) && !(isset($map['first_name']) && isset($map['last_name'])))) {
            fclose($handle);
            Helpers::flash('error', 'The CSV needs a student number column plus either a full name or first + last name columns. Use the template for the exact layout.');
            Helpers::redirect('students.php?import=1');
        }

        try {
            $result = import_run($handle, $map, Helpers::post('update_existing') === '1');
        } catch (Throwable $e) {
            fclose($handle);
            Helpers::flash('error', 'Import rolled back - nothing was saved: ' . $e->getMessage());
            Helpers::redirect('students.php?import=1');
        }
        fclose($handle);

        Security::audit(
            'STUDENT_IMPORT',
            'CSV "' . (string) ($upload['name'] ?? 'upload') . '": ' . $result['imported'] . ' added, '
                . $result['updated'] . ' updated, ' . $result['duplicates'] . ' duplicate(s) skipped',
            'students'
        );

        $summary = $result['imported'] . ' student(s) added, each with a fresh signed QR ID.';
        if ($result['updated'] > 0) {
            $summary .= ' ' . $result['updated'] . ' existing record(s) updated.';
        }
        if ($result['duplicates'] > 0) {
            $summary .= ' ' . $result['duplicates'] . ' duplicate student number(s) skipped.';
        }
        Helpers::flash('success', $summary);

        $problems = $result['problems'];
        if ($problems !== []) {
            Helpers::flash('error', count($problems) . ' row(s) need a look: '
                . implode('; ', array_slice($problems, 0, 4))
                . (count($problems) > 4 ? ' ... and ' . (count($problems) - 4) . ' more' : '') . '.');
        }
        Helpers::redirect('students.php?import=1');
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
$PAGE_ACTIONS = '<a class="btn" href="' . Helpers::e(Helpers::url('students.php', ['import' => 1]) . '#import-csv') . '">'
    . icon('upload') . '<span>Import CSV</span></a>'
    . '<a class="btn ghost" href="' . Helpers::e(Helpers::url('qr_cards.php')) . '">'
    . icon('qr') . '<span>Print QR ID cards</span></a>';

require __DIR__ . '/includes/layout/header.php';
?>

<?php if (Helpers::get('import') === '1'): ?>
<div class="card" id="import-csv" style="margin-bottom: 16px;">
  <h3><?= icon('upload') ?> Import students from CSV</h3>
  <div class="grid cols-2">
    <div>
      <p class="hint">
        Upload the campus roster as a <strong>.csv</strong> file. Column names are matched
        loosely (case, spaces and underscores do not matter): a student number column
        (<span class="mono">student_id</span> / <span class="mono">student_no</span> /
        <span class="mono">Student number</span>), a single <span class="mono">full_name</span>
        — or <span class="mono">first_name</span>, <span class="mono">middle_name</span> and
        <span class="mono">last_name</span> — plus <span class="mono">course</span>,
        <span class="mono">year_level</span>, <span class="mono">section</span> and
        <span class="mono">email</span>. <span class="mono">contact</span> and
        <span class="mono">status</span> are picked up when present, and the roster CSV
        export works as-is.
      </p>
      <p class="hint">
        Student numbers already on file are skipped unless the box below is ticked.
        Every newly imported student gets a fresh signed QR ID — print the cards
        afterwards from <a href="<?= Helpers::e(Helpers::url('qr_cards.php')) ?>">Print QR ID cards</a>.
      </p>
    </div>
    <form method="post" action="<?= Helpers::e(Helpers::url('students.php', ['import' => 1])) ?>" enctype="multipart/form-data">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="import">

      <label class="field">
        <span>CSV file *</span>
        <input type="file" name="csv_file" accept=".csv,text/csv" required>
      </label>
      <label class="checkline">
        <input type="checkbox" name="update_existing" value="1">
        <span>Also update students whose number is already on file</span>
      </label>

      <div class="row mt">
        <button type="submit"><?= icon('upload') ?><span>Import CSV</span></button>
        <a class="btn ghost" href="<?= Helpers::e(Helpers::url('students.php', ['download' => 'sample'])) ?>"><?= icon('download') ?><span>Sample roster (500)</span></a>
        <a class="btn ghost" href="<?= Helpers::e(Helpers::url('students.php', ['download' => 'template'])) ?>"><?= icon('download') ?><span>Blank template</span></a>
        <a class="btn ghost" href="<?= Helpers::e(Helpers::url('students.php')) ?>"><?= icon('x-circle') ?><span>Close</span></a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

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
      <table class="tbl responsive">
        <thead>
          <tr><th>Student</th><th>Course</th><th class="num">Events attended</th><th>Last scan</th><th>Status</th><th class="right">Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($students as $student): $sid = (int) $student['id']; ?>
          <tr>
            <td data-label="Student">
              <a href="<?= Helpers::e(Helpers::url('student.php', ['id' => $sid])) ?>"><strong><?= Helpers::e((string) $student['full_name']) ?></strong></a><br>
              <span class="small muted mono"><?= Helpers::e((string) $student['student_no']) ?></span>
            </td>
            <td class="small" data-label="Course"><?= Helpers::e((string) $student['course']) ?><br>
              <span class="muted"><?= Helpers::e((string) $student['year_level']) ?> <?= Helpers::e((string) $student['section']) ?></span></td>
            <td class="num" data-label="Events attended"><?= (int) $student['visits'] ?></td>
            <td class="small" data-label="Last scan"><?= Helpers::e($student['last_visit'] === null ? 'never' : Helpers::humanAgo((string) $student['last_visit'])) ?></td>
            <td data-label="Status"><?= (string) $student['status'] === 'active'
                ? '<span class="badge">active</span>'
                : '<span class="badge grey">inactive</span>' ?></td>
            <td class="right nowrap" data-label="Actions">
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
