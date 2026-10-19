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

final class AppDatabaseSessionHandler implements SessionHandlerInterface
{
    private ?string $lockedSessionKey = null;

    public function __construct(private PDO $db) {}

    public function available(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM app_sessions LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool
    {
        if ($this->lockedSessionKey === null) return true;
        try {
            $stmt = $this->db->prepare('SELECT pg_advisory_unlock(hashtextextended(:session_key, 0))');
            $stmt->execute(['session_key' => $this->lockedSessionKey]);
        } catch (Throwable) {
            return false;
        } finally {
            $this->lockedSessionKey = null;
        }
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $sessionKey = hash('sha256', $id);
            $lock = $this->db->prepare('SELECT pg_advisory_lock(hashtextextended(:session_key, 0))');
            $lock->execute(['session_key' => $sessionKey]);
            $this->lockedSessionKey = $sessionKey;
            $stmt = $this->db->prepare('SELECT session_data FROM app_sessions WHERE session_key = :session_key AND expires_at > CURRENT_TIMESTAMP');
            $stmt->execute(['session_key' => $sessionKey]);
            $data = $stmt->fetchColumn();
            if (is_string($data)) return $data;

            // Migrate an existing PHP file session on its first request after this handler is enabled.
            $legacyPath = $this->legacySessionFilePath($id);
            if ($legacyPath !== null && is_file($legacyPath)) {
                $legacyData = @file_get_contents($legacyPath);
                if (is_string($legacyData)) return $legacyData;
            }
            return '';
        } catch (Throwable) {
            $legacyPath = $this->legacySessionFilePath($id);
            $legacyData = $legacyPath !== null ? @file_get_contents($legacyPath) : false;
            return is_string($legacyData) ? $legacyData : '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $lifetime = max(60, (int) ini_get('session.gc_maxlifetime'));
            $stmt = $this->db->prepare(
                'INSERT INTO app_sessions (session_key, session_data, expires_at, updated_at)
                 VALUES (:session_key, :data, CURRENT_TIMESTAMP + make_interval(secs => CAST(:lifetime AS DOUBLE PRECISION)), CURRENT_TIMESTAMP)
                 ON CONFLICT (session_key) DO UPDATE SET
                    session_data = EXCLUDED.session_data,
                    expires_at = EXCLUDED.expires_at,
                    updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute(['session_key' => hash('sha256', $id), 'data' => $data, 'lifetime' => $lifetime]);
            $legacyPath = $this->legacySessionFilePath($id);
            if ($legacyPath !== null && is_file($legacyPath)) @unlink($legacyPath);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM app_sessions WHERE session_key = :session_key');
            $stmt->execute(['session_key' => hash('sha256', $id)]);
            $legacyPath = $this->legacySessionFilePath($id);
            if ($legacyPath !== null && is_file($legacyPath)) @unlink($legacyPath);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM app_sessions WHERE expires_at <= CURRENT_TIMESTAMP');
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable) {
            return false;
        }
    }

    private function legacySessionFilePath(string $id): ?string
    {
        if (preg_match('/\A[A-Za-z0-9,-]+\z/', $id) !== 1) return null;
        $settings = explode(';', (string) ini_get('session.save_path'));
        $path = (string) end($settings);
        if ($path === '') return null;
        $depth = count($settings) > 1 ? max(0, min(5, (int) $settings[0])) : 0;
        for ($i = 0; $i < $depth; $i++) $path .= DIRECTORY_SEPARATOR . $id[$i];
        return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $id;
    }
}

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Keep server-side session data available long enough for an installed PWA.
    // Regular browser sessions still use a session cookie and end when the browser closes.
    ini_set('session.gc_maxlifetime', (string) (90 * 24 * 60 * 60));
    ini_set('session.lazy_write', '0');
    session_name((string) app_env('SESSION_NAME', '1g1a_session'));
    $pwaSession = ($_COOKIE['1g1a_pwa_session'] ?? '') === '1' || ($_SERVER['HTTP_X_1G1A_PWA'] ?? '') === '1';
    session_set_cookie_params([
        'lifetime' => $pwaSession ? 90 * 24 * 60 * 60 : 0,
        'path' => '/',
        'domain' => '',
        'secure' => filter_var(app_env('SESSION_SECURE', false), FILTER_VALIDATE_BOOL),
        'httponly' => filter_var(app_env('SESSION_HTTP_ONLY', true), FILTER_VALIDATE_BOOL),
        'samesite' => (string) app_env('SESSION_SAME_SITE', 'Lax'),
    ]);
    if ($pwaSession) {
        $sessionDb = app_db();
    } else {
        $sessionDb = null;
    }
    if ($sessionDb !== null) {
        $handler = new AppDatabaseSessionHandler($sessionDb);
        if ($handler->available()) session_set_save_handler($handler, true);
    }
    session_start();
}

function app_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    $path = app_root('VERSION');
    $value = is_file($path) ? trim((string) file_get_contents($path)) : '';
    $version = preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $value) === 1 ? $value : '0.0.1';

    return $version;
}

function app_info_updated_at(): string
{
    static $updatedAt = null;
    if ($updatedAt !== null) {
        return $updatedAt;
    }

    $path = app_root('UPDATED_AT');
    $value = is_file($path) ? trim((string) file_get_contents($path)) : '';
    if ($value === '') {
        $updatedAt = '';
        return $updatedAt;
    }

    try {
        $updatedAt = (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
    } catch (Throwable) {
        $updatedAt = '';
    }

    return $updatedAt;
}

function app_info_updated_at_label(): string
{
    $updatedAt = app_info_updated_at();
    if ($updatedAt === '') {
        return '日時未設定';
    }

    return (new DateTimeImmutable($updatedAt))
        ->setTimezone(new DateTimeZone('Asia/Tokyo'))
        ->format('Y/m/d H:i') . ' JST';
}

function app_guest_skin_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }

    $options = [];
    $directory = app_root('public/skins/guest');
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $skinDirectory) {
        $id = basename($skinDirectory);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) !== 1) {
            continue;
        }
        $manifestPath = $skinDirectory . DIRECTORY_SEPARATOR . 'skin.json';
        $cssPath = $skinDirectory . DIRECTORY_SEPARATOR . 'skin.css';
        if (!is_file($manifestPath) || !is_file($cssPath)) {
            continue;
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || ($manifest['id'] ?? '') !== $id || !is_string($manifest['name'] ?? null)) {
            continue;
        }
        $options[$id] = [
            'id' => $id,
            'name' => $manifest['name'],
            'description' => is_string($manifest['description'] ?? null) ? $manifest['description'] : '',
            'preview' => is_file($skinDirectory . DIRECTORY_SEPARATOR . 'preview.svg')
                ? app_url('/skins/guest/' . $id . '/preview.svg?v=' . rawurlencode(app_version()))
                : '',
        ];
    }
    uksort($options, static function (string $left, string $right): int {
        $order = ['light' => 0, 'dark' => 1];
        return ($order[$left] ?? 2) <=> ($order[$right] ?? 2) ?: strcmp($left, $right);
    });

    return $options;
}

function app_guest_skin_id(mixed $id): string
{
    $options = app_guest_skin_options();
    if (is_string($id) && isset($options[$id])) {
        return $id;
    }
    if (isset($options['light'])) {
        return 'light';
    }

    return (string) (array_key_first($options) ?? 'light');
}

function app_enable_pwa_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        app_start_session();
    }

    $params = session_get_cookie_params();
    $expires = time() + 90 * 24 * 60 * 60;
    setcookie(session_name(), session_id(), [
        'expires' => $expires,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?: 'Lax',
    ]);
    setcookie('1g1a_pwa_session', '1', [
        'expires' => $expires,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => true,
        'samesite' => $params['samesite'] ?: 'Lax',
    ]);
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

function app_login_user(PDO $db, array $user): void
{
    app_start_session();
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['user_email'] = (string) $user['email'];
    app_purge_expired_share_events($db, (int) $user['id']);
}

function app_purge_expired_share_events(PDO $db, int $userId): int
{
    $storagePaths = [];
    $deletedCount = 0;

    try {
        $db->beginTransaction();
        $expiredEvents = $db->prepare(
            "SELECT se.id, photo.storage_path
             FROM share_events se
             INNER JOIN profiles p ON p.id = se.profile_id
             LEFT JOIN share_event_photos photo ON photo.share_event_id = se.id
             WHERE p.user_id = :user_id
               AND se.expires_at IS NOT NULL
               AND se.expires_at < CURRENT_TIMESTAMP - INTERVAL '30 days'
             ORDER BY se.id
             FOR UPDATE OF se"
        );
        $expiredEvents->execute(['user_id' => $userId]);
        $eventIds = [];
        foreach ($expiredEvents->fetchAll() as $row) {
            $eventIds[(int) $row['id']] = true;
            if (is_string($row['storage_path'] ?? null) && $row['storage_path'] !== '') {
                $storagePaths[] = $row['storage_path'];
            }
        }

        if ($eventIds !== []) {
            $deleteMessages = $db->prepare(
                "DELETE FROM guest_messages gm
                 USING share_events se
                 INNER JOIN profiles p ON p.id = se.profile_id
                 WHERE gm.share_event_id = se.id
                   AND p.user_id = :user_id
                   AND se.expires_at IS NOT NULL
                   AND se.expires_at < CURRENT_TIMESTAMP - INTERVAL '30 days'"
            );
            $deleteMessages->execute(['user_id' => $userId]);

            $deleteEvents = $db->prepare(
                "DELETE FROM share_events se
                 USING profiles p
                 WHERE p.id = se.profile_id
                   AND p.user_id = :user_id
                   AND se.expires_at IS NOT NULL
                   AND se.expires_at < CURRENT_TIMESTAMP - INTERVAL '30 days'"
            );
            $deleteEvents->execute(['user_id' => $userId]);
            $deletedCount = $deleteEvents->rowCount();
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Expired share event cleanup failed: ' . $error->getMessage());
        return 0;
    }

    $uploadsDirectory = realpath(app_root('storage/uploads'));
    if ($uploadsDirectory !== false) {
        $uploadsPrefix = rtrim($uploadsDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach (array_unique($storagePaths) as $storagePath) {
            if (preg_match('#\Auploads/share_event_[a-f0-9]{32}\.(?:jpg|png|webp|gif)\z#i', $storagePath) !== 1) {
                continue;
            }
            $filePath = realpath(app_root('storage/' . $storagePath));
            if ($filePath === false || !str_starts_with($filePath, $uploadsPrefix) || !is_file($filePath)) {
                continue;
            }
            if (!@unlink($filePath)) {
                error_log('Unable to delete expired share event photo file.');
            }
        }
    }

    return $deletedCount;
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
        if (trim((string) ($user['avatar_url'] ?? '')) === '') {
            $update = $db->prepare('UPDATE users SET avatar_url = :avatar_url, updated_at = CURRENT_TIMESTAMP WHERE id = :id RETURNING *');
            $update->execute([
                'avatar_url' => app_random_default_avatar_url(),
                'id' => (int) $user['id'],
            ]);
            $updated = $update->fetch();

            return $updated ?: $user;
        }

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
        'avatar_url' => app_random_default_avatar_url(),
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
        'SELECT ps.*, st.name AS sns_name, st.code AS sns_code,
                COALESCE(st.display_name, st.name) AS service_display_name,
                st.icon_url, st.category, st.input_kind, st.copy_template, st.url_template
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

function app_fetch_share_event_history(PDO $db, int $userId, ?int $profileId = null): array
{
    $profileFilter = $profileId === null ? '' : ' AND p.id = :profile_id';
    $stmt = $db->prepare(
        "SELECT se.*, p.profile_name, p.display_name AS profile_display_name
         FROM share_events se
         INNER JOIN profiles p ON p.id = se.profile_id
         WHERE p.user_id = :user_id
           AND se.status = 'ready'" . $profileFilter . "
         ORDER BY se.created_at DESC, se.id DESC"
    );
    $params = ['user_id' => $userId];
    if ($profileId !== null) {
        $params['profile_id'] = $profileId;
    }
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function app_count_profile_share_events(PDO $db, int $profileId): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM share_events WHERE profile_id = :profile_id AND status = 'ready'");
    $stmt->execute(['profile_id' => $profileId]);

    return (int) $stmt->fetchColumn();
}

function app_fetch_share_event_by_token(PDO $db, string $token): ?array
{
    $stmt = $db->prepare(
        'SELECT se.*, p.display_name AS profile_display_name, p.profile_name, p.headline AS profile_headline, p.bio AS profile_bio, p.is_public, p.guest_skin_id, p.user_id, p.avatar_url AS profile_avatar_url,
                u.avatar_url AS account_avatar_url, u.account_display_name AS account_display_name, u.name AS account_name
         FROM share_events se
         INNER JOIN profiles p ON p.id = se.profile_id
         INNER JOIN users u ON u.id = p.user_id
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

function app_default_avatar_asset_url(int $index): string
{
    $index = max(1, min(10, $index));

    return app_url('/assets/default-avatar-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '.svg');
}

function app_random_default_avatar_url(): string
{
    return app_default_avatar_asset_url(random_int(1, 10));
}

function app_default_avatar_url(string $seed): string
{
    $index = (abs((int) crc32($seed !== '' ? $seed : '1g1a')) % 10) + 1;

    return app_default_avatar_asset_url($index);
}

function app_account_avatar_url(array $user): string
{
    $avatarUrl = trim((string) ($user['avatar_url'] ?? ''));
    if ($avatarUrl !== '') {
        return $avatarUrl;
    }

    $seed = (string) ($user['id'] ?? $user['email'] ?? $user['name'] ?? '1g1a');

    return app_default_avatar_url($seed);
}

function app_profile_display_avatar_url(array $profileOrEvent, ?array $user = null): string
{
    $profileAvatarUrl = trim((string) ($profileOrEvent['avatar_url'] ?? $profileOrEvent['profile_avatar_url'] ?? ''));
    if ($profileAvatarUrl !== '') {
        return $profileAvatarUrl;
    }

    if ($user !== null) {
        return app_account_avatar_url($user);
    }

    $accountAvatarUrl = trim((string) ($profileOrEvent['account_avatar_url'] ?? ''));
    if ($accountAvatarUrl !== '') {
        return $accountAvatarUrl;
    }

    $seed = (string) ($profileOrEvent['user_id'] ?? $profileOrEvent['profile_id'] ?? $profileOrEvent['public_token'] ?? '1g1a');

    return app_default_avatar_url($seed);
}

function app_render_profile_avatar(?string $avatarUrl, string $label, string $className = 'hero-avatar'): string
{
    $avatarUrl = trim((string) $avatarUrl);
    $safeLabel = trim($label);
    $fallback = $safeLabel !== '' ? (function_exists('mb_substr') ? mb_substr($safeLabel, 0, 2, 'UTF-8') : substr($safeLabel, 0, 2)) : '1G';

    if ($avatarUrl !== '') {
        $avatarSize = match ($className) {
            'avatar-preview' => 112,
            'avatar-mini' => 44,
            'qr-center-avatar' => 64,
            default => 140,
        };

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
        $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $normalized[] = [
            'name' => $files['name'][$i] ?? null,
            'type' => $files['type'][$i] ?? null,
            'tmp_name' => $files['tmp_name'][$i] ?? null,
            'error' => $error,
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
    $token = app_generate_public_token($db);
    $expiresIn = (string) ($payload['expires_in'] ?? '24h');
    if (!in_array($expiresIn, ['24h', '3d', '7d', '30d'], true)) {
        $expiresIn = '24h';
    }

    $attachLocationRaw = $payload['attach_location'] ?? null;
    $attachLocation = !array_key_exists('attach_location', $payload)
        || in_array(strtolower(trim((string) $attachLocationRaw)), ['1', 'true', 'on', 'yes'], true);

    $latitude = $payload['latitude'] ?? null;
    $longitude = $payload['longitude'] ?? null;
    $accuracy = $payload['location_accuracy_m'] ?? null;
    $capturedAt = $payload['location_captured_at'] ?? null;
    if (!$attachLocation) {
        $latitude = $longitude = $accuracy = $capturedAt = null;
    }

    $stmt = $db->prepare(
        "INSERT INTO share_events (profile_id, public_token, body, status, expires_in, attach_location, latitude, longitude, location_accuracy_m, location_captured_at)
         VALUES (:profile_id, :public_token, :body, 'ready', :expires_in, :attach_location, :latitude, :longitude, :accuracy, :captured_at)
         RETURNING *"
    );
    $stmt->execute([
        'profile_id' => $profileId,
        'public_token' => $token,
        'body' => $body,
        'expires_in' => $expiresIn,
        'attach_location' => $attachLocation ? 'true' : 'false',
        'latitude' => $latitude !== null && $latitude !== '' ? (float) $latitude : null,
        'longitude' => $longitude !== null && $longitude !== '' ? (float) $longitude : null,
        'accuracy' => $accuracy !== null && $accuracy !== '' ? (float) $accuracy : null,
        'captured_at' => $capturedAt !== null && $capturedAt !== '' ? $capturedAt : null,
    ]);
    $event = $stmt->fetch();
    if (!$event) {
        throw new RuntimeException('共有イベントの作成に失敗しました。');
    }

    $eventId = (int) $event['id'];

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

    return $event;
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

function app_guest_identity_cookie_suffix(string $shareEventToken): string
{
    return sha1($shareEventToken);
}

function app_guest_token_from_cookie(string $shareEventToken): ?string
{
    $token = $_COOKIE['1g1a_guest_token_' . app_guest_identity_cookie_suffix($shareEventToken)] ?? null;

    return is_string($token) && trim($token) !== '' ? trim($token) : null;
}

function app_guest_name_from_cookie(string $shareEventToken): ?string
{
    $name = $_COOKIE['1g1a_guest_name_' . app_guest_identity_cookie_suffix($shareEventToken)] ?? null;

    return is_string($name) && trim($name) !== '' ? trim($name) : null;
}

function app_guest_identity_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => filter_var(app_env('SESSION_SECURE', false), FILTER_VALIDATE_BOOL),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function app_set_guest_identity_cookie(string $shareEventToken, string $token, string $name): void
{
    $suffix = app_guest_identity_cookie_suffix($shareEventToken);
    $options = app_guest_identity_cookie_options(time() + 60 * 60 * 24 * 365);

    setcookie('1g1a_guest_token_' . $suffix, $token, $options);
    setcookie('1g1a_guest_name_' . $suffix, $name, $options);
}

function app_clear_guest_identity_cookie(string $shareEventToken): void
{
    $suffix = app_guest_identity_cookie_suffix($shareEventToken);
    $options = app_guest_identity_cookie_options(time() - 3600);

    setcookie('1g1a_guest_token_' . $suffix, '', $options);
    setcookie('1g1a_guest_name_' . $suffix, '', $options);
    unset($_COOKIE['1g1a_guest_token_' . $suffix], $_COOKIE['1g1a_guest_name_' . $suffix]);

    setcookie('1g1a_guest_token', '', $options);
    setcookie('1g1a_guest_name', '', $options);
    unset($_COOKIE['1g1a_guest_token'], $_COOKIE['1g1a_guest_name']);
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

function app_fetch_guest_messages(PDO $db, int $shareEventId, string $guestName, int $limit = 10): array
{
    $stmt = $db->prepare(
        'SELECT guest_name, message, created_at
         FROM guest_messages
         WHERE share_event_id = :share_event_id AND guest_name = :guest_name
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':share_event_id', $shareEventId, PDO::PARAM_INT);
    $stmt->bindValue(':guest_name', $guestName);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function app_fetch_share_event_messages(PDO $db, int $shareEventId, int $limit = 50): array
{
    $stmt = $db->prepare(
        'SELECT guest_name, message, created_at
         FROM guest_messages
         WHERE share_event_id = :share_event_id
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':share_event_id', $shareEventId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
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
                p.is_public, p.user_id, p.avatar_url AS profile_avatar_url,
                u.avatar_url AS account_avatar_url, u.account_display_name AS account_display_name, u.name AS account_name
         FROM share_events se
         INNER JOIN profiles p ON p.id = se.profile_id
         INNER JOIN users u ON u.id = p.user_id
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
    $footerContent = $context['footer'] ?? '';
    $chrome = array_key_exists('chrome', $context) ? (bool) $context['chrome'] : true;
    $showFlash = array_key_exists('flash', $context) ? (bool) $context['flash'] : true;
    $qrFooter = !empty($context['qr_footer']);
    $guestSkin = isset($context['guest_skin']) ? app_guest_skin_id($context['guest_skin']) : '';
    $dashboardSkin = $guestSkin !== '' && !empty($context['dashboard_skin']);
    $version = app_version();
    $cssVersion = $version;
    $jsVersion = $version;

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . app_h($title) . ' | ' . app_h($siteName) . '</title>';
    echo '<meta name="description" content="1G1A の共有プロフィールサービス">';
    echo '<meta name="1g1a-app-version" content="' . app_h($version) . '">';
    echo '<meta name="1g1a-info-updated-at" content="' . app_h(app_info_updated_at()) . '">';
    if (is_string($meta) && $meta !== '') {
        echo $meta;
    }
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="manifest" href="' . app_h(app_url('/manifest.webmanifest')) . '">';
    echo '<link rel="icon" type="image/svg+xml" href="' . app_h(app_url('/assets/icon.svg?v=' . rawurlencode($version))) . '">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="' . app_h(app_url('/assets/apple-touch-icon.png?v=' . rawurlencode($version))) . '">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">';
    echo '<meta name="theme-color" content="#155c70">';
    echo '<link rel="stylesheet" href="' . app_h(app_url('/assets/app.css?v=' . $cssVersion)) . '">';
    if ($guestSkin !== '') {
        echo '<link' . ($dashboardSkin ? ' id="dashboard-skin-stylesheet"' : '') . ' rel="stylesheet" href="' . app_h(app_url('/skins/guest/' . $guestSkin . '/skin.css?v=' . rawurlencode($cssVersion))) . '">';
    }
    echo '<script defer src="' . app_h(app_url('/assets/app.js?v=' . $jsVersion)) . '"></script>';
    $bodyClasses = [];
    if ($guestSkin !== '') {
        $bodyClasses[] = 'guest-skin-theme';
        $bodyClasses[] = 'guest-skin-theme-' . $guestSkin;
    }
    if ($dashboardSkin) {
        $bodyClasses[] = 'guest-skin-dashboard';
    }
    echo '</head><body' . ($bodyClasses !== [] ? ' class="' . app_h(implode(' ', $bodyClasses)) . '"' : '') . '>';
    echo '<div class="shell">';
    if ($chrome) {
        echo '<header class="topbar">';
        if ($user) {
            echo '<details class="brand-menu"><summary class="brand brand-menu-trigger" aria-label="1G1A メニュー" title="1G1A メニュー"><span class="brand-mark">1G1A</span><span class="brand-text">共有ページ</span><i class="fa-solid fa-chevron-down brand-menu-caret" aria-hidden="true"></i></summary><nav class="brand-menu-panel" aria-label="アプリメニュー"><ul><li><a href="' . app_h(app_url('/dashboard')) . '">QR作成</a></li><li><a href="' . app_h(app_url('/share-events')) . '">共有イベント</a></li><li><a href="' . app_h(app_url('/profiles')) . '">共有プロフィール</a></li><li><a href="' . app_h(app_url('/account')) . '">基本プロフィール</a></li><li><a href="' . app_h(app_url('/news')) . '">新着情報</a></li><li><a href="' . app_h(app_url('/help')) . '">使い方</a></li><li><a href="' . app_h(app_url('/pwa/install')) . '" data-pwa-install>ホーム画面に追加</a></li><li><a href="' . app_h(app_url('/logout')) . '">ログアウト</a></li></ul><span class="app-version">v' . app_h($version) . '</span></nav></details>';
        } else {
            echo '<a class="brand" href="' . app_h(app_url('/')) . '"><span class="brand-mark">1G1A</span><span class="brand-text">共有ページ</span></a>';
        }
        if (!$user) {
            echo '<nav class="nav">';
            echo "<a class=\"icon-nav\" href=\"/\" aria-label=\"Home\" title=\"Home\"><i class=\"fa-solid fa-house\" aria-hidden=\"true\"></i></a>";
            echo '</nav>';
        }
        if ($user) {
            $accountName = (string) ($user['account_display_name'] ?? $user['name'] ?? 'User');
            echo '<a class="user-chip" href="' . app_h(app_url('/account')) . '" aria-label="アカウント情報">' . app_render_profile_avatar(app_account_avatar_url($user), $accountName, 'avatar-mini') . '</a>';
        }
        echo '</header>';
    }
    if ($flash && $showFlash) {
        echo '<div class="flash">' . app_h($flash) . '</div>';
    }
    echo $body;
    echo '<footer class="app-footer' . ($qrFooter ? ' app-footer-qr' : '') . '">' . (is_string($footerContent) ? $footerContent : '') . '<span>1G1A v' . app_h($version) . '</span><span>情報更新: ' . app_h(app_info_updated_at_label()) . '</span></footer>';
    echo '</div>';
    echo '</body></html>';
}
