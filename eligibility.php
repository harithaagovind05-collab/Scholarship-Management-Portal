<?php
/*
|----------------------------------------------------------
| eligibility.php - Shared eligibility engine
|
| Single source of truth for "can this student apply for this
| scholarship?". Used by:
|     - application_form.php   (blocks ineligible submissions)
|     - applications.php       (guards status updates + badges)
|     - index.php              (dashboard badges)
|     - eligibility_check.php  (live JSON check for the form)
|
| RULES PHILOSOPHY:
| Only criteria the project actually stores data for are checked
| automatically. Nothing is invented:
|     - Required_Gender           vs Student.Gender
|     - Required_Course_Keywords  vs Student.Course
| Every remaining condition described in the scholarship's free-text
| Eligibility field (income, CGPA, school type...) is reported as a
| MANUAL condition that an administrator must verify.
|
| Fail-closed: if a required value is unknown (e.g. Student.Gender is
| NULL), the student is NOT eligible until the data is recorded.
|
| Gender is validated from the stored Student.Gender field only.
| Names are never used to infer gender.
|----------------------------------------------------------
*/

/**
 * Normalise a raw gender value into 'Male', 'Female' or null (unknown).
 */
function normalizeGender($raw)
{
    $g = strtolower(trim((string)$raw));
    if ($g === '') return null;
    if (in_array($g, ['male', 'm', 'boy', 'man']))        return 'Male';
    if (in_array($g, ['female', 'f', 'girl', 'woman']))   return 'Female';
    if (in_array($g, ['other', 'non-binary', 'nb']))      return 'Other';
    return null; // unrecognised => unknown => fail-closed
}

/**
 * Evaluate one student against one scholarship.
 *
 * @param array $student      Row from Student (needs Student_ID, Name, Gender, Course)
 * @param array $scholarship  Row from Scholarship (needs Scholarship_ID, Name, Eligibility,
 *                                                 Required_Gender, Required_Course_Keywords, Deadline)
 * @return array [
 *   'eligible'      => bool   true only if ALL machine-checkable criteria pass
 *   'provable_fail' => bool   true when a recorded value actually contradicts a
 *                             criterion (safe to auto-reject). false when the
 *                             failure is only "data missing" (needs review).
 *   'blockers'      => array  strings: failed hard criteria (block submission)
 *   'manual_checks' => array  strings: criteria that still need human verification
 *   'summary'       => string one-line human-readable result
 * ]
 */
function checkEligibility(array $student, array $scholarship): array
{
    $blockers      = [];
    $manualChecks  = [];
    $provableFail  = false;

    /* ---------- 1. Gender criterion ---------- */
    $requiredGender = normalizeGender($scholarship['Required_Gender'] ?? null);

    if ($requiredGender !== null) {
        $studentGender = normalizeGender($student['Gender'] ?? null);

        if ($studentGender === null) {
            // Fail-closed: gender not recorded => cannot verify => not eligible.
            $blockers[] = 'Gender is not recorded for this student. Record it in the Students module before applying for this scholarship.';
        } elseif ($studentGender !== $requiredGender) {
            $blockers[] = 'This scholarship is for ' . strtolower($requiredGender)
                        . ' students only; this student is recorded as ' . strtolower($studentGender) . '.';
            $provableFail = true;   // recorded value contradicts the criterion
        }
    }

    /* ---------- 2. Course criterion ---------- */
    $keywordsRaw = trim((string)($scholarship['Required_Course_Keywords'] ?? ''));
    if ($keywordsRaw !== '') {
        $keywords = array_values(array_filter(array_map('trim', explode(',', $keywordsRaw))));
        $course   = strtolower(trim((string)($student['Course'] ?? '')));

        if ($course === '') {
            $blockers[] = 'Course is not recorded for this student, but this scholarship requires a specific course.';
        } else {
            $matched = false;
            foreach ($keywords as $kw) {
                if ($kw !== '' && strpos($course, strtolower($kw)) !== false) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $blockers[] = 'Course "' . $student['Course'] . '" does not match the required course(s): ' . implode(', ', $keywords) . '.';
                $provableFail = true;   // recorded value contradicts the criterion
            }
        }
    }

    /* ---------- 3. Deadline (machine-checkable, only when set) ---------- */
    if (!empty($scholarship['Deadline'])) {
        $deadline = date('Y-m-d', strtotime($scholarship['Deadline']));
        $today    = date('Y-m-d');
        if ($today > $deadline) {
            $manualChecks[] = 'Application deadline was ' . date('d M Y', strtotime($deadline)) . ' (already passed) - reopen only by explicit decision.';
        }
    }

    /* ---------- 4. Manual conditions from the free-text criteria ---------- */
    // Any text that survives after removing the machine-checked parts is a
    // human-verification item. We never parse meaning we cannot enforce.
    $eligText = trim((string)($scholarship['Eligibility'] ?? ''));
    if ($eligText !== '') {
        $manual = $eligText;

        if ($requiredGender !== null) {
            // Remove the gender clause so it is not double-reported.
            $manual = preg_replace('/\b(female|male|girls?|boys?|women|men)\b[^;,.]*/i', '', $manual, 1);
        }
        if ($keywordsRaw !== '') {
            foreach ($keywords as $kw) {
                $manual = str_ireplace($kw, '', $manual);
            }
        }
        $manual = trim(preg_replace('/\s+/', ' ', $manual), " \t\n\r;,-");

        if ($manual !== '') {
            $manualChecks[] = 'Verify manually: ' . $manual . ' (no structured data for this in the system).';
        }
    }

    $eligible = count($blockers) === 0;

    if ($eligible && count($manualChecks) === 0) {
        $summary = 'Eligible - all recorded criteria are satisfied.';
    } elseif ($eligible) {
        $summary = 'Meets all automatic criteria; ' . count($manualChecks)
                 . ' condition(s) still need manual verification.';
    } else {
        $summary = 'NOT eligible: ' . $blockers[0];
        if (count($blockers) > 1) {
            $summary .= ' (+' . (count($blockers) - 1) . ' more issue(s))';
        }
    }

    return [
        'eligible'      => $eligible,
        'provable_fail' => $provableFail,
        'blockers'      => $blockers,
        'manual_checks' => $manualChecks,
        'summary'       => $summary,
    ];
}

/**
 * Convenience: load student + scholarship rows and evaluate.
 * Returns null if either record does not exist.
 */
function checkEligibilityByIds(PDO $pdo, $studentId, $scholarshipId): ?array
{
    $stmt = $pdo->prepare("SELECT Student_ID, Name, Gender, Course
                           FROM Student WHERE Student_ID = :sid");
    $stmt->execute([':sid' => $studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT Scholarship_ID, Name, Eligibility,
                                  Required_Gender, Required_Course_Keywords, Deadline
                           FROM Scholarship WHERE Scholarship_ID = :scid");
    $stmt->execute([':scid' => $scholarshipId]);
    $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student || !$scholarship) {
        return null;
    }

    $result              = checkEligibility($student, $scholarship);
    $result['student']     = $student;
    $result['scholarship'] = $scholarship;
    return $result;
}

/**
 * Small HTML badge renderer for consistent display across modules.
 */
function eligibilityBadge(array $result): string
{
    if ($result === null) {
        return '<span class="badge bg-secondary-subtle text-secondary">Unknown</span>';
    }
    if (!$result['eligible']) {
        if (!empty($result['provable_fail'])) {
            return '<span class="badge bg-danger-subtle text-danger" title="'
                 . htmlspecialchars($result['summary']) . '">Ineligible</span>';
        }
        // Not provable - the failure is a data gap (e.g. gender unrecorded).
        return '<span class="badge bg-secondary-subtle text-secondary" title="'
             . htmlspecialchars($result['summary']) . '">Unverified</span>';
    }
    if (count($result['manual_checks']) > 0) {
        return '<span class="badge bg-warning-subtle text-warning-emphasis" title="'
             . htmlspecialchars($result['summary']) . '">Eligible*</span>';
    }
    return '<span class="badge bg-success-subtle text-success" title="'
         . htmlspecialchars($result['summary']) . '">Eligible</span>';
}
