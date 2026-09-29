<?php
/*
|----------------------------------------------------------
| eligibility_check.php - Live eligibility check (JSON)
| Used by application_form.php to show the eligibility result
| while the admin picks a student + scholarship.
|
| Read-only. Mirrors exactly what the server enforces on save
| (same engine: eligibility.php).
|----------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

require_once 'db_connect.php';
require_once 'eligibility.php';

$studentId     = trim($_GET['student_id']     ?? '');
$scholarshipId = trim($_GET['scholarship_id'] ?? '');

if ($studentId === '' || $scholarshipId === '') {
    echo json_encode(['ok' => false, 'message' => 'Select both a student and a scholarship.']);
    exit;
}

$result = checkEligibilityByIds($pdo, $studentId, $scholarshipId);

if ($result === null) {
    echo json_encode(['ok' => false, 'message' => 'Student or scholarship not found.']);
    exit;
}

echo json_encode([
    'ok'            => true,
    'eligible'      => $result['eligible'],
    'summary'       => $result['summary'],
    'blockers'      => $result['blockers'],
    'manual_checks' => $result['manual_checks'],
]);
