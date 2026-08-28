<<<<<<< HEAD
<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';

function data(): array { $value = json_decode(file_get_contents('php://input'), true); return is_array($value) ? $value : $_POST; }
function reply(array $value, int $status = 200): never { http_response_code($status); echo json_encode($value); exit; }
function user(): array { return $_SESSION['user'] ?? []; }
function requireLogin(): void { if (!user()) reply(['message' => 'Login required.'], 401); }
function sendVerificationEmail(string $recipient, string $code): bool {
    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'mail-config.php';
    $config = is_file($configPath) ? require $configPath : [];
    if (!is_array($config) || empty($config['host']) || empty($config['username']) || empty($config['password'])) {
        return false;
    }

    $host = (string) $config['host'];
    $port = (int) ($config['port'] ?? 587);
    $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));
    $from = (string) ($config['from'] ?? $config['username']);
    $fromName = (string) ($config['from_name'] ?? 'DRLCEFI Attendance');
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client($remote, $errorNumber, $errorMessage, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) return false;
    stream_set_timeout($socket, 15);

    $read = static function () use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $response;
    };
    $write = static function (string $command) use ($socket, $read): bool {
        fwrite($socket, $command . "\r\n");
        return (int) substr($read(), 0, 3) < 400;
    };

    $ready = (int) substr($read(), 0, 3) < 400;
    $ready = $ready && $write('EHLO localhost');
    if ($ready && $encryption === 'tls') {
        $ready = $write('STARTTLS') && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $ready = $ready && $write('EHLO localhost');
    }
    $ready = $ready && $write('AUTH LOGIN');
    $ready = $ready && $write(base64_encode((string) $config['username']));
    $ready = $ready && $write(base64_encode((string) $config['password']));
    $ready = $ready && $write('MAIL FROM:<' . $from . '>');
    $ready = $ready && $write('RCPT TO:<' . $recipient . '>');
    $ready = $ready && $write('DATA');
    if ($ready) {
        $subject = 'DRLCEFI password reset verification code';
        $body = "Your DRLCEFI verification code is: {$code}\r\n\r\nThis code expires in 15 minutes. If you did not request a password reset, you can ignore this email.";
        $message = 'From: ' . $fromName . ' <' . $from . ">\r\n"
            . 'To: <' . $recipient . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $body;
        $message = preg_replace('/^\./m', '..', $message) . "\r\n.";
        fwrite($socket, $message . "\r\n");
        $ready = (int) substr($read(), 0, 3) < 400;
    }
    $write('QUIT');
    fclose($socket);
    return $ready;
}
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
        $_SESSION['user'] = ['id' => (int) $db->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role, 'profileImage' => null];
        audit($db, 'Created account');
        reply(['user' => user()], 201);
    } catch (PDOException $error) {
        if (($error->errorInfo[0] ?? '') === '23000') reply(['message' => 'An account with this email already exists.'], 409);
        reply(['message' => 'Unable to create the account.'], 500);
    }
}

if ($method === 'POST' && $action === 'login') {
    $lockoutUntil = (int) ($_SESSION['login_lockout_until'] ?? 0);
    if ($lockoutUntil > time()) reply(['message' => 'Too many failed attempts. Try again in 1 minute.', 'retryAfter' => $lockoutUntil - time()], 429);
    if ($lockoutUntil > 0) unset($_SESSION['login_lockout_until'], $_SESSION['login_failed_attempts']);

    $statement = $db->prepare('SELECT id, name, email, role, password_hash, profile_image FROM users WHERE email = ? LIMIT 1');
    $statement->execute([strtolower(trim((string) ($payload['email'] ?? '')))]);
    $record = $statement->fetch();
    if (!$record || !password_verify((string) ($payload['password'] ?? ''), $record['password_hash'])) {
        $failedAttempts = (int) ($_SESSION['login_failed_attempts'] ?? 0) + 1;
        if ($failedAttempts >= 3) {
            $_SESSION['login_failed_attempts'] = 0;
            $_SESSION['login_lockout_until'] = time() + 60;
            reply(['message' => 'Too many failed attempts. Try again in 1 minute.', 'retryAfter' => 60], 429);
        }
        $_SESSION['login_failed_attempts'] = $failedAttempts;
        reply(['message' => 'Incorrect email or password.'], 401);
    }
    unset($_SESSION['login_failed_attempts'], $_SESSION['login_lockout_until']);
    $_SESSION['user'] = ['id' => (int) $record['id'], 'name' => $record['name'], 'email' => $record['email'], 'role' => $record['role'], 'profileImage' => $record['profile_image']];
    reply(['user' => user()]);
}

if ($action === 'me') {
    requireLogin();
    $statement = $db->prepare('SELECT id, name, email, role, profile_image FROM users WHERE email = ? LIMIT 1');
    $statement->execute([user()['email'] ?? '']);
    $record = $statement->fetch();
    if ($record) {
        $_SESSION['user'] = ['id' => (int) $record['id'], 'name' => $record['name'], 'email' => $record['email'], 'role' => $record['role'], 'profileImage' => $record['profile_image']];
    }
    reply(['user' => user()]);
}

if ($method === 'POST' && $action === 'request-password-reset') {
    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) reply(['message' => 'Enter a valid email address.'], 400);

    $statement = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);
    $record = $statement->fetch();
    if (!$record) reply(['message' => 'If an account exists for that email, a verification code has been sent.']);

    $code = (string) random_int(100000, 999999);
    $tokenHash = hash('sha256', $code);
    $expiresAt = time() + 900;
    $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([(int) $record['id']]);
    $db->prepare('INSERT INTO password_reset_tokens (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')->execute([(int) $record['id'], $email, $tokenHash, $expiresAt]);

    if (!sendVerificationEmail($email, $code)) {
        $db->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?')->execute([$tokenHash]);
        reply(['message' => 'The verification email could not be sent. Add your SMTP details to mail-config.php and try again.'], 500);
    }
    reply(['message' => 'If an account exists for that email, a verification code has been sent.']);
}

if ($method === 'POST' && $action === 'reset-password') {
    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    $code = trim((string) ($payload['code'] ?? ''));
    $newPassword = (string) ($payload['newPassword'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) reply(['message' => 'Enter the 6-digit verification code from your email.'], 400);
    if (strlen($newPassword) < 6) reply(['message' => 'The new password must be at least 6 characters.'], 400);

    $statement = $db->prepare('SELECT id, user_id FROM password_reset_tokens WHERE email = ? AND token_hash = ? AND expires_at >= ? LIMIT 1');
    $statement->execute([$email, hash('sha256', $code), time()]);
    $token = $statement->fetch();
    if (!$token) reply(['message' => 'That verification code is invalid or expired.'], 400);
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $token['user_id']]);
    $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([(int) $token['user_id']]);
    reply(['message' => 'Password changed successfully. You can now log in.']);
}

if ($method === 'POST' && $action === 'logout') { $_SESSION = []; session_destroy(); reply(['message' => 'Logged out.']); }

requireLogin();

if ($method === 'POST' && $action === 'profile') {
    $name = trim((string) ($payload['name'] ?? ''));
    $profileImage = (string) ($payload['profileImage'] ?? '');
    if ($name === '') reply(['message' => 'A full name is required.'], 400);
    if ($profileImage !== '' && !preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=]+)$/', $profileImage, $matches)) reply(['message' => 'Please upload a valid JPG, PNG, or WebP image.'], 400);
    if ($profileImage !== '' && strlen($profileImage) > 2 * 1024 * 1024 * 1.4) reply(['message' => 'The profile photo must be 2 MB or smaller.'], 400);
    $statement = $db->prepare('UPDATE users SET name = ?, profile_image = ? WHERE id = ?');
    $statement->execute([$name, $profileImage !== '' ? $profileImage : (user()['profileImage'] ?? null), user()['id']]);
    $_SESSION['user']['name'] = $name;
    if ($profileImage !== '') $_SESSION['user']['profileImage'] = $profileImage;
    audit($db, 'Updated administrator profile');
    reply(['user' => user(), 'message' => 'Profile saved.']);
}

if ($method === 'POST' && $action === 'password') {
    $currentPassword = (string) ($payload['currentPassword'] ?? '');
    $newPassword = (string) ($payload['newPassword'] ?? '');
    if (strlen($newPassword) < 6) reply(['message' => 'The new password must be at least 6 characters.'], 400);
    $statement = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $statement->execute([user()['id'] ?? 0]);
    $record = $statement->fetch();
    if (!$record || !password_verify($currentPassword, $record['password_hash'])) reply(['message' => 'The current password is incorrect.'], 400);
    $statement = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT), user()['id']]);
    audit($db, 'Changed administrator password');
    reply(['message' => 'Password changed successfully.']);
}

if ($method === 'GET' && $action === 'dashboard') {
    $students = $db->query('SELECT student_id, full_name, course, year, school_year, status, parent_phone, is_archived, archived_school_year, face_image_path FROM students ORDER BY full_name COLLATE NOCASE')->fetchAll();
    $schedules = $db->query('SELECT id, subject, instructor, room, day, start_time, end_time, school_year, is_archived, archived_school_year FROM schedules ORDER BY day, start_time')->fetchAll();
    $attendance = $db->query('SELECT student_id, student_name, course, attendance_date, subject, time_in, time_out, status, school_year, is_archived, archived_school_year FROM attendance ORDER BY id')->fetchAll();
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
    $attendanceStatement = $db->prepare('UPDATE attendance SET is_archived = 1, school_year = ?, archived_school_year = ? WHERE is_archived = 0 AND student_id IN (SELECT student_id FROM students WHERE archived_school_year = ?)');
    $attendanceStatement->execute([$schoolYear, $schoolYear, $schoolYear]);
    $scheduleStatement = $db->prepare('UPDATE schedules SET is_archived = 1, archived_school_year = ? WHERE is_archived = 0 AND school_year = ?');
    $scheduleStatement->execute([$schoolYear, $schoolYear]);
    audit($db, 'Archived students for school year ' . $schoolYear);
    reply(['message' => 'Students archived for ' . $schoolYear . '.']);
}

if ($method === 'POST' && $action === 'archive-attendance-date') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $dateKey = trim((string) ($payload['date'] ?? ''));
    $schoolYear = trim((string) ($payload['schoolYear'] ?? ''));
    $date = DateTime::createFromFormat('!Y-m-d', $dateKey);
    if (!$date || $date->format('Y-m-d') !== $dateKey) reply(['message' => 'A valid attendance date is required.'], 400);
    if (!preg_match('/^\d{4}-\d{4}$/', $schoolYear) || (int) substr($schoolYear, 5) !== (int) substr($schoolYear, 0, 4) + 1) reply(['message' => 'A valid school year is required.'], 400);
    $displayDate = $date->format('F j, Y');
    $statement = $db->prepare('UPDATE attendance SET is_archived = 1, school_year = ?, archived_school_year = ? WHERE attendance_date = ? AND is_archived = 0');
    $statement->execute([$schoolYear, $schoolYear, $displayDate]);
    audit($db, 'Archived attendance for ' . $displayDate . ' (' . $schoolYear . ')');
    reply(['message' => 'Attendance archived for ' . $displayDate . '.']);
}

if ($method === 'POST' && $action === 'restore-year') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $schoolYear = trim((string) ($payload['schoolYear'] ?? ''));
    if ($schoolYear === '') reply(['message' => 'A school year is required.'], 400);
    $statement = $db->prepare('UPDATE students SET is_archived = 0, archived_school_year = NULL WHERE archived_school_year = ?');
    $statement->execute([$schoolYear]);
    $attendanceStatement = $db->prepare('UPDATE attendance SET is_archived = 0, archived_school_year = NULL WHERE archived_school_year = ?');
    $attendanceStatement->execute([$schoolYear]);
    $scheduleStatement = $db->prepare('UPDATE schedules SET is_archived = 0, archived_school_year = NULL WHERE archived_school_year = ?');
    $scheduleStatement->execute([$schoolYear]);
    audit($db, 'Restored students for school year ' . $schoolYear);
    reply(['message' => 'Students restored for ' . $schoolYear . '.']);
}

if ($method === 'POST' && $action === 'schedule') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $schoolYear = trim((string) ($payload['schoolYear'] ?? ''));
    if (!preg_match('/^\d{4}-\d{4}$/', $schoolYear) || (int) substr($schoolYear, 5) !== (int) substr($schoolYear, 0, 4) + 1) reply(['message' => 'Please provide a valid school year, such as 2026-2027.'], 400);
    if (!empty($payload['id'])) {
        $statement = $db->prepare('UPDATE schedules SET subject = ?, instructor = ?, room = ?, day = ?, start_time = ?, end_time = ?, school_year = ? WHERE id = ?');
        $statement->execute([$payload['subject'], $payload['teacher'], $payload['room'], $payload['day'], $payload['startTime'], $payload['endTime'], $schoolYear, $payload['id']]);
        audit($db, 'Updated schedule ' . $payload['subject']);
    } else {
        $statement = $db->prepare('INSERT INTO schedules (subject, instructor, room, day, start_time, end_time, school_year) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$payload['subject'], $payload['teacher'], $payload['room'], $payload['day'], $payload['startTime'], $payload['endTime'], $schoolYear]);
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
=======
<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';

function data(): array { $value = json_decode(file_get_contents('php://input'), true); return is_array($value) ? $value : $_POST; }
function reply(array $value, int $status = 200): never { http_response_code($status); echo json_encode($value); exit; }
function user(): array { return $_SESSION['user'] ?? []; }
function requireLogin(): void { if (!user()) reply(['message' => 'Login required.'], 401); }
function deviceToken(): string {
    $configuredToken = getenv('ATTENDANCE_DEVICE_TOKEN');
    if ($configuredToken !== false && trim($configuredToken) !== '') return trim($configuredToken);
    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'device_config.php';
    if (is_file($configPath)) {
        $config = require $configPath;
        if (is_array($config) && !empty($config['token'])) return (string) $config['token'];
    }
    return '';
}
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
        $_SESSION['user'] = ['id' => (int) $db->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role, 'profileImage' => null];
        audit($db, 'Created account');
        reply(['user' => user()], 201);
    } catch (PDOException $error) {
        if (($error->errorInfo[0] ?? '') === '23000') reply(['message' => 'An account with this email already exists.'], 409);
        reply(['message' => 'Unable to create the account.'], 500);
    }
}

if ($method === 'POST' && $action === 'login') {
    $statement = $db->prepare('SELECT id, name, email, role, password_hash, profile_image FROM users WHERE email = ? LIMIT 1');
    $statement->execute([strtolower(trim((string) ($payload['email'] ?? '')))]);
    $record = $statement->fetch();
    if (!$record || !password_verify((string) ($payload['password'] ?? ''), $record['password_hash'])) reply(['message' => 'Incorrect email or password.'], 401);
    $_SESSION['user'] = ['id' => (int) $record['id'], 'name' => $record['name'], 'email' => $record['email'], 'role' => $record['role'], 'profileImage' => $record['profile_image']];
    reply(['user' => user()]);
}

if ($action === 'me') {
    requireLogin();
    $statement = $db->prepare('SELECT id, name, email, role, profile_image FROM users WHERE email = ? LIMIT 1');
    $statement->execute([user()['email'] ?? '']);
    $record = $statement->fetch();
    if ($record) {
        $_SESSION['user'] = ['id' => (int) $record['id'], 'name' => $record['name'], 'email' => $record['email'], 'role' => $record['role'], 'profileImage' => $record['profile_image']];
    }
    reply(['user' => user()]);
}

if ($method === 'POST' && $action === 'device-attendance') {
    $configuredToken = deviceToken();
    $requestToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? ($payload['deviceToken'] ?? '');
    if ($configuredToken === '' || !is_string($requestToken) || !hash_equals($configuredToken, $requestToken)) reply(['message' => 'Invalid device token.'], 401);

    $studentId = trim((string) ($payload['studentId'] ?? ''));
    $subject = trim((string) ($payload['subject'] ?? ''));
    $status = trim((string) ($payload['status'] ?? 'Present'));
    $timeIn = trim((string) ($payload['timeIn'] ?? date('g:i A')));
    $timeOut = trim((string) ($payload['timeOut'] ?? '--'));
    if ($studentId === '' || $subject === '' || !in_array($status, ['Present', 'Late'], true)) reply(['message' => 'studentId, subject, and a valid status are required.'], 400);

    $studentStatement = $db->prepare('SELECT student_id, full_name, course, school_year, parent_phone FROM students WHERE student_id = ? AND is_archived = 0 LIMIT 1');
    $studentStatement->execute([$studentId]);
    $student = $studentStatement->fetch();
    if (!$student) reply(['message' => 'Student was not found or is archived.'], 404);

    $attendanceDate = date('F j, Y');
    $duplicateStatement = $db->prepare('SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ? AND subject = ? LIMIT 1');
    $duplicateStatement->execute([$studentId, $attendanceDate, $subject]);
    if ($duplicateStatement->fetch()) reply(['message' => 'Attendance already recorded for this student and subject today.'], 409);

    $statement = $db->prepare('INSERT INTO attendance (student_id, student_name, course, attendance_date, subject, time_in, time_out, status, school_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([$student['student_id'], $student['full_name'], $student['course'], $attendanceDate, $subject, $timeIn, $timeOut, $status, $student['school_year']]);
    audit($db, 'Device recorded ' . $status . ' attendance for ' . $studentId);
    reply(['message' => 'Attendance recorded.', 'studentId' => $studentId, 'studentName' => $student['full_name'], 'parentPhone' => $student['parent_phone'], 'date' => $attendanceDate]);
}

if ($method === 'POST' && $action === 'logout') { $_SESSION = []; session_destroy(); reply(['message' => 'Logged out.']); }

requireLogin();

if ($method === 'POST' && $action === 'profile') {
    $name = trim((string) ($payload['name'] ?? ''));
    $profileImage = (string) ($payload['profileImage'] ?? '');
    if ($name === '') reply(['message' => 'A full name is required.'], 400);
    if ($profileImage !== '' && !preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=]+)$/', $profileImage, $matches)) reply(['message' => 'Please upload a valid JPG, PNG, or WebP image.'], 400);
    if ($profileImage !== '' && strlen($profileImage) > 2 * 1024 * 1024 * 1.4) reply(['message' => 'The profile photo must be 2 MB or smaller.'], 400);
    $statement = $db->prepare('UPDATE users SET name = ?, profile_image = ? WHERE id = ?');
    $statement->execute([$name, $profileImage !== '' ? $profileImage : (user()['profileImage'] ?? null), user()['id']]);
    $_SESSION['user']['name'] = $name;
    if ($profileImage !== '') $_SESSION['user']['profileImage'] = $profileImage;
    audit($db, 'Updated administrator profile');
    reply(['user' => user(), 'message' => 'Profile saved.']);
}

if ($method === 'POST' && $action === 'password') {
    $currentPassword = (string) ($payload['currentPassword'] ?? '');
    $newPassword = (string) ($payload['newPassword'] ?? '');
    if (strlen($newPassword) < 6) reply(['message' => 'The new password must be at least 6 characters.'], 400);
    $statement = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $statement->execute([user()['id'] ?? 0]);
    $record = $statement->fetch();
    if (!$record || !password_verify($currentPassword, $record['password_hash'])) reply(['message' => 'The current password is incorrect.'], 400);
    $statement = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT), user()['id']]);
    audit($db, 'Changed administrator password');
    reply(['message' => 'Password changed successfully.']);
}

if ($method === 'GET' && $action === 'dashboard') {
    $students = $db->query('SELECT student_id, full_name, course, year, school_year, status, parent_phone, is_archived, archived_school_year, face_image_path FROM students ORDER BY full_name COLLATE NOCASE')->fetchAll();
    $schedules = $db->query('SELECT id, subject, instructor, room, day, start_time, end_time, school_year, is_archived, archived_school_year FROM schedules ORDER BY day, start_time')->fetchAll();
    $attendance = $db->query('SELECT student_id, student_name, course, attendance_date, subject, time_in, time_out, status, school_year, is_archived, archived_school_year FROM attendance ORDER BY id')->fetchAll();
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
    $attendanceStatement = $db->prepare('UPDATE attendance SET is_archived = 1, school_year = ?, archived_school_year = ? WHERE is_archived = 0 AND student_id IN (SELECT student_id FROM students WHERE archived_school_year = ?)');
    $attendanceStatement->execute([$schoolYear, $schoolYear, $schoolYear]);
    $scheduleStatement = $db->prepare('UPDATE schedules SET is_archived = 1, archived_school_year = ? WHERE is_archived = 0 AND school_year = ?');
    $scheduleStatement->execute([$schoolYear, $schoolYear]);
    audit($db, 'Archived students for school year ' . $schoolYear);
    reply(['message' => 'Students archived for ' . $schoolYear . '.']);
}

if ($method === 'POST' && $action === 'archive-attendance-date') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $dateKey = trim((string) ($payload['date'] ?? ''));
    $schoolYear = trim((string) ($payload['schoolYear'] ?? ''));
    $date = DateTime::createFromFormat('!Y-m-d', $dateKey);
    if (!$date || $date->format('Y-m-d') !== $dateKey) reply(['message' => 'A valid attendance date is required.'], 400);
    if (!preg_match('/^\d{4}-\d{4}$/', $schoolYear) || (int) substr($schoolYear, 5) !== (int) substr($schoolYear, 0, 4) + 1) reply(['message' => 'A valid school year is required.'], 400);
    $displayDate = $date->format('F j, Y');
    $statement = $db->prepare('UPDATE attendance SET is_archived = 1, school_year = ?, archived_school_year = ? WHERE attendance_date = ? AND is_archived = 0');
    $statement->execute([$schoolYear, $schoolYear, $displayDate]);
    audit($db, 'Archived attendance for ' . $displayDate . ' (' . $schoolYear . ')');
    reply(['message' => 'Attendance archived for ' . $displayDate . '.']);
}

if ($method === 'POST' && $action === 'restore-year') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $schoolYear = trim((string) ($payload['schoolYear'] ?? ''));
    if ($schoolYear === '') reply(['message' => 'A school year is required.'], 400);
    $statement = $db->prepare('UPDATE students SET is_archived = 0, archived_school_year = NULL WHERE archived_school_year = ?');
    $statement->execute([$schoolYear]);
    $attendanceStatement = $db->prepare('UPDATE attendance SET is_archived = 0, archived_school_year = NULL WHERE archived_school_year = ?');
    $attendanceStatement->execute([$schoolYear]);
    $scheduleStatement = $db->prepare('UPDATE schedules SET is_archived = 0, archived_school_year = NULL WHERE archived_school_year = ?');
    $scheduleStatement->execute([$schoolYear]);
    audit($db, 'Restored students for school year ' . $schoolYear);
    reply(['message' => 'Students restored for ' . $schoolYear . '.']);
}

if ($method === 'POST' && $action === 'schedule') {
    if ((user()['role'] ?? '') !== 'Administrator') reply(['message' => 'Administrator permission required.'], 403);
    $schoolYear = trim((string) ($payload['schoolYear'] ?? ''));
    if (!preg_match('/^\d{4}-\d{4}$/', $schoolYear) || (int) substr($schoolYear, 5) !== (int) substr($schoolYear, 0, 4) + 1) reply(['message' => 'Please provide a valid school year, such as 2026-2027.'], 400);
    if (!empty($payload['id'])) {
        $statement = $db->prepare('UPDATE schedules SET subject = ?, instructor = ?, room = ?, day = ?, start_time = ?, end_time = ?, school_year = ? WHERE id = ?');
        $statement->execute([$payload['subject'], $payload['teacher'], $payload['room'], $payload['day'], $payload['startTime'], $payload['endTime'], $schoolYear, $payload['id']]);
        audit($db, 'Updated schedule ' . $payload['subject']);
    } else {
        $statement = $db->prepare('INSERT INTO schedules (subject, instructor, room, day, start_time, end_time, school_year) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$payload['subject'], $payload['teacher'], $payload['room'], $payload['day'], $payload['startTime'], $payload['endTime'], $schoolYear]);
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
>>>>>>> 134244567deebd761d16245076316146e50f51df
