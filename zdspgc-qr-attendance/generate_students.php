<?php
/**
 * generate_students.php — writes a random sample roster CSV in the exact column
 * layout understood by the students CSV importer (and the blank template).
 *
 * Usage:
 *     CLI:  php generate_students.php [count] [file.csv] [first-id]
 *           php generate_students.php 500 database/sample_500_students.csv 2001
 *     Web:  generate_students.php?count=500&file=sample_500_students.csv&start=2001
 *
 * Defaults: 200 students -> database/sample_200_students.csv starting at
 * 2026-001003 (the original sample file). The output name must be a plain .csv
 * file name; it is always written inside database/.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$firstNames = ['Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Carmen', 'Luis', 'Isabel', 'Carlos', 'Elena', 'Miguel', 'Sofia', 'Jorge', 'Lucia', 'Fernando', 'Valeria', 'Andres', 'Camila', 'Diego', 'Gabriela', 'Rafael', 'Daniela', 'Santiago', 'Mariana', 'Nicolas', 'Paula', 'Adrian', 'Isabella', 'Emilio', 'Julia', 'Felipe', 'Carolina', 'Sebastian', 'Andrea', 'Manuel', 'Beatriz', 'Christian', 'Patricia', 'Antonio', 'Monica', 'Francisco', 'Rosa', 'David', 'Laura', 'Ricardo', 'Sandra', 'Javier', 'Alberto', 'Silvia', 'Gustavo', 'Teresa', 'Federico', 'Clara', 'Oscar', 'Pablo', 'Diana', 'Hugo', 'Paola', 'Valentina', 'Lucas', 'Marco'];
$middleNames = ['Santos', 'Reyes', 'Cruz', 'Garcia', 'Martinez', 'Hernandez', 'Gonzalez', 'Perez', 'Gomez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Thompson', 'White', 'Harris', 'Clark', 'Lewis', 'Robinson', 'Walker', 'Hall', 'Allen', 'Young', 'King', 'Wright', 'Scott', 'Green', 'Adams', 'Baker', 'Nelson', 'Carter', 'Mitchell', 'Perez', 'Roberts', 'Turner', 'Phillips', 'Campbell', 'Parker', 'Evans', 'Edwards', 'Collins', 'Stewart', 'Sanchez', 'Morris', 'Rogers', 'Reed', 'Cook', 'Morgan', 'Bell', 'Murphy', 'Bailey', 'Rivera', 'Cooper', 'Richardson', 'Cox', 'Howard', 'Ward', 'Torres', 'Peterson', 'Gray', 'Ramirez', 'James', 'Watson', 'Brooks', 'Kelly', 'Sanders', 'Price', 'Bennett', 'Wood', 'Barnes', 'Ross', 'Henderson', 'Coleman', 'Jenkins', 'Perry', 'Powell', 'Long', 'Patterson', 'Hughes', 'Flores', 'Washington', 'Butler', 'Simmons', 'Foster', 'Gonzales', 'Bryant', 'Alexander', 'Russell', 'Griffin', 'Diaz', 'Hayes', 'Myers', 'Ford', 'Hamilton', 'Graham', 'Sullivan', 'Wallace', 'Woods', 'Cole', 'West', 'Jordan', 'Owens', 'Reynolds', 'Fisher', 'Ellis', 'Harrison', 'Gibson', 'Mcdonald', 'Cruz', 'Marshall', 'Ortiz', 'Gomez', 'Molina', 'Webb', 'Stevens', 'Tucker', 'Porter', 'Hunter', 'Hicks', 'Crawford', 'Henry', 'Boyd', 'Mason', 'Morales', 'Kennedy', 'Warren', 'Dixon', 'Ramos', 'Reyes', 'Burns', 'Gordon', 'Shaw', 'Holmes', 'Rice', 'Robertson', 'Hunt', 'Black', 'Daniels', 'Palmer', 'Mills', 'Nichols', 'Grant', 'Knight', 'Ferguson', 'Rose', 'Stone', 'Hawkins', 'Dunn', 'Perkins', 'Hudson', 'Spencer', 'Payne', 'Pierce', 'Berry', 'Matthews', 'Arnold', 'Wagner', 'Willis', 'Ray', 'Watkins', 'Olson', 'Carroll', 'Duncan', 'Snyder', 'Hart', 'Cunningham', 'Bradley', 'Lane', 'Andrews', 'Ruiz', 'Harper', 'Fox', 'Riley', 'Armstrong', 'Carpenter', 'Weaver', 'Greene', 'Lawrence', 'Elliott', 'Chavez', 'Sims', 'Austin', 'Peters', 'Kelley', 'Franklin', 'Lawson', 'Fields', 'Gutierrez', 'Ryan', 'Schmidt', 'Carr', 'Vasquez', 'Castillo'];
$lastNames = ['Dela Cruz', 'Santos', 'Garcia', 'Rodriguez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Clark', 'Lewis', 'Robinson', 'Walker', 'Hall', 'Allen', 'Young', 'King', 'Wright', 'Scott', 'Green', 'Adams', 'Baker', 'Gonzalez', 'Nelson', 'Carter', 'Mitchell', 'Perez', 'Roberts', 'Turner', 'Phillips', 'Campbell', 'Parker', 'Evans', 'Edwards', 'Collins', 'Stewart', 'Sanchez', 'Morris', 'Rogers', 'Reed', 'Cook', 'Morgan', 'Bell', 'Murphy', 'Bailey', 'Rivera', 'Cooper', 'Richardson', 'Cox', 'Howard', 'Ward', 'Torres', 'Peterson', 'Gray', 'Ramirez', 'James', 'Watson', 'Brooks', 'Kelly', 'Sanders', 'Price', 'Bennett', 'Wood', 'Barnes', 'Ross', 'Henderson', 'Coleman', 'Jenkins', 'Perry', 'Powell', 'Long', 'Patterson', 'Hughes', 'Flores', 'Washington', 'Butler', 'Simmons', 'Foster', 'Gonzales', 'Bryant', 'Alexander', 'Russell', 'Griffin', 'Diaz', 'Hayes', 'Myers', 'Ford', 'Hamilton', 'Graham', 'Sullivan', 'Wallace', 'Woods', 'Cole', 'West', 'Jordan', 'Owens', 'Reynolds', 'Fisher', 'Ellis', 'Harrison', 'Gibson', 'Mcdonald', 'Cruz', 'Marshall', 'Ortiz', 'Gomez', 'Molina', 'Webb', 'Stevens', 'Tucker', 'Porter', 'Hunter', 'Hicks', 'Crawford', 'Henry', 'Boyd', 'Mason', 'Morales', 'Kennedy', 'Warren', 'Dixon', 'Ramos', 'Reyes', 'Burns', 'Gordon', 'Shaw', 'Holmes', 'Rice', 'Robertson', 'Hunt', 'Black', 'Daniels', 'Palmer', 'Mills', 'Nichols', 'Grant', 'Knight', 'Ferguson', 'Rose', 'Stone', 'Hawkins', 'Dunn', 'Perkins', 'Hudson', 'Spencer', 'Payne', 'Pierce', 'Berry', 'Matthews', 'Arnold', 'Wagner', 'Willis', 'Ray', 'Watkins', 'Olson', 'Carroll', 'Duncan', 'Snyder', 'Hart', 'Cunningham', 'Bradley', 'Lane', 'Andrews', 'Ruiz', 'Harper', 'Fox', 'Riley', 'Armstrong', 'Carpenter', 'Weaver', 'Greene', 'Lawrence', 'Elliott', 'Chavez', 'Sims', 'Austin', 'Peters', 'Kelley', 'Franklin', 'Lawson', 'Fields', 'Gutierrez', 'Ryan', 'Schmidt', 'Carr', 'Vasquez', 'Castillo'];
$courses = ['BSIT', 'BSED', 'BEED', 'BSBA', 'BSHM', 'BSA', 'BSCrim'];
$yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
$sections = ['C1', 'C2', 'A', 'B', 'C'];

$count    = (int) ($argv[1] ?? $_GET['count'] ?? 200);
$count    = max(1, min(2000, $count));
$fileName = (string) ($argv[2] ?? $_GET['file'] ?? 'sample_200_students.csv');
$firstId  = max(1, (int) ($argv[3] ?? $_GET['start'] ?? 1003));

// Accept "sample.csv" or "database/sample.csv" - always write inside database/.
$fileName = preg_replace('#^database/#', '', str_replace('\\', '/', $fileName)) ?? $fileName;
if (basename($fileName) !== $fileName || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.csv$/', $fileName) !== 1) {
    fwrite(STDERR, 'Refusing "' . $fileName . '" - use a plain .csv file name (letters, digits, dot, dash, underscore).' . PHP_EOL);
    exit(1);
}

$path = __DIR__ . '/database/' . $fileName;
$fp   = fopen($path, 'w');
if ($fp === false) {
    fwrite(STDERR, 'Cannot write ' . $path . PHP_EOL);
    exit(1);
}
fputcsv($fp, ['student_id', 'first_name', 'middle_name', 'last_name', 'course', 'year_level', 'section', 'email']);

$id = $firstId;
for ($i = 0; $i < $count; $i++) {
    $fn = $firstNames[array_rand($firstNames)];
    $mn = $middleNames[array_rand($middleNames)];
    $ln = $lastNames[array_rand($lastNames)];
    $course = $courses[array_rand($courses)];
    $year = $yearLevels[array_rand($yearLevels)];
    $section = $sections[array_rand($sections)];
    $email = strtolower($fn . '.' . str_replace(' ', '', $ln) . $id . '@zdspgc.edu.ph');
    fputcsv($fp, ['2026-' . str_pad($id, 6, '0', STR_PAD_LEFT), $fn, $mn, $ln, $course, $year, $section, $email]);
    $id++;
}
fclose($fp);
echo 'CSV generated: database/' . $fileName . ' (' . $count . " students)\n";