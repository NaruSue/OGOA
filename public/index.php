<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

app_start_session();

$db = app_db();
$path = app_detect_request_path();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/healthz') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ok';
    exit;
}

if ($path === '/' && $method === 'GET') {
    app_render('Home', render_home());
    exit;
}

if ($path === '/login' && $method === 'GET') {
    app_render('Login', render_login($db));
    exit;
}

if ($path === '/auth/google' && $method === 'POST' && isset($_POST['demo'])) {
    app_require_csrf();
    if ($db === null) {
        app_flash('DB が使えないためデモログインできません。');
        app_redirect('/login');
    }

    $user = app_demo_user($db);
    app_demo_profile($db, (int) $user['id']);
    app_login_user($user);
    app_flash('デモでログインしました。');
    app_redirect('/dashboard');
}

if ($path === '/auth/google' && $method === 'GET') {
    if (!app_oauth_configured()) {
        app_redirect('/login');
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $query = http_build_query([
        'client_id' => (string) app_env('GOOGLE_CLIENT_ID'),
        'redirect_uri' => (string) app_env('GOOGLE_REDIRECT_URI', app_url('/auth/google/callback')),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'include_granted_scopes' => 'true',
        'prompt' => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query);
    exit;
}

if ($path === '/auth/google/callback' && $method === 'GET') {
    if (!app_oauth_configured() || $db === null) {
        app_flash('Google OAuth が未設定です。');
        app_redirect('/login');
    }

    $state = $_GET['state'] ?? '';
    $code = $_GET['code'] ?? '';
    $storedState = $_SESSION['oauth_state'] ?? '';
    unset($_SESSION['oauth_state']);

    if (!is_string($state) || !is_string($code) || $state === '' || $code === '' || !is_string($storedState) || !hash_equals($storedState, $state)) {
        app_flash('Google 認証に失敗しました。');
        app_redirect('/login');
    }

    $tokenResponse = app_google_token_exchange($code);
    if ($tokenResponse === null || empty($tokenResponse['access_token'])) {
        app_flash('Google のトークン交換に失敗しました。');
        app_redirect('/login');
    }

    $profile = app_google_userinfo((string) $tokenResponse['access_token']);
    if ($profile === null || empty($profile['sub']) || empty($profile['email']) || empty($profile['name'])) {
        app_flash('Google のユーザー情報を取得できませんでした。');
        app_redirect('/login');
    }

    $user = app_upsert_user_from_google($db, $profile);
    app_ensure_starter_profile($db, (int) $user['id'], (string) $user['name'], (string) ($user['account_display_name'] ?? $user['name']));
    app_login_user($user);
    app_flash('ログインしました。');
    app_redirect('/dashboard');
}

if ($path === '/logout') {
    app_logout_user();
    app_flash('ログアウトしました。');
    app_redirect('/');
}

if ($path === '/dashboard') {
    render_dashboard($db);
    exit;
}

if ($path === '/account') {
    render_account($db, $method);
    exit;
}

if ($path === '/profiles/new') {
    render_profile_form($db, $method, null);
    exit;
}

if (preg_match('#^/profiles/(\d+)/edit$#', $path, $matches) === 1) {
    render_profile_form($db, $method, (int) $matches[1]);
    exit;
}

if (preg_match('#^/profiles/(\d+)/qr$#', $path, $matches) === 1) {
    render_profile_qr($db, (int) $matches[1]);
    exit;
}

if (preg_match('#^/profiles/(\d+)$#', $path, $matches) === 1) {
    render_profile_detail($db, (int) $matches[1]);
    exit;
}

if ($path === '/share-tokens/reserve' && $method === 'POST') {
    api_reserve_tokens($db);
    exit;
}

if ($path === '/share-events/create' && $method === 'POST') {
    handle_share_event_save($db, false);
    exit;
}

if (preg_match('#^/share-events/(\d+)/location$#', $path, $matches) === 1 && $method === 'POST') {
    api_share_event_location($db, (int) $matches[1]);
    exit;
}

if (preg_match('#^/media/([A-Za-z0-9._-]+)$#', $path, $matches) === 1 && $method === 'GET') {
    serve_media($db, $matches[1]);
    exit;
}

if (preg_match('#^/s/([A-Za-z0-9_-]{12,80})$#', $path, $matches) === 1) {
    render_share_event($db, (string) $matches[1], $method);
    exit;
}

if (preg_match('#^/p/([A-Za-z0-9_-]{12,80})$#', $path, $matches) === 1) {
    render_public_profile($db, (string) $matches[1]);
    exit;
}

http_response_code(404);
app_render('Not found', '<section class="page narrow"><div class="card"><h1>ページが見つかりません</h1></div></section>');

function app_google_token_exchange(string $code): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $payload = http_build_query([
        'code' => $code,
        'client_id' => (string) app_env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => (string) app_env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => (string) app_env('GOOGLE_REDIRECT_URI', app_url('/auth/google/callback')),
        'grant_type' => 'authorization_code',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        return null;
    }

    $json = json_decode($response, true);

    return is_array($json) ? $json : null;
}

function app_google_userinfo(string $accessToken): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        return null;
    }

    $json = json_decode($response, true);

    return is_array($json) ? $json : null;
}

function app_upsert_user_from_google(PDO $db, array $profile): array
{
    $stmt = $db->prepare('SELECT * FROM users WHERE google_sub = :google_sub LIMIT 1');
    $stmt->execute(['google_sub' => (string) $profile['sub']]);
    $user = $stmt->fetch();
    if ($user) {
        $update = $db->prepare(
            'UPDATE users
             SET email = :email,
                 name = :name,
                 avatar_url = :avatar_url,
                 account_display_name = COALESCE(account_display_name, :display_name),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
             RETURNING *'
        );
        $update->execute([
            'email' => (string) $profile['email'],
            'name' => (string) $profile['name'],
            'avatar_url' => $profile['picture'] ?? null,
            'display_name' => (string) $profile['name'],
            'id' => (int) $user['id'],
        ]);
        $updated = $update->fetch();

        return $updated ?: $user;
    }

    $insert = $db->prepare(
        'INSERT INTO users (google_sub, email, name, avatar_url, account_display_name, role)
         VALUES (:google_sub, :email, :name, :avatar_url, :account_display_name, :role)
         RETURNING *'
    );
    $insert->execute([
        'google_sub' => (string) $profile['sub'],
        'email' => (string) $profile['email'],
        'name' => (string) $profile['name'],
        'avatar_url' => $profile['picture'] ?? null,
        'account_display_name' => (string) $profile['name'],
        'role' => 'user',
    ]);

    $user = $insert->fetch();
    if (!$user) {
        throw new RuntimeException('ユーザーの作成に失敗しました。');
    }

    return $user;
}

function render_home(): string
{
    ob_start();
    ?>
<section class="hero">
  <div class="hero-copy">
    <p class="eyebrow">Meet and share</p>
    <h1>その場で見せたいプロフィールを、QRひとつで。</h1>
    <p class="lead">1G1A は、会った人に合わせて共有プロフィールを選び、メッセージや写真と一緒に QR で渡すためのサービスです。</p>
    <div class="hero-actions">
      <a class="button primary" href="<?= app_h(app_url('/login')) ?>">ログイン</a>
      <a class="button secondary" href="<?= app_h(app_url('/p/demo-preview-1g1a')) ?>">デモを見る</a>
    </div>
  </div>
  <div class="hero-card">
    <div class="stats">
      <div class="stat"><strong>QR</strong><span>見せるだけ</span></div>
      <div class="stat"><strong>Profile</strong><span>用途別に複数</span></div>
      <div class="stat"><strong>Guest</strong><span>初回だけ名前入力</span></div>
    </div>
    <div class="panel">
      <h2>できること</h2>
      <ul>
        <li>アカウントプロフィール管理</li>
        <li>共有プロフィールの複数運用</li>
        <li>コメント・写真をその場で追加</li>
        <li>QR表示時の位置情報保存</li>
        <li>ゲストの名前を Cookie と DB に保存</li>
      </ul>
    </div>
  </div>
</section>
    <?php
    return (string) ob_get_clean();
}

function render_login(?PDO $db): string
{
    $csrf = app_h(app_csrf_token());
    $googleUrl = app_h(app_url('/auth/google'));
    ob_start();
    ?>
<section class="page narrow">
  <div class="card">
    <p class="eyebrow">Login</p>
    <h1>ログイン</h1>
    <p>このサービスは、会った相手に見せたい共有プロフィールを QR で渡すためのものです。ログイン後、初回は基本の共有プロフィールを1つ自動作成します。</p>
    <p>Google アカウントで入るか、ローカルのデモで試せます。</p>
    <?php if (app_oauth_configured()): ?>
      <p><a class="button primary" href="<?= $googleUrl ?>">Google でログイン</a></p>
    <?php else: ?>
      <div class="notice">Google OAuth の環境変数が未設定です。</div>
    <?php endif; ?>
    <?php if ($db !== null && app_supports_demo_login()): ?>
      <form method="post" action="<?= $googleUrl ?>" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="demo" value="1">
        <button class="button secondary" type="submit">デモで入る</button>
      </form>
    <?php endif; ?>
  </div>
</section>
    <?php
    return (string) ob_get_clean();
}

function render_dashboard(?PDO $db): void
{
    if ($db === null) {
        app_render('Dashboard', '<section class="page narrow"><div class="card"><h1>DB に接続できません</h1><p>DATABASE_URL を確認してください。</p></div></section>');
        return;
    }

    $user = app_current_user($db);
    if (!$user) {
        app_flash('先にログインしてください。');
        app_redirect('/login');
    }

    $profiles = app_fetch_profiles($db, (int) $user['id']);
    if ($profiles === []) {
        app_ensure_starter_profile(
            $db,
            (int) $user['id'],
            (string) ($user['account_display_name'] ?? $user['name']),
            (string) $user['name']
        );
        $profiles = app_fetch_profiles($db, (int) $user['id']);
    }

    $profileOptions = '';
    $profileCards = '';
    foreach ($profiles as $index => $profile) {
        $profileId = (int) $profile['id'];
        $profileOptions .= '<option value="' . $profileId . '"' . ($index === 0 ? ' selected' : '') . '>' . app_h((string) $profile['profile_name']) . ' - ' . app_h((string) $profile['display_name']) . '</option>';
        $recentEvents = app_fetch_recent_events($db, $profileId, 2);
        $eventInfo = $recentEvents !== [] ? count($recentEvents) . ' 件の共有イベント' : 'まだ共有イベントはありません';
        $profileCards .= '<article class="list-card"><div><p class="eyebrow">' . app_h((string) $profile['profile_name']) . '</p><h3>' . app_h((string) $profile['display_name']) . '</h3><p>' . app_h((string) ($profile['headline'] ?? '')) . '</p><p class="muted">' . app_h($eventInfo) . '</p></div><div class="list-meta"><span class="status-pill ' . (((bool) $profile['is_public']) ? 'public' : 'private') . '">' . (((bool) $profile['is_public']) ? '公開中' : '非公開') . '</span><span>' . (int) ($profile['sns_count'] ?? 0) . ' SNS</span><div class="list-actions"><a class="button secondary" href="' . app_h(app_url('/profiles/' . $profileId)) . '">詳細</a><a class="button secondary" href="' . app_h(app_url('/profiles/' . $profileId . '/edit')) . '">編集</a></div></div></article>';
    }

    $firstProfileId = $profiles !== [] ? (int) $profiles[0]['id'] : 0;
    $reserveCount = $profiles !== [] ? count(app_fetch_reserved_tokens($db, (int) $user['id'], $firstProfileId)) : 0;
    $statusLine = $reserveCount > 0 ? '予約済みトークン ' . $reserveCount . ' 件' : '予約済みトークンはまだありません';
    $createAction = app_h(app_url('/share-events/create'));
    $csrf = app_h(app_csrf_token());
    $newProfileUrl = app_h(app_url('/profiles/new'));
    $accountUrl = app_h(app_url('/account'));
    $logoutUrl = app_h(app_url('/logout'));
    $profileHint = $profiles !== [] ? 'まず共有プロフィールを選んでください。' : '共有プロフィールがまだありません。';

    ob_start();
    ?>
<section class="dashboard-grid">
  <div class="launch-headline">
    <p class="eyebrow">Your sharing desk</p>
    <h1>見せたい共有プロフィールを選んで、QR を公開。</h1>
    <p class="lead">1. プロフィールを選ぶ 2. 必要なら一言と写真を足す 3. QRを公開。スマホでそのまま使えるよう、最短の流れにしています。</p>
    <div class="chip-row">
      <span class="guest-badge"><?= app_h($statusLine) ?></span>
    </div>
  </div>
  <div class="card launch-card">
    <div class="publish-steps" aria-label="publish steps">
      <div class="publish-step active">
        <span>1</span>
        <div><strong>共有プロフィールを選ぶ</strong><p><?= app_h($profileHint) ?></p></div>
      </div>
      <div class="publish-step">
        <span>2</span>
        <div><strong>一言と写真を足す</strong><p>必要なときだけ、あとから確認してもらう内容を追加します。</p></div>
      </div>
      <div class="publish-step">
        <span>3</span>
        <div><strong>QR を公開する</strong><p>公開すると、その場で見せる画面に切り替わります。</p></div>
      </div>
    </div>
    <form method="post" action="<?= $createAction ?>" enctype="multipart/form-data" class="form" id="share-launcher">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <label>表示する共有プロフィール
        <select name="profile_id" id="profile-select" required>
          <?= $profileOptions ?>
        </select>
      </label>
      <label>今日のメッセージ <span class="optional">任意</span>
        <textarea name="body" id="share-body" rows="4" maxlength="2000" placeholder="今日はありがとうございました。"></textarea>
      </label>
      <label class="upload-field">写真を追加 <span class="optional">複数可 / カメラ起動可</span>
        <input type="file" name="photos[]" id="share-photos" accept="image/jpeg,image/png,image/webp,image/gif" capture="environment" multiple>
        <span class="upload-note">写真は通信できないときに端末内に一時保存されます。</span>
      </label>
      <div class="launch-actions">
        <button class="button secondary" type="submit">下書きを保存</button>
        <button class="button primary" type="submit" data-publish="1">QRを公開</button>
      </div>
      <div class="draft-banner" id="draft-status"><?= app_h($statusLine) ?></div>
    </form>
  </div>
</section>

<section class="page">
  <div class="section-head">
    <div>
      <p class="eyebrow">Share profiles</p>
      <h2>共有プロフィール</h2>
    </div>
    <a class="button secondary" href="<?= $newProfileUrl ?>">＋ 追加</a>
  </div>
  <div class="grid">
    <?= $profileCards ?>
  </div>
</section>

<section class="page narrow">
  <div class="card">
    <p class="eyebrow">Account profile</p>
    <h2>アカウントプロフィール</h2>
    <p>このアカウントにひとつだけある基本プロフィールです。共有プロフィールの土台になります。</p>
    <div class="section-actions">
      <a class="button secondary" href="<?= $accountUrl ?>">編集する</a>
      <a class="button secondary" href="<?= $logoutUrl ?>">ログアウト</a>
    </div>
  </div>
</section>
    <?php
    app_render('Dashboard', (string) ob_get_clean(), ['user' => $user]);
}

function render_account(?PDO $db, string $method): void
{
    if ($db === null) {
        app_render('Account', '<section class="page narrow"><div class="card"><h1>DB に接続できません</h1></div></section>');
        return;
    }

    $user = app_current_user($db);
    if (!$user) {
        app_redirect('/login');
    }

    if ($method === 'POST') {
        app_require_csrf();
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $bio = trim((string) ($_POST['bio'] ?? ''));
        if ($displayName === '' || mb_strlen($displayName) > 120 || mb_strlen($bio) > 1000) {
            app_flash('入力内容を確認してください。');
            app_redirect('/account');
        }
        $stmt = $db->prepare('UPDATE users SET account_display_name = :display_name, account_bio = :bio, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            'display_name' => $displayName,
            'bio' => $bio !== '' ? $bio : null,
            'id' => (int) $user['id'],
        ]);
        app_flash('アカウントプロフィールを更新しました。');
        app_redirect('/dashboard');
    }

    $displayName = trim((string) ($user['account_display_name'] ?? '')) ?: (string) $user['name'];
    $bio = (string) ($user['account_bio'] ?? '');
    $csrf = app_h(app_csrf_token());
    ob_start();
    ?>
<section class="page narrow">
  <div class="card">
    <p class="eyebrow">Account profile</p>
    <h1>アカウントプロフィール</h1>
    <p>アカウントにひとつだけある基本情報です。共有プロフィールの初期値として使います。</p>
    <form method="post" class="form">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <label>表示名
        <input name="display_name" required maxlength="120" value="<?= app_h($displayName) ?>">
      </label>
      <label>自己紹介
        <textarea name="bio" rows="5" maxlength="1000"><?= app_h($bio) ?></textarea>
      </label>
      <div class="notice">メールアドレスや Google 情報は公開ページには出ません。</div>
      <button class="button primary" type="submit">保存する</button>
    </form>
  </div>
</section>
    <?php
    app_render('Account', (string) ob_get_clean(), ['user' => $user]);
}

function render_profile_form(?PDO $db, string $method, ?int $profileId): void
{
    if ($db === null) {
        app_render('Profile', '<section class="page narrow"><div class="card"><h1>DB に接続できません</h1></div></section>');
        return;
    }

    $user = app_current_user($db);
    if (!$user) {
        app_redirect('/login');
    }

    $profile = $profileId !== null ? app_fetch_profile($db, $profileId) : null;
    if ($profileId !== null && (!$profile || (int) $profile['user_id'] !== (int) $user['id'])) {
        http_response_code(404);
        app_render('Not found', '<section class="page narrow"><div class="card"><h1>共有プロフィールが見つかりません</h1></div></section>', ['user' => $user]);
        return;
    }

    if ($method === 'POST') {
        app_require_csrf();
        $profileName = trim((string) ($_POST['profile_name'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $headline = trim((string) ($_POST['headline'] ?? ''));
        $bio = trim((string) ($_POST['bio'] ?? ''));
        $isPublic = isset($_POST['is_public']) && $_POST['is_public'] === '1';

        if ($profileName === '' || $displayName === '') {
            app_flash('共有プロフィール名と表示名を入れてください。');
            app_redirect($profileId === null ? '/profiles/new' : '/profiles/' . $profileId . '/edit');
        }

        if ($profile === null) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $profileName) ?? $profileName);
            $slug = trim($slug, '-');
            if ($slug === '') {
                $slug = 'profile-' . substr(bin2hex(random_bytes(4)), 0, 8);
            } else {
                $slug .= '-' . substr(bin2hex(random_bytes(4)), 0, 8);
            }

            $stmt = $db->prepare('INSERT INTO profiles (user_id, slug, public_token, profile_name, display_name, bio, headline, is_public) VALUES (:user_id, :slug, :public_token, :profile_name, :display_name, :bio, :headline, :is_public)');
            $stmt->execute([
                'user_id' => (int) $user['id'],
                'slug' => $slug,
                'public_token' => app_generate_public_token($db),
                'profile_name' => $profileName,
                'display_name' => $displayName,
                'bio' => $bio !== '' ? $bio : null,
                'headline' => $headline !== '' ? $headline : null,
                'is_public' => $isPublic,
            ]);
            app_flash('共有プロフィールを作成しました。');
            app_redirect('/dashboard');
        }

        $stmt = $db->prepare('UPDATE profiles SET profile_name = :profile_name, display_name = :display_name, headline = :headline, bio = :bio, is_public = :is_public, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            'profile_name' => $profileName,
            'display_name' => $displayName,
            'headline' => $headline !== '' ? $headline : null,
            'bio' => $bio !== '' ? $bio : null,
            'is_public' => $isPublic,
            'id' => (int) $profile['id'],
        ]);
        app_flash('共有プロフィールを更新しました。');
        app_redirect('/profiles/' . (string) $profile['id']);
    }

    $defaultDisplay = (string) ($user['account_display_name'] ?? $user['name'] ?? '');
    $profileName = $profile ? (string) $profile['profile_name'] : '';
    $displayName = $profile ? (string) $profile['display_name'] : $defaultDisplay;
    $headline = $profile ? (string) ($profile['headline'] ?? '') : '';
    $bio = $profile ? (string) ($profile['bio'] ?? '') : '';
    $checked = $profile === null || (bool) $profile['is_public'] ? ' checked' : '';
    $title = $profile === null ? '共有プロフィールを作成' : '共有プロフィールを編集';
    $button = $profile === null ? '作成する' : '更新する';
    $csrf = app_h(app_csrf_token());
    ob_start();
    ?>
<section class="page narrow">
  <div class="card">
    <p class="eyebrow">Share profile</p>
    <h1><?= app_h($title) ?></h1>
    <p>1アカウントで複数持てる公開プロフィールです。用途ごとに分けて使えます。</p>
    <form method="post" class="form">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <label>共有プロフィール名
        <input name="profile_name" required maxlength="120" value="<?= app_h($profileName) ?>" placeholder="仕事用 / 友人用 / 旅先用">
      </label>
      <label>公開表示名
        <input name="display_name" required maxlength="120" value="<?= app_h($displayName) ?>">
      </label>
      <label>見出し
        <input name="headline" maxlength="160" value="<?= app_h($headline) ?>" placeholder="旅と写真が好きです">
      </label>
      <label>紹介文
        <textarea name="bio" rows="5" maxlength="1000"><?= app_h($bio) ?></textarea>
      </label>
      <label class="checkbox">
        <input type="checkbox" name="is_public" value="1"<?= $checked ?>> 公開する
      </label>
      <button class="button primary" type="submit"><?= app_h($button) ?></button>
    </form>
  </div>
</section>
    <?php
    app_render($title, (string) ob_get_clean(), ['user' => $user]);
}

function render_profile_detail(?PDO $db, int $profileId): void
{
    if ($db === null) {
        app_render('Profile', '<section class="page narrow"><div class="card"><h1>DB に接続できません</h1></div></section>');
        return;
    }

    $user = app_current_user($db);
    if (!$user) {
        app_redirect('/login');
    }

    $profile = app_fetch_profile($db, $profileId);
    if (!$profile || (int) $profile['user_id'] !== (int) $user['id']) {
        http_response_code(404);
        app_render('Not found', '<section class="page narrow"><div class="card"><h1>共有プロフィールが見つかりません</h1></div></section>', ['user' => $user]);
        return;
    }

    $links = app_fetch_profile_links($db, $profileId);
    $events = app_fetch_recent_events($db, $profileId, 6);
    $linkItems = '';
    foreach ($links as $link) {
        $linkItems .= '<a class="social-link" href="' . app_h((string) $link['url']) . '" target="_blank" rel="noreferrer"><span>' . app_h((string) $link['sns_name']) . '</span><strong>' . app_h((string) ($link['label'] ?? $link['sns_name'])) . '</strong></a>';
    }
    $eventItems = '';
    foreach ($events as $event) {
        $eventItems .= '<article class="timeline-item">';
        $eventItems .= '<p class="eyebrow">Share event</p>';
        $eventItems .= '<p>' . nl2br(app_h((string) ($event['body'] ?? ''))) . '</p>';
        $photos = app_fetch_share_event_photos($db, (int) $event['id']);
        if ($photos !== []) {
            $eventItems .= '<div class="photo-grid">';
            foreach ($photos as $photo) {
                $eventItems .= '<img src="' . app_h(app_url('/media/' . basename((string) $photo['storage_path']))) . '" alt="共有写真" loading="lazy">';
            }
            $eventItems .= '</div>';
        }
        $eventItems .= '</article>';
    }
    $shareUrl = app_h(app_url('/p/' . (string) $profile['public_token']));
    ob_start();
    ?>
<section class="page">
  <div class="section-head">
    <div>
      <p class="eyebrow">Share profile</p>
      <h1><?= app_h((string) $profile['display_name']) ?></h1>
      <p><?= app_h((string) ($profile['headline'] ?? '')) ?></p>
    </div>
    <div class="section-actions">
      <a class="button secondary" href="<?= app_h(app_url('/profiles/' . $profileId . '/edit')) ?>">編集</a>
      <a class="button primary" href="<?= app_h(app_url('/profiles/' . $profileId . '/qr')) ?>">QR</a>
    </div>
  </div>
  <div class="two-col">
    <div class="card">
      <h2>公開内容</h2>
      <p><?= nl2br(app_h((string) ($profile['bio'] ?? ''))) ?></p>
      <p><strong>公開URL:</strong> <a href="<?= $shareUrl ?>"><?= $shareUrl ?></a></p>
      <p><strong>状態:</strong> <?= (bool) $profile['is_public'] ? '公開中' : '非公開' ?></p>
    </div>
    <div class="card">
      <h2>SNS・リンク</h2>
      <div class="social-list">
        <?= $linkItems !== '' ? $linkItems : '<div class="empty">まだリンクがありません。</div>' ?>
      </div>
    </div>
  </div>
  <div class="card">
    <h2>最近の共有イベント</h2>
    <div class="timeline">
      <?= $eventItems !== '' ? $eventItems : '<div class="empty">まだ共有イベントがありません。</div>' ?>
    </div>
  </div>
</section>
    <?php
    app_render((string) $profile['display_name'], (string) ob_get_clean(), ['user' => $user]);
}

function render_profile_qr(?PDO $db, int $profileId): void
{
    if ($db === null) {
        app_render('QR', '<section class="page narrow"><div class="card"><h1>DB に接続できません</h1></div></section>');
        return;
    }

    $user = app_current_user($db);
    if (!$user) {
        app_redirect('/login');
    }

    $profile = app_fetch_profile($db, $profileId);
    if (!$profile || (int) $profile['user_id'] !== (int) $user['id']) {
        http_response_code(404);
        app_render('Not found', '<section class="page narrow"><div class="card"><h1>共有プロフィールが見つかりません</h1></div></section>', ['user' => $user]);
        return;
    }

    $requestedEventId = isset($_GET['event']) ? (int) $_GET['event'] : 0;
    $requestedToken = isset($_GET['token']) && is_string($_GET['token']) ? trim((string) $_GET['token']) : '';
    $draftMode = isset($_GET['draft']) && $_GET['draft'] === '1';

    $event = null;
    if ($requestedEventId > 0) {
        $stmt = $db->prepare('SELECT se.*, p.user_id, p.display_name AS profile_display_name, p.headline AS profile_headline, p.bio AS profile_bio FROM share_events se INNER JOIN profiles p ON p.id = se.profile_id WHERE se.id = :id AND p.id = :profile_id LIMIT 1');
        $stmt->execute([
            'id' => $requestedEventId,
            'profile_id' => $profileId,
        ]);
        $event = $stmt->fetch() ?: null;
    }
    if ($event === null && $requestedEventId === 0 && $requestedToken !== '') {
        $event = app_fetch_share_event_by_token($db, $requestedToken);
        if ($event !== null && (int) $event['profile_id'] !== $profileId) {
            $event = null;
        }
    }
    if ($event === null && $requestedEventId === 0 && $requestedToken === '') {
        $recent = app_fetch_recent_events($db, $profileId, 1);
        if ($recent !== []) {
            $event = $recent[0];
        }
    }

    $token = $requestedToken !== '' ? $requestedToken : ($event ? (string) $event['public_token'] : '');
    $shareUrl = $token !== '' ? app_url('/s/' . $token) : app_url('/dashboard');
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&margin=12&data=' . rawurlencode($shareUrl);
    $noticeJa = $draftMode && $event === null
        ? 'まだ通信が復帰していないので、下書きQRを表示しています。復帰後に自動で公開されます。'
        : 'このQRは写真で撮っておくと、あとからアクセスできます。';
    $noticeEn = $draftMode && $event === null
        ? 'This is a draft QR while the connection is weak. It will be published automatically after sync.'
        : 'Please take a photo of this QR so you can visit my profile later.';
    $messageJa = (string) ($event['body'] ?? '');
    $messageEn = $messageJa !== ''
        ? 'You can read my social links and message later from this QR.'
        : 'No message yet. You can still take a photo of this QR and visit later.';
    ob_start();
    ?>
<section class="page narrow">
  <div class="card qr-card">
    <p class="eyebrow">Share this profile</p>
    <h1><?= app_h((string) $profile['display_name']) ?></h1>
    <div class="qr-copy">
      <p><?= app_h($noticeJa) ?></p>
      <p class="muted"><?= app_h($noticeEn) ?></p>
    </div>
    <div class="message-box">
      <div class="message-head">
        <span class="guest-badge">Message</span>
      </div>
      <p><?= nl2br(app_h($messageJa)) ?: 'まだメッセージはありません。' ?></p>
      <p class="muted"><?= app_h($messageEn) ?></p>
    </div>
    <div class="qr-frame">
      <img src="<?= $qrUrl ?>" alt="QR code">
    </div>
    <div class="qr-url">
      <span>URL</span>
      <a href="<?= app_h($shareUrl) ?>"><?= app_h($shareUrl) ?></a>
    </div>
    <div class="notice">位置情報は、QRを公開するときに許可された場合だけ保存します。</div>
  </div>
</section>
    <?php
    app_render(
        'QR',
        (string) ob_get_clean(),
        [
            'user' => $user,
            'meta' => '<script>window.__QR_EVENT_ID=' . json_encode($event ? (int) $event['id'] : null) . ';window.__QR_EVENT_TOKEN=' . json_encode($token) . ';</script>',
        ]
    );
}

function api_reserve_tokens(?PDO $db): void
{
    if ($db === null) {
        app_json(['error' => 'db_unavailable'], 503);
    }
    $user = app_current_user($db);
    if (!$user) {
        app_json(['error' => 'unauthorized'], 401);
    }
    app_require_csrf();
    $payload = $_POST;
    $profileId = (int) ($payload['profile_id'] ?? 0);
    $count = (int) ($payload['count'] ?? 3);
    $rows = app_reserve_share_tokens($db, (int) $user['id'], $profileId, $count);
    app_json(['reserved_tokens' => array_map(static fn ($row): string => (string) $row['public_token'], $rows)]);
}

function handle_share_event_save(?PDO $db, bool $syncMode): void
{
    if ($db === null) {
        app_json(['error' => 'db_unavailable'], 503);
    }
    $user = app_current_user($db);
    if (!$user) {
        app_json(['error' => 'unauthorized'], 401);
    }

    $payload = $_POST;
    $photos = [];
    if (!empty($_FILES['photos'])) {
        $photos = app_normalize_uploaded_files($_FILES['photos']);
    }
    $payload['photos'] = $photos;

    try {
        $event = app_save_share_event($db, $user, $payload);
        $publicUrl = app_url('/s/' . (string) $event['public_token']);
        if (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json')) {
            app_json(['ok' => true, 'event_id' => (int) $event['id'], 'public_token' => (string) $event['public_token'], 'public_url' => $publicUrl]);
        }
        app_flash($syncMode ? '共有イベントを同期しました。' : 'QR を公開しました。');
        app_redirect('/s/' . (string) $event['public_token']);
    } catch (Throwable $e) {
        if (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json')) {
            app_json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        app_flash($e->getMessage());
        app_redirect('/dashboard');
    }
}

function api_share_event_location(?PDO $db, int $eventId): void
{
    if ($db === null) {
        app_json(['error' => 'db_unavailable'], 503);
    }
    $user = app_current_user($db);
    if (!$user) {
        app_json(['error' => 'unauthorized'], 401);
    }
    $event = app_fetch_share_event_by_token($db, (string) ($_POST['public_token'] ?? ''));
    if (!$event || (int) $event['user_id'] !== (int) $user['id']) {
        $stmt = $db->prepare('SELECT se.*, p.user_id FROM share_events se INNER JOIN profiles p ON p.id = se.profile_id WHERE se.id = :id LIMIT 1');
        $stmt->execute(['id' => $eventId]);
        $event = $stmt->fetch();
        if (!$event || (int) $event['user_id'] !== (int) $user['id']) {
            app_json(['error' => 'not_found'], 404);
        }
    }
    $latitude = (float) ($_POST['latitude'] ?? 0);
    $longitude = (float) ($_POST['longitude'] ?? 0);
    $accuracy = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? (float) $_POST['accuracy'] : null;
    app_save_share_location($db, $eventId, $latitude, $longitude, $accuracy);
    app_json(['ok' => true]);
}

function serve_media(?PDO $db, string $filename): void
{
    if ($db === null) {
        http_response_code(404);
        exit;
    }
    $storagePath = 'uploads/' . $filename;
    $stmt = $db->prepare('SELECT mime_type FROM share_event_photos WHERE storage_path = :storage_path LIMIT 1');
    $stmt->execute(['storage_path' => $storagePath]);
    $mimeType = $stmt->fetchColumn();
    $file = app_root('storage/' . $storagePath);
    if (!is_string($mimeType) || !is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $mimeType);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
}

function render_share_event(?PDO $db, string $token, string $method): void
{
    if ($db === null) {
        app_render('Shared page', '<section class="page narrow"><div class="card"><h1>DB に接続できません</h1></div></section>');
        return;
    }

    $event = app_fetch_share_event_by_token($db, $token);
    if ($event === null) {
        app_render('準備中', render_preparing_page($token));
        return;
    }

    if ($method === 'POST') {
        app_require_csrf();
        $guestName = app_normalize_guest_name((string) ($_POST['guest_name'] ?? ''));
        if (!app_valid_guest_name($guestName)) {
            app_flash('名前を入力してください。');
            app_redirect('/s/' . $token);
        }

        $viewerToken = app_guest_token_from_cookie() ?? bin2hex(random_bytes(16));
        app_upsert_guest_visitor($db, $viewerToken, $guestName);
        app_set_guest_identity_cookie($viewerToken, $guestName);
        app_log_share_access($db, (int) $event['id'], (int) $event['profile_id'], $viewerToken);
        app_redirect('/s/' . $token);
    }

    if ((string) ($event['status'] ?? 'ready') !== 'ready') {
        app_render('準備中', render_preparing_page($token));
        return;
    }

    $viewerToken = app_guest_token_from_cookie();
    $guestName = app_guest_name_from_cookie();
    if ($viewerToken !== null && $guestName === null) {
        $stmt = $db->prepare('SELECT display_name FROM guest_visitors WHERE viewer_token = :viewer_token LIMIT 1');
        $stmt->execute(['viewer_token' => $viewerToken]);
        $stored = $stmt->fetchColumn();
        if (is_string($stored) && $stored !== '') {
            $guestName = $stored;
        }
    }

    if ($guestName === null) {
        app_render((string) $event['profile_display_name'], render_guest_name_screen($event, $token));
        return;
    }

    app_log_share_access($db, (int) $event['id'], (int) $event['profile_id'], $viewerToken);
    $photos = app_fetch_share_event_photos($db, (int) $event['id']);
    $links = app_fetch_profile_links($db, (int) $event['profile_id']);
    app_render((string) $event['profile_display_name'], render_guest_profile_screen($event, $guestName, $photos, $links), ['meta' => '<script>window.__QR_EVENT_ID=' . json_encode((int) $event['id']) . ';</script>']);
}

function render_public_profile(?PDO $db, string $token): void
{
    if ($db === null) {
        app_render('Public profile', '<section class="page narrow"><div class="card"><h1>DB に接続できません</h1></div></section>');
        return;
    }

    $profile = app_fetch_profile_by_public_token($db, $token);
    if (!$profile && $token === 'demo-preview-1g1a') {
        $demoUser = app_demo_user($db);
        $profile = app_demo_profile($db, (int) $demoUser['id']);
    }
    if (!$profile) {
        http_response_code(404);
        app_render('Not found', '<section class="page narrow"><div class="card"><h1>公開プロフィールが見つかりません</h1></div></section>');
        return;
    }

    $links = app_fetch_profile_links($db, (int) $profile['id']);
    $events = app_fetch_recent_events($db, (int) $profile['id'], 5);
    $eventHtml = '';
    foreach ($events as $event) {
        $eventHtml .= '<article class="timeline-item"><p class="eyebrow">Share event</p><p>' . nl2br(app_h((string) ($event['body'] ?? ''))) . '</p></article>';
    }
    $linkHtml = '';
    foreach ($links as $link) {
        $linkHtml .= '<a class="social-link" href="' . app_h((string) $link['url']) . '" target="_blank" rel="noreferrer"><span>' . app_h((string) $link['sns_name']) . '</span><strong>' . app_h((string) ($link['label'] ?? $link['sns_name'])) . '</strong></a>';
    }

    ob_start();
    ?>
<section class="public-hero">
  <div class="hero-copy">
    <p class="eyebrow">Shared page</p>
    <h1><?= app_h((string) $profile['display_name']) ?></h1>
    <p class="lead"><?= app_h((string) ($profile['headline'] ?? '')) ?></p>
    <p><?= nl2br(app_h((string) ($profile['bio'] ?? ''))) ?></p>
  </div>
  <div class="hero-card">
    <div class="guest-card">
      <p class="eyebrow">Public profile</p>
      <h2>共有プロフィール</h2>
      <p>相手に合わせて見せたい情報だけをまとめた公開ページです。</p>
    </div>
  </div>
</section>
<section class="dashboard-grid">
  <div class="card">
    <h2>SNS・リンク</h2>
    <div class="social-list">
      <?= $linkHtml !== '' ? $linkHtml : '<div class="empty">まだリンクがありません。</div>' ?>
    </div>
  </div>
  <div class="card">
    <h2>最近の共有イベント</h2>
    <div class="timeline">
      <?= $eventHtml !== '' ? $eventHtml : '<div class="empty">まだ共有イベントがありません。</div>' ?>
    </div>
  </div>
</section>
<section class="page narrow">
  <div class="card">
    <p><a href="<?= app_h(app_url('/login')) ?>">管理画面へ</a></p>
  </div>
</section>
    <?php
    app_render((string) $profile['display_name'], (string) ob_get_clean());
}

function render_preparing_page(string $token): string
{
    ob_start();
    ?>
<section class="prep-screen">
  <div class="card prep-card">
    <p class="eyebrow">Preparing</p>
    <h1>このページは準備中です</h1>
    <p>通信が復帰したら共有イベントの内容が表示されます。少し時間をおいてもう一度アクセスしてください。</p>
    <p class="muted">Token: <?= app_h($token) ?></p>
  </div>
</section>
    <?php
    return (string) ob_get_clean();
}

function render_guest_name_screen(array $event, string $token): string
{
    ob_start();
    ?>
<section class="guest-hero">
  <div class="hero-copy">
    <p class="eyebrow">Guest access</p>
    <h1>ニックネームを入れて次へ。</h1>
    <p class="lead">初回だけ表示名を入れてください。</p>
    <div class="message-box">
      <div class="message-head">
        <span class="guest-badge">ようこそ</span>
        <span class="muted"><?= app_h((string) $event['profile_display_name']) ?></span>
      </div>
      <h2><?= app_h((string) ($event['profile_headline'] ?? '')) ?></h2>
      <p><?= nl2br(app_h((string) ($event['body'] ?? ''))) ?></p>
    </div>
  </div>
  <div class="card guest-card">
    <h2>ニックネーム</h2>
    <form method="post" action="<?= app_h(app_url('/s/' . $token)) ?>" class="form">
      <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
      <label>
        <input name="guest_name" maxlength="40" required placeholder="ニックネームを入力">
      </label>
      <button class="button primary" type="submit">次へ</button>
    </form>
  </div>
</section>
    <?php
    return (string) ob_get_clean();
}

function render_guest_profile_screen(array $event, string $guestName, array $photos, array $links): string
{
    $photoHtml = '';
    foreach ($photos as $photo) {
        $photoHtml .= '<img src="' . app_h(app_url('/media/' . basename((string) $photo['storage_path']))) . '" alt="今日の写真" loading="lazy">';
    }

    $linkHtml = '';
    foreach ($links as $link) {
        $linkHtml .= '<a class="social-link" href="' . app_h((string) $link['url']) . '" target="_blank" rel="noreferrer"><span>' . app_h((string) $link['sns_name']) . '</span><strong>' . app_h((string) ($link['label'] ?? $link['sns_name'])) . '</strong></a>';
    }

    ob_start();
    ?>
<section class="guest-hero">
  <div class="hero-copy">
    <p class="eyebrow">Shared profile</p>
    <h1><?= app_h($guestName) ?>さん、ようこそ</h1>
    <div class="hero-profile">
      <img class="hero-avatar" src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=420&q=80" alt="profile">
      <div>
        <h2><?= app_h((string) $event['profile_display_name']) ?></h2>
        <p class="lead"><?= app_h((string) ($event['profile_headline'] ?? '')) ?></p>
        <p><?= nl2br(app_h((string) ($event['profile_bio'] ?? ''))) ?></p>
      </div>
    </div>
    <div class="message-box">
      <div class="message-head">
        <span class="guest-badge">今日のメッセージ</span>
      </div>
      <p><?= nl2br(app_h((string) ($event['body'] ?? ''))) ?: '今日はありがとうございました。' ?></p>
    </div>
  </div>
  <div class="card guest-card">
    <h2>今日の写真</h2>
    <div class="photo-grid">
      <?= $photoHtml !== '' ? $photoHtml : '<div class="empty">写真はまだありません。</div>' ?>
    </div>
    <h2>SNS・リンク</h2>
    <div class="social-list">
      <?= $linkHtml !== '' ? $linkHtml : '<div class="empty">リンクはまだありません。</div>' ?>
    </div>
  </div>
</section>
    <?php
    return (string) ob_get_clean();
}
