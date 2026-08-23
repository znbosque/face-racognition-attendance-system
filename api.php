<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';

function data(): array { $value = json_decode(file_get_contents('php://input'), true); return is_array($value) ? $value : $_POST; }
function reply(array $value, int $status = 200): never { http_response_code($status); echo json_encode($value); exit; }
function user(): array { return $_SESSION['user'] ?? []; }
function requireLogin(): void { if (!user()) reply(['message' => 'Login required.'], 401); }
function audit(PDO $db, string $action): void {
    $actor = user()['name'] ?? 'System';
    $statement = $db->prepare('INSERT INTO audit_logs (action, actor) VALUES (?, ?)');
    $statement->execute([$action, $actor]);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$payload = data();

if ($method === 'POST' && $action === 'signup') {
    $name = trim((string) ($payload['name'] ?? ''));
    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    $password = (string) ($payload['password'] ?? '');
    $role = 'Administrator';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) reply(['message' => 'Please provide valid account details.'], 400);
    try {
        $statement = $db->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        $_SESSION['user'] = ['name' => $name, 'email' => $email, 'role' => $role];
        audit($db, 'Created account');
        reply(['user' => user()], 201);
    } catch (PDOException $error) {
        if (($error->errorInfo[0] ?? '') === '23000') reply(['message' => 'An account with this email already exists.'], 409);
        reply(['message' => 'Unable to create the account.'], 500);
    }
}

if ($method === 'POST' && $action === 'login') {
    $statement = $db->prepare('SELECT name, email, role, password_hash FROM users WHERE email = ? LIMIT 1');
    $statement->execute([strtolower(trim((string) ($payload['email'] ?? '')))]);
    $record = $statement->fetch();
    if (!$record || !password_verify((string) ($payload['password'] ?? ''), $record['password_hash'])) reply(['message' => 'Incorrect email or password.'], 401);
    $_SESSION['user'] = ['name' => $record['name'], 'email' => $record['email'], 'role' => $record['role']];
    reply(['user' => user()]);
}

if ($action === 'me') { requireLogin(); reply(['user' => user()]); }
if ($method === 'POST' && $action === 'logout') { $_SESSION = []; session_destroy(); reply(['message' => 'Logged out.']); }

requireLogin();

if ($method === 'GET' && $action === 'dashboard') {
    $students = $db->query('SELECT student_id, full_name, course, year, school_year, status, parent_phone, is_archived, archived_school_year, face_image_path FROM students ORDER BY full_name COLLATE NOCASE')->fetchAll();
    $schedules = $db->query('SELECT id, subject, instructor, room, day, start_time, end_time FROM schedules ORDER BY day, start_time')->fetchAll();
    $attendance = $db->query('SELECT student_id, student_name, course, attendance_date, subject, time_in, time_out, status FROM attendance ORDER BY id')->fetchAll();
    $audit = $db->query('SELECT action, actor, created_at FROM audit_logs ORDER BY id DESC LIMIT 20')->fetchAll();
    $settings = $db->query('SELECT setting_key, setting_value FROM notification_settings')->fetchAll();
    $notificationSettings = [];
    foreach ($settings as $setting) $notificationSettings[$setting['setting_key']] = $setting['setting_value'] === 'true';
    reply(['students' => $students, 'schedules' => $schedules, 'attendance' => $attendance, 'audit' => $audit, 'notificationSettings' => $notificationSettings]);
}

if ($method === 'POST' && $action === 'student') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $schoolYear = trim((string) ($payload['schoolYear'] ?? ''));
    if (!preg_match('/^\d{4}-\d{4}$/', $schoolYear) || (int) substr($schoolYear, 5) !== (int) substr($schoolYear, 0, 4) + 1) reply(['message' => 'Please provide a valid school year, such as 2026-2027.'], 400);
    $parentPhone = trim((string) ($payload['parentPhone'] ?? ''));
    $phoneDigits = preg_replace('/\D+/', '', $parentPhone);
    if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) reply(['message' => 'Please provide a valid parent phone number.'], 400);
    if (str_starts_with($phoneDigits, '63')) {
        $parentPhone = '+' . $phoneDigits;
    } elseif (str_starts_with($phoneDigits, '0')) {
        $parentPhone = '+63' . substr($phoneDigits, 1);
    } else {
        $parentPhone = '+' . $phoneDigits;
    }
    $facePhoto = (string) ($payload['facePhoto'] ?? '');
    if (!preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=]+)$/', $facePhoto, $matches)) reply(['message' => 'Please provide a valid face photo.'], 400);
    $imageData = base64_decode($matches[2], true);
    if ($imageData === false || strlen($imageData) > 5 * 1024 * 1024) reply(['message' => 'The face photo must be 5 MB or smaller.'], 400);
    $imageInfo = @getimagesizefromstring($imageData);
    if (!$imageInfo || !in_array($imageInfo['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) reply(['message' => 'The face photo is not a supported image.'], 400);
    $imageDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'face_images';
    if (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0755, true)) reply(['message' => 'Unable to create the face photo folder.'], 500);
    $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
    $imageName = hash('sha256', (string) ($payload['studentId'] ?? '')) . '.' . $extension;
    $imagePath = $imageDirectory . DIRECTORY_SEPARATOR . $imageName;
    if (file_put_contents($imagePath, $imageData) === false) reply(['message' => 'Unable to save the face photo.'], 500);
    $relativeImagePath = 'face_images/' . $imageName;
    $statement = $db->prepare('INSERT INTO students (student_id, full_name, course, year, school_year, status, parent_phone, face_image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    try {
        $statement->execute([$payload['studentId'], $payload['fullName'], $payload['course'], $payload['year'], $schoolYear, $payload['status'], $parentPhone, $relativeImagePath]);
    } catch (PDOException $error) {
        @unlink($imagePath);
        throw $error;
    }
    audit($db, 'Added student ' . $payload['studentId']);
    reply(['message' => 'Student added.']);
}

if ($method === 'POST' && $action === 'archive-student') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $value = (int) ($payload['archived'] ?? 0);
    $statement = $db->prepare('UPDATE students SET is_archived = ? WHERE student_id = ?');
    $statement->execute([$value, $payload['studentId']]);
    audit($db, ($value ? 'Archived ' : 'Restored ') . 'student ' . $payload['studentId']);
    reply(['message' => 'Student updated.']);
}

if ($method === 'POST' && $action === 'archive-year') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $schoolYear = trim((string) ($payload['schoolYear'] ?? ''));
    if ($schoolYear === '') reply(['message' => 'A school year is required.'], 400);
    $statement = $db->prepare('UPDATE students SET is_archived = 1, archived_school_year = ? WHERE is_archived = 0 AND school_year = ?');
    $statement->execute([$schoolYear, $schoolYear]);
    audit($db, 'Archived students for school year ' . $schoolYear);
    reply(['message' => 'Students archived for ' . $schoolYear . '.']);
}

if ($method === 'POST' && $action === 'restore-year') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $schoolYear = trim((string) ($payload['schoolYear'] ?? ''));
    if ($schoolYear === '') reply(['message' => 'A school year is required.'], 400);
    $statement = $db->prepare('UPDATE students SET is_archived = 0, archived_school_year = NULL WHERE archived_school_year = ?');
    $statement->execute([$schoolYear]);
    audit($db, 'Restored students for school year ' . $schoolYear);
    reply(['message' => 'Students restored for ' . $schoolYear . '.']);
}

if ($method === 'POST' && $action === 'schedule') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    if (!empty($payload['id'])) {
        $statement = $db->prepare('UPDATE schedules SET subject = ?, instructor = ?, room = ?, day = ?, start_time = ?, end_time = ? WHERE id = ?');
        $statement->execute([$payload['subject'], $payload['teacher'], $payload['room'], $payload['day'], $payload['startTime'], $payload['endTime'], $payload['id']]);
        audit($db, 'Updated schedule ' . $payload['subject']);
    } else {
        $statement = $db->prepare('INSERT INTO schedules (subject, instructor, room, day, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([$payload['subject'], $payload['teacher'], $payload['room'], $payload['day'], $payload['startTime'], $payload['endTime']]);
        audit($db, 'Added schedule ' . $payload['subject']);
    }
    reply(['message' => 'Schedule added.']);
}

if ($method === 'POST' && $action === 'delete-schedule') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $statement = $db->prepare('DELETE FROM schedules WHERE id = ?');
    $statement->execute([$payload['id']]);
    audit($db, 'Deleted schedule');
    reply(['message' => 'Schedule deleted.']);
}

if ($method === 'POST' && $action === 'settings') {
    foreach (['absent', 'late', 'checkIn'] as $key) {
        $statement = $db->prepare('INSERT INTO notification_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value');
        $statement->execute([$key, !empty($payload[$key]) ? 'true' : 'false']);
    }
    audit($db, 'Updated notification settings');
    reply(['message' => 'Settings saved.']);
}

reply(['message' => 'Unknown action.'], 404);
