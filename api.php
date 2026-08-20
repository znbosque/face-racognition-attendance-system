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
    $students = $db->query('SELECT student_id, full_name, course, year, status, is_archived, archived_school_year FROM students ORDER BY full_name COLLATE NOCASE')->fetchAll();
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
    $statement = $db->prepare('INSERT INTO students (student_id, full_name, course, year, status) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$payload['studentId'], $payload['fullName'], $payload['course'], $payload['year'], $payload['status']]);
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
    $statement = $db->prepare('UPDATE students SET is_archived = 1, archived_school_year = ? WHERE is_archived = 0');
    $statement->execute([$schoolYear]);
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
