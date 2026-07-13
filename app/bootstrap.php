<?php
declare(strict_types=1);

function app_root(string $path = ''): string
{
    $base = dirname(__DIR__);

    return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
}

function app_boot_env(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }
    $loaded = true;

    $envFile = app_root('.env');
    if (!is_file($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function app_env(string $key, mixed $default = null): mixed
{
    app_boot_env();

    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name((string) app_env('SESSION_NAME', '1g1a_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => filter_var(app_env('SESSION_SECURE', false), FILTER_VALIDATE_BOOL),
        'httponly' => filter_var(app_env('SESSION_HTTP_ONLY', true), FILTER_VALIDATE_BOOL),
        'samesite' => (string) app_env('SESSION_SAME_SITE', 'Lax'),
    ]);
    session_start();
}

function app_base_url(): string
{
    return rtrim((string) app_env('APP_URL', ''), '/');
}

function app_url(string $path = ''): string
{
    return app_base_url() . '/' . ltrim($path, '/');
}

function app_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_json(mixed $data, int $status = 200): never
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_flash(?string $message = null): ?string
{
    app_start_session();

    if ($message !== null) {
        $_SESSION['_flash'] = $message;
        return null;
    }

    if (!isset($_SESSION['_flash'])) {
        return null;
    }

    $flash = (string) $_SESSION['_flash'];
    unset($_SESSION['_flash']);

    return $flash;
}

function app_csrf_token(): string
{
    app_start_session();

    if (!isset($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }

    return (string) $_SESSION['_csrf'];
}

function app_require_csrf(): void
{
    app_start_session();

    $posted = $_POST['_csrf'] ?? '';
    $stored = $_SESSION['_csrf'] ?? '';
    if (!is_string($posted) || !is_string($stored) || $posted === '' || !hash_equals($stored, $posted)) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}

function app_redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function app_detect_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }

    if ($path === '/index.php') {
        return '/';
    }

    if (str_starts_with($path, '/index.php/')) {
        return substr($path, strlen('/index.php'));
    }

    return $path;
}

function app_parse_database_url(?string $url): ?array
{
    if ($url === null || trim($url) === '') {
        $host = (string) app_env('DB_HOST', '');
        $port = (int) app_env('DB_PORT', 5432);
        $dbname = (string) app_env('DB_DATABASE', '');
        $user = (string) app_env('DB_USERNAME', '');
        $pass = (string) app_env('DB_PASSWORD', '');

        if ($host === '' || $dbname === '' || $user === '') {
            return null;
        }

        return compact('host', 'port', 'dbname', 'user', 'pass');
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || empty($parts['path'])) {
        return null;
    }

    return [
        'host' => (string) $parts['host'],
        'port' => isset($parts['port']) ? (int) $parts['port'] : 5432,
        'dbname' => ltrim((string) $parts['path'], '/'),
        'user' => (string) ($parts['user'] ?? ''),
        'pass' => (string) ($parts['pass'] ?? ''),
    ];
}

function app_db(): ?PDO
{
    static $pdo = null;
    static $resolved = false;

    if ($resolved) {
        return $pdo;
    }
    $resolved = true;

    $config = app_parse_database_url((string) app_env('DATABASE_URL', ''));
    if ($config === null) {
        return null;
    }

    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['dbname']);
    try {
        $pdo = new PDO($dsn, (string) $config['user'], (string) $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable) {
        $pdo = null;
    }

    return $pdo;
}

function app_supports_demo_login(): bool
{
    return (string) app_env('APP_ENV', 'local') !== 'production';
}

function app_oauth_configured(): bool
{
    return (string) app_env('GOOGLE_CLIENT_ID', '') !== '' && (string) app_env('GOOGLE_CLIENT_SECRET', '') !== '';
}

function app_current_user(PDO $db): ?array
{
    app_start_session();
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_numeric($userId)) {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function app_login_user(array $user): void
{
    app_start_session();
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['user_email'] = (string) $user['email'];
}

function app_logout_user(): void
{
    app_start_session();
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);
}

function app_demo_user(PDO $db): array
{
    $stmt = $db->prepare('SELECT * FROM users WHERE google_sub = :google_sub LIMIT 1');
    $stmt->execute(['google_sub' => 'demo-sub']);
    $user = $stmt->fetch();
    if ($user) {
        return $user;
    }

    $insert = $db->prepare(
        'INSERT INTO users (google_sub, email, name, avatar_url, account_display_name, account_bio, role)
         VALUES (:google_sub, :email, :name, :avatar_url, :account_display_name, :account_bio, :role)
         RETURNING *'
    );
    $insert->execute([
        'google_sub' => 'demo-sub',
        'email' => 'demo@1g1a.local',
        'name' => '1G1A Demo',
        'avatar_url' => null,
        'account_display_name' => '1G1A Demo',
        'account_bio' => 'デモ用アカウントです。',
        'role' => 'user',
    ]);

    $user = $insert->fetch();
    if (!$user) {
        throw new RuntimeException('Failed to create demo user.');
    }

    return $user;
}

function app_demo_profile(PDO $db, int $userId): array
{
    $stmt = $db->prepare('SELECT * FROM profiles WHERE public_token = :public_token LIMIT 1');
    $stmt->execute(['public_token' => 'demo-preview-1g1a']);
    $profile = $stmt->fetch();
    if ($profile) {
        return $profile;
    }

    $insert = $db->prepare(
        'INSERT INTO profiles (user_id, slug, public_token, profile_name, display_name, bio, headline, is_public)
         VALUES (:user_id, :slug, :public_token, :profile_name, :display_name, :bio, :headline, :is_public)
         RETURNING *'
    );
    $insert->execute([
        'user_id' => $userId,
        'slug' => 'demo-preview-1g1a',
        'public_token' => 'demo-preview-1g1a',
        'profile_name' => 'デモ用',
        'display_name' => '1G1A Demo Profile',
        'bio' => '1G1A のデモ用共有プロフィールです。',
        'headline' => '出会いのあとで共有するページ',
        'is_public' => true,
    ]);

    $profile = $insert->fetch();
    if (!$profile) {
        throw new RuntimeException('Failed to create demo profile.');
    }

    $website = $db->prepare('SELECT id FROM sns_types WHERE code = :code LIMIT 1');
    $website->execute(['code' => 'website']);
    $snsTypeId = $website->fetchColumn();
    if ($snsTypeId !== false) {
        $link = $db->prepare(
            'INSERT INTO profile_sns (profile_id, sns_type_id, label, url, sort_order, is_primary)
             VALUES (:profile_id, :sns_type_id, :label, :url, :sort_order, :is_primary)
             ON CONFLICT (profile_id, sns_type_id) DO UPDATE
             SET label = EXCLUDED.label,
                 url = EXCLUDED.url,
                 sort_order = EXCLUDED.sort_order,
                 is_primary = EXCLUDED.is_primary'
        );
        $link->execute([
            'profile_id' => (int) $profile['id'],
            'sns_type_id' => (int) $snsTypeId,
            'label' => 'Official site',
            'url' => app_url('/'),
            'sort_order' => 10,
            'is_primary' => true,
        ]);
    }

    return $profile;
}

function app_ensure_starter_profile(PDO $db, int $userId, string $displayName, string $accountName): array
{
    $profiles = app_fetch_profiles($db, $userId);
    if ($profiles !== []) {
        return $profiles[0];
    }

    $base = trim($displayName) !== '' ? trim($displayName) : trim($accountName);
    if ($base === '') {
        $base = 'my-profile';
    }
    $slugBase = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $base) ?? $base);
    $slugBase = trim($slugBase, '-');
    if ($slugBase === '') {
        $slugBase = 'profile';
    }

    $slug = $slugBase . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $publicToken = app_generate_public_token($db);

    $insert = $db->prepare(
        'INSERT INTO profiles (user_id, slug, public_token, profile_name, display_name, bio, headline, is_public)
         VALUES (:user_id, :slug, :public_token, :profile_name, :display_name, :bio, :headline, :is_public)
         RETURNING *'
    );
    $insert->execute([
        'user_id' => $userId,
        'slug' => $slug,
        'public_token' => $publicToken,
        'profile_name' => 'はじめての共有プロフィール',
        'display_name' => $base,
        'bio' => "この共有プロフィールは、初回ログイン時に自動で作成されます。\n会った相手に見せたい情報を、あとから複数追加できます。",
        'headline' => 'QR で見せる共有プロフィール',
        'is_public' => true,
    ]);

    $profile = $insert->fetch();
    if (!$profile) {
        throw new RuntimeException('初期共有プロフィールの作成に失敗しました。');
    }

    return $profile;
}

function app_generate_public_token(PDO $db): string
{
    do {
        $token = bin2hex(random_bytes(16));
        $stmt = $db->prepare('SELECT 1 FROM profiles WHERE public_token = :token UNION SELECT 1 FROM share_events WHERE public_token = :token LIMIT 1');
        $stmt->execute(['token' => $token]);
    } while ($stmt->fetchColumn() !== false);

    return $token;
}

function app_generate_local_token(): string
{
    return 'draft-' . bin2hex(random_bytes(16));
}

function app_fetch_profiles(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT p.*,
                COUNT(DISTINCT ps.id) AS sns_count,
                COUNT(DISTINCT se.id) AS event_count
         FROM profiles p
         LEFT JOIN profile_sns ps ON ps.profile_id = p.id
         LEFT JOIN share_events se ON se.profile_id = p.id
         WHERE p.user_id = :user_id
         GROUP BY p.id
         ORDER BY p.created_at DESC, p.id DESC'
    );
    $stmt->execute(['user_id' => $userId]);

    return $stmt->fetchAll() ?: [];
}

function app_fetch_profile(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM profiles WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $profile = $stmt->fetch();

    return $profile ?: null;
}

function app_fetch_profile_by_public_token(PDO $db, string $publicToken): ?array
{
    $stmt = $db->prepare('SELECT * FROM profiles WHERE public_token = :token LIMIT 1');
    $stmt->execute(['token' => $publicToken]);
    $profile = $stmt->fetch();

    return $profile ?: null;
}

function app_fetch_profile_owner(PDO $db, int $profileId): ?array
{
    $stmt = $db->prepare(
        'SELECT p.id AS profile_id,
                p.display_name AS profile_display_name,
                p.profile_name,
                p.public_token AS profile_public_token,
                u.id AS user_id,
                u.name AS user_name,
                u.email AS user_email
         FROM profiles p
         INNER JOIN users u ON u.id = p.user_id
         WHERE p.id = :profile_id
         LIMIT 1'
    );
    $stmt->execute(['profile_id' => $profileId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function app_fetch_profile_links(PDO $db, int $profileId): array
{
    $stmt = $db->prepare(
        'SELECT ps.*, st.name AS sns_name, st.code AS sns_code
         FROM profile_sns ps
         INNER JOIN sns_types st ON st.id = ps.sns_type_id
         WHERE ps.profile_id = :profile_id
         ORDER BY ps.sort_order ASC, ps.id ASC'
    );
    $stmt->execute(['profile_id' => $profileId]);

    return $stmt->fetchAll() ?: [];
}

function app_fetch_recent_events(PDO $db, int $profileId, int $limit = 5): array
{
    $stmt = $db->prepare(
        'SELECT se.*, p.avatar_url AS profile_avatar_url
         FROM share_events se
         INNER JOIN profiles p ON p.id = se.profile_id
         WHERE se.profile_id = :profile_id
         ORDER BY se.created_at DESC, se.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function app_count_dashboard_events(PDO $db, int $userId): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*)
         FROM share_events se
         INNER JOIN profiles p ON p.id = se.profile_id
         WHERE p.user_id = :user_id
           AND se.status = 'ready'
           AND (se.expires_at IS NULL OR se.expires_at >= CURRENT_TIMESTAMP)"
    );
    $stmt->execute(['user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

function app_fetch_dashboard_events(PDO $db, int $userId, int $limit = 5, int $offset = 0): array
{
    $stmt = $db->prepare(
        "SELECT se.*, p.profile_name, p.display_name AS profile_display_name
         FROM share_events se
         INNER JOIN profiles p ON p.id = se.profile_id
         WHERE p.user_id = :user_id
           AND se.status = 'ready'
           AND (se.expires_at IS NULL OR se.expires_at >= CURRENT_TIMESTAMP)
         ORDER BY se.created_at DESC, se.id DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function app_fetch_share_event_by_token(PDO $db, string $token): ?array
{
    $stmt = $db->prepare(
        'SELECT se.*, p.display_name AS profile_display_name, p.profile_name, p.headline AS profile_headline, p.bio AS profile_bio, p.is_public, p.user_id, p.avatar_url AS profile_avatar_url
         FROM share_events se
         INNER JOIN profiles p ON p.id = se.profile_id
         WHERE se.public_token = :token
         LIMIT 1'
    );
    $stmt->execute(['token' => $token]);
    $event = $stmt->fetch();

    return $event ?: null;
}

function app_fetch_share_event_photos(PDO $db, int $eventId): array
{
    $stmt = $db->prepare('SELECT * FROM share_event_photos WHERE share_event_id = :id ORDER BY id ASC');
    $stmt->execute(['id' => $eventId]);

    return $stmt->fetchAll() ?: [];
}

function app_render_profile_avatar(?string $avatarUrl, string $label, string $className = 'hero-avatar'): string
{
    $avatarUrl = trim((string) $avatarUrl);
    $safeLabel = trim($label);
    $fallback = $safeLabel !== '' ? (function_exists('mb_substr') ? mb_substr($safeLabel, 0, 2, 'UTF-8') : substr($safeLabel, 0, 2)) : '1G';

    if ($avatarUrl !== '') {
        $avatarSize = $className === 'avatar-preview' ? 112 : 140;

        return '<img class="' . app_h($className) . '" src="' . app_h($avatarUrl) . '" alt="' . app_h($safeLabel !== '' ? $safeLabel : 'profile') . '" width="' . $avatarSize . '" height="' . $avatarSize . '">';
    }

    return '<div class="' . app_h($className) . ' hero-avatar-fallback" aria-label="' . app_h($safeLabel !== '' ? $safeLabel : 'profile') . '"><span>' . app_h($fallback) . '</span></div>';
}

function app_store_uploaded_photo(array $file, string $prefix = 'share'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('写真のアップロードに失敗しました。');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException('写真を受け取れませんでした。');
    }

    $info = @getimagesize($tmpName);
    if ($info === false || empty($info['mime'])) {
        throw new InvalidArgumentException('画像ファイルを選んでください。');
    }

    $mime = (string) $info['mime'];
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        default => throw new InvalidArgumentException('対応していない画像形式です。'),
    };

    $storageDir = app_root('storage/uploads');
    if (!is_dir($storageDir) && !mkdir($storageDir, 0777, true) && !is_dir($storageDir)) {
        throw new RuntimeException('画像保存用ディレクトリを作成できませんでした。');
    }

    $filename = sprintf('%s_%s.%s', $prefix, bin2hex(random_bytes(16)), $ext);
    $target = $storageDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('画像の保存に失敗しました。');
    }

    return [
        'storage_path' => 'uploads/' . $filename,
        'mime_type' => $mime,
        'file_size' => (int) ($file['size'] ?? filesize($target)),
        'width' => (int) ($info[0] ?? 0),
        'height' => (int) ($info[1] ?? 0),
    ];
}

function app_normalize_uploaded_files(array $files): array
{
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $normalized = [];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $normalized[] = [
            'name' => $files['name'][$i] ?? null,
            'type' => $files['type'][$i] ?? null,
            'tmp_name' => $files['tmp_name'][$i] ?? null,
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? null,
        ];
    }

    return $normalized;
}

function app_save_share_event(PDO $db, array $user, array $payload): array
{
    $profileId = (int) ($payload['profile_id'] ?? 0);
    $profile = app_fetch_profile($db, $profileId);
    if (!$profile || (int) $profile['user_id'] !== (int) $user['id']) {
        throw new InvalidArgumentException('共有プロフィールを選んでください。');
    }

    $body = trim((string) ($payload['body'] ?? ''));
    $token = trim((string) ($payload['public_token'] ?? ''));
    if ($token === '') {
        $token = app_generate_public_token($db);
    }

    $status = (string) ($payload['status'] ?? 'ready');
    if (!in_array($status, ['pending', 'ready'], true)) {
        $status = 'ready';
    }
    $expiresIn = (string) ($payload['expires_in'] ?? '24h');
    if (!in_array($expiresIn, ['24h', '3d', '7d', '30d'], true)) {
        $expiresIn = '24h';
    }

    $latitude = $payload['latitude'] ?? null;
    $longitude = $payload['longitude'] ?? null;
    $accuracy = $payload['location_accuracy_m'] ?? null;
    $capturedAt = $payload['location_captured_at'] ?? null;

    $exists = $db->prepare('SELECT id FROM share_events WHERE public_token = :token LIMIT 1');
    $exists->execute(['token' => $token]);
    $eventId = $exists->fetchColumn();

    if ($eventId !== false) {
        $stmt = $db->prepare(
            'UPDATE share_events
             SET profile_id = :profile_id,
                 body = :body,
                 status = :status,
                 expires_in = :expires_in,
                 latitude = :latitude,
                 longitude = :longitude,
                 location_accuracy_m = :accuracy,
                 location_captured_at = :captured_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
             RETURNING *'
        );
        $stmt->execute([
            'profile_id' => $profileId,
            'body' => $body,
            'status' => $status,
            'expires_in' => $expiresIn,
            'latitude' => $latitude !== null && $latitude !== '' ? (float) $latitude : null,
            'longitude' => $longitude !== null && $longitude !== '' ? (float) $longitude : null,
            'accuracy' => $accuracy !== null && $accuracy !== '' ? (float) $accuracy : null,
            'captured_at' => $capturedAt !== null && $capturedAt !== '' ? $capturedAt : null,
            'id' => (int) $eventId,
        ]);
        $event = $stmt->fetch();
        if (!$event) {
            throw new RuntimeException('共有イベントの更新に失敗しました。');
        }
    } else {
        $stmt = $db->prepare(
            'INSERT INTO share_events (profile_id, public_token, body, status, expires_in, latitude, longitude, location_accuracy_m, location_captured_at)
             VALUES (:profile_id, :public_token, :body, :status, :expires_in, :latitude, :longitude, :accuracy, :captured_at)
             RETURNING *'
        );
        $stmt->execute([
            'profile_id' => $profileId,
            'public_token' => $token,
            'body' => $body,
            'status' => $status,
            'expires_in' => $expiresIn,
            'latitude' => $latitude !== null && $latitude !== '' ? (float) $latitude : null,
            'longitude' => $longitude !== null && $longitude !== '' ? (float) $longitude : null,
            'accuracy' => $accuracy !== null && $accuracy !== '' ? (float) $accuracy : null,
            'captured_at' => $capturedAt !== null && $capturedAt !== '' ? $capturedAt : null,
        ]);
        $event = $stmt->fetch();
        if (!$event) {
            throw new RuntimeException('共有イベントの作成に失敗しました。');
        }
    }

    $eventId = (int) $event['id'];

    $deletePhotos = $db->prepare('DELETE FROM share_event_photos WHERE share_event_id = :id');
    $deletePhotos->execute(['id' => $eventId]);

    if (isset($payload['photos']) && is_array($payload['photos'])) {
        $photoStmt = $db->prepare(
            'INSERT INTO share_event_photos (share_event_id, storage_path, mime_type, file_size, width, height)
             VALUES (:share_event_id, :storage_path, :mime_type, :file_size, :width, :height)'
        );

        foreach ($payload['photos'] as $photoFile) {
            if (!is_array($photoFile)) {
                continue;
            }

            $stored = app_store_uploaded_photo($photoFile, 'share_event');
            $photoStmt->execute([
                'share_event_id' => $eventId,
                'storage_path' => $stored['storage_path'],
                'mime_type' => $stored['mime_type'],
                'file_size' => $stored['file_size'],
                'width' => $stored['width'],
                'height' => $stored['height'],
            ]);
        }
    }

    if (isset($payload['reserve_token']) && is_string($payload['reserve_token']) && $payload['reserve_token'] !== '') {
        app_use_reserved_token($db, (int) $user['id'], $profileId, (string) $payload['reserve_token']);
    }

    return $event;
}

function app_reserve_share_tokens(PDO $db, int $userId, int $profileId, int $count = 3): array
{
    $profile = app_fetch_profile($db, $profileId);
    if (!$profile || (int) $profile['user_id'] !== $userId) {
        throw new InvalidArgumentException('共有プロフィールが見つかりません。');
    }

    $count = max(1, min($count, 10));
    $tokens = [];
    for ($i = 0; $i < $count; $i++) {
        $tokens[] = app_generate_local_token();
    }

    $insert = $db->prepare(
        "INSERT INTO reserved_share_tokens (user_id, profile_id, public_token, status, reserved_at, expires_at)
         VALUES (:user_id, :profile_id, :public_token, 'available', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP + INTERVAL '7 days')
         ON CONFLICT (public_token) DO NOTHING
         RETURNING *"
    );

    $rows = [];
    foreach ($tokens as $token) {
        $insert->execute([
            'user_id' => $userId,
            'profile_id' => $profileId,
            'public_token' => $token,
        ]);
        $row = $insert->fetch();
        if ($row) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function app_fetch_reserved_tokens(PDO $db, int $userId, int $profileId): array
{
    $stmt = $db->prepare(
        "SELECT *
         FROM reserved_share_tokens
         WHERE user_id = :user_id AND profile_id = :profile_id AND status = 'available'
         ORDER BY reserved_at DESC, id DESC"
    );
    $stmt->execute([
        'user_id' => $userId,
        'profile_id' => $profileId,
    ]);

    return $stmt->fetchAll() ?: [];
}

function app_use_reserved_token(PDO $db, int $userId, int $profileId, string $token): void
{
    $stmt = $db->prepare(
        "UPDATE reserved_share_tokens
         SET status = 'used', used_at = CURRENT_TIMESTAMP
         WHERE user_id = :user_id AND profile_id = :profile_id AND public_token = :token"
    );
    $stmt->execute([
        'user_id' => $userId,
        'profile_id' => $profileId,
        'token' => $token,
    ]);
}

function app_normalize_guest_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

    return $name;
}

function app_valid_guest_name(string $name): bool
{
    return $name !== '' && preg_match('/^[\p{L}\p{N}\p{P}\p{S}\p{Zs}]{1,40}$/u', $name) === 1;
}

function app_guest_token_from_cookie(): ?string
{
    $token = $_COOKIE['1g1a_guest_token'] ?? null;

    return is_string($token) && trim($token) !== '' ? trim($token) : null;
}

function app_guest_name_from_cookie(): ?string
{
    $name = $_COOKIE['1g1a_guest_name'] ?? null;

    return is_string($name) && trim($name) !== '' ? trim($name) : null;
}

function app_set_guest_identity_cookie(string $token, string $name): void
{
    $options = [
        'expires' => time() + 60 * 60 * 24 * 365,
        'path' => '/',
        'secure' => filter_var(app_env('SESSION_SECURE', false), FILTER_VALIDATE_BOOL),
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    setcookie('1g1a_guest_token', $token, $options);
    setcookie('1g1a_guest_name', $name, $options);
}

function app_upsert_guest_visitor(PDO $db, string $token, string $name): void
{
    $stmt = $db->prepare(
        'INSERT INTO guest_visitors (viewer_token, display_name, first_seen_at, last_seen_at)
         VALUES (:viewer_token, :display_name, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
         ON CONFLICT (viewer_token)
         DO UPDATE SET display_name = EXCLUDED.display_name, last_seen_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'viewer_token' => $token,
        'display_name' => $name,
    ]);
}

function app_store_guest_message(PDO $db, int $profileId, ?int $shareEventId, string $guestName, string $message, string $recipientEmail): void
{
    $stmt = $db->prepare(
        'INSERT INTO guest_messages (profile_id, share_event_id, guest_name, message, recipient_email, created_at)
         VALUES (:profile_id, :share_event_id, :guest_name, :message, :recipient_email, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        'profile_id' => $profileId,
        'share_event_id' => $shareEventId,
        'guest_name' => $guestName,
        'message' => $message,
        'recipient_email' => $recipientEmail,
    ]);
}

function app_send_guest_message_notification(PDO $db, array $event, string $guestName, string $message): bool
{
    $profileId = (int) ($event['profile_id'] ?? 0);
    if ($profileId <= 0) {
        return false;
    }

    $owner = app_fetch_profile_owner($db, $profileId);
    $recipientEmail = trim((string) ($owner['user_email'] ?? ''));
    if ($recipientEmail === '') {
        return false;
    }

    $profileDisplayName = trim((string) ($event['profile_display_name'] ?? $owner['profile_display_name'] ?? $owner['profile_name'] ?? ''));
    $shareToken = trim((string) ($event['public_token'] ?? ''));
    $shareUrl = $shareToken !== '' ? app_url('/s/' . $shareToken) : app_url('/dashboard');
    $subject = '【1G1A】' . ($profileDisplayName !== '' ? $profileDisplayName : 'プロフィール') . ' にメッセージ';
    if (function_exists('mb_encode_mimeheader')) {
        $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }

    $body = implode("\r\n", [
        '1G1A に新しいメッセージが届きました。',
        '',
        '送信者名: ' . $guestName,
        'メッセージ: ' . $message,
        '送信先: ' . ($profileDisplayName !== '' ? $profileDisplayName : '(unknown profile)'),
        '送信URL: ' . $shareUrl,
        '送信時刻: ' . gmdate('Y-m-d H:i:s') . ' UTC',
    ]);

    $fromAddress = (string) app_env('MAIL_FROM_ADDRESS', 'no-reply@1g1a.local');
    $fromName = (string) app_env('MAIL_FROM_NAME', '1G1A');
    $headers = [
        'From: ' . $fromName . ' <' . $fromAddress . '>',
        'Reply-To: ' . $fromAddress,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    return mail($recipientEmail, $subject, $body, implode("\r\n", $headers));
}

function app_log_share_access(PDO $db, int $eventId, int $profileId, ?string $viewerToken): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $hash = $ip !== '' ? hash('sha256', $ip) : null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $db->prepare(
        'INSERT INTO share_access_logs (share_event_id, profile_id, viewer_token, access_ip_hash, user_agent, accessed_at)
         VALUES (:share_event_id, :profile_id, :viewer_token, :access_ip_hash, :user_agent, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        'share_event_id' => $eventId,
        'profile_id' => $profileId,
        'viewer_token' => $viewerToken,
        'access_ip_hash' => $hash,
        'user_agent' => $userAgent,
    ]);
}

function app_share_event_expiration_interval(string $expiresIn): string
{
    return match ($expiresIn) {
        '3d' => '3 days',
        '7d' => '7 days',
        '30d' => '30 days',
        default => '24 hours',
    };
}

function app_touch_share_event_first_access(PDO $db, int $eventId): ?array
{
    $stmt = $db->prepare(
        "UPDATE share_events
         SET first_accessed_at = COALESCE(first_accessed_at, CURRENT_TIMESTAMP),
             expires_at = COALESCE(expires_at, CURRENT_TIMESTAMP + ((CASE expires_in
                 WHEN '3d' THEN '3 days'
                 WHEN '7d' THEN '7 days'
                 WHEN '30d' THEN '30 days'
                 ELSE '24 hours'
             END)::interval)),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id
         RETURNING id"
    );
    $stmt->execute(['id' => $eventId]);
    $updatedEventId = $stmt->fetchColumn();
    if ($updatedEventId === false) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT se.*, p.display_name AS profile_display_name, p.profile_name,
                p.headline AS profile_headline, p.bio AS profile_bio,
                p.is_public, p.user_id, p.avatar_url AS profile_avatar_url
         FROM share_events se
         INNER JOIN profiles p ON p.id = se.profile_id
         WHERE se.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => (int) $updatedEventId]);
    $event = $stmt->fetch();

    return $event ?: null;
}

function app_share_event_expired(array $event): bool
{
    $expiresAt = $event['expires_at'] ?? null;
    if (!is_string($expiresAt) || $expiresAt === '') {
        return false;
    }

    return strtotime($expiresAt) !== false && strtotime($expiresAt) < time();
}

function app_save_share_location(PDO $db, int $eventId, float $latitude, float $longitude, ?float $accuracy): void
{
    $stmt = $db->prepare(
        'UPDATE share_events
         SET latitude = :latitude,
             longitude = :longitude,
             location_accuracy_m = :accuracy,
             location_captured_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy' => $accuracy,
        'id' => $eventId,
    ]);
}

function app_store_uploads_path(): string
{
    $path = app_root('storage/uploads');
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }

    return $path;
}

function app_render(string $title, string $body, array $context = []): void
{
    $siteName = (string) app_env('APP_NAME', '1G1A');
    $flash = app_flash();
    $user = $context['user'] ?? null;
    $meta = $context['meta'] ?? '';
    $chrome = array_key_exists('chrome', $context) ? (bool) $context['chrome'] : true;
    $showFlash = array_key_exists('flash', $context) ? (bool) $context['flash'] : true;
    $cssVersion = (string) (filemtime(app_root('public/assets/app.css')) ?: time());
    $jsVersion = (string) (filemtime(app_root('public/assets/app.js')) ?: time());

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . app_h($title) . ' | ' . app_h($siteName) . '</title>';
    echo '<meta name="description" content="1G1A の共有プロフィールサービス">';
    if (is_string($meta) && $meta !== '') {
        echo $meta;
    }
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="manifest" href="' . app_h(app_url('/manifest.webmanifest')) . '">';
    echo '<meta name="theme-color" content="#0f5b66">';
    echo '<link rel="stylesheet" href="' . app_h(app_url('/assets/app.css?v=' . $cssVersion)) . '">';
    echo '<script defer src="' . app_h(app_url('/assets/app.js?v=' . $jsVersion)) . '"></script>';
    echo '</head><body>';
    echo '<div class="shell">';
    if ($chrome) {
        echo '<header class="topbar">';
        echo '<a class="brand" href="' . app_h(app_url('/')) . '"><span class="brand-mark">1G1A</span><span class="brand-text">共有ページ</span></a>';
        echo '<nav class="nav">';
        if ($user) {
            echo '<a href="' . app_h(app_url('/dashboard')) . '">Home</a>';
            echo '<a href="' . app_h(app_url('/logout')) . '">Logout</a>';
        } else {
            echo '<a href="' . app_h(app_url('/')) . '">Home</a>';
            echo '<a href="' . app_h(app_url('/login')) . '">Login</a>';
        }
        echo '</nav>';
        if ($user) {
            echo '<div class="user-chip">' . app_h((string) ($user['account_display_name'] ?? $user['name'] ?? 'User')) . '</div>';
        }
        echo '</header>';
    }
    if ($flash && $showFlash) {
        echo '<div class="flash">' . app_h($flash) . '</div>';
    }
    echo $body;
    echo '</div>';
    echo '</body></html>';
}
