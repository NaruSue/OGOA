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
    if ($db !== null && app_current_user($db) !== null) {
        app_redirect('/dashboard');
    }
    app_render('Home', render_home_simple());
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

if (preg_match('#^/profiles/(\d+)$#', $path, $matches) === 1) {
    app_redirect('/profiles/' . (int) $matches[1] . '/edit');
}

if ($path === '/share-tokens/reserve' && $method === 'POST') {
    api_reserve_tokens($db);
    exit;
}

if ($path === '/share-events/create' && $method === 'POST') {
    handle_share_event_save($db, false);
    exit;
}

if ($path === '/share-events/qr' && $method === 'GET') {
    render_share_event_qr($db, null);
    exit;
}

if (preg_match('#^/share-events/(\d+)/qr$#', $path, $matches) === 1 && $method === 'GET') {
    render_share_event_qr($db, (int) $matches[1]);
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
    $defaultAvatarUrl = app_random_default_avatar_url();
    if ($user) {
        $update = $db->prepare(
            'UPDATE users
             SET email = :email,
                 name = :name,
                 avatar_url = COALESCE(NULLIF(avatar_url, \'\'), :avatar_url),
                 account_display_name = COALESCE(account_display_name, :display_name),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
             RETURNING *'
        );
        $update->execute([
            'email' => (string) $profile['email'],
            'name' => (string) $profile['name'],
            'avatar_url' => $defaultAvatarUrl,
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
        'avatar_url' => $defaultAvatarUrl,
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

function render_home_simple(): string
{
    ob_start();
    ?>
<section class="hero">
  <div class="hero-copy">
    <p class="eyebrow">Meet and share</p>
    <h1>出会った相手に、見せたいプロフィールをQRで共有。</h1>
    <p class="lead">1G1Aは、会った相手にプロフィールや連絡先をあとから見てもらうための共有アプリです。場面に合わせたプロフィールを選んで、メッセージや写真と一緒にQRで渡せます。</p>
    <div class="hero-actions">
      <a class="button primary" href="<?= app_h(app_url('/login')) ?>">ログインして使う</a>
    </div>
  </div>
  <div class="hero-card">
    <div class="panel">
      <h2>かんたんな使い方</h2>
      <div class="step-accordion">
        <details open>
          <summary>1. 見せたいプロフィールを登録</summary>
          <p>LINE、Instagram、X、メール、電話など、見せたい連絡先を登録します。プロフィールは複数作れるので、場面に合わせて分けられます。飲み仲間、仕事仲間、旅先用、秘密のうふふ用など。</p>
        </details>
        <details>
          <summary>2. その場でQRを表示</summary>
          <p>その場のノリに合わせて、見せたいプロフィールを選びます。必要ならメッセージや写真を追加して、QRを表示します。QRはあとからでも参照できます。</p>
        </details>
        <details>
          <summary>3. 写真で撮ってもらう</summary>
          <p>相手は今すぐページを開かなくても大丈夫です。この画面を写真で撮ってもらえば、あとからQRでアクセスできます。ネットが弱い場所でも使いやすい形です。</p>
        </details>
      </div>
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
        $profileAvatarUrl = app_profile_display_avatar_url($profile, $user);
        $profileOptions .= '<option value="' . $profileId . '"' . ($index === 0 ? ' selected' : '') . '>' . app_h((string) $profile['profile_name']) . ' - ' . app_h((string) $profile['display_name']) . '</option>';
        $recentEvents = app_fetch_recent_events($db, $profileId, 2);
        $eventInfo = $recentEvents !== [] ? count($recentEvents) . ' 件の共有イベント' : 'まだ共有イベントはありません';
        $profileCards .= '<article class="list-card profile-list-card">' . app_render_profile_avatar($profileAvatarUrl, (string) $profile['display_name'], 'avatar-mini') . '<div><p class="eyebrow">' . app_h((string) $profile['profile_name']) . '</p><h3>' . app_h((string) $profile['display_name']) . '</h3><p>' . app_h((string) ($profile['headline'] ?? '')) . '</p><p class="muted">' . app_h($eventInfo) . '</p></div><div class="list-meta"><span class="status-pill ' . (((bool) $profile['is_public']) ? 'public' : 'private') . '">' . (((bool) $profile['is_public']) ? '利用中' : '停止中') . '</span><span>' . (int) ($profile['sns_count'] ?? 0) . ' SNS</span><div class="list-actions"><a class="button secondary" href="' . app_h(app_url('/profiles/' . $profileId . '/edit')) . '">編集</a></div></div></article>';
    }

    $eventPageSize = 5;
    $eventCount = app_count_dashboard_events($db, (int) $user['id']);
    $eventPageCount = max(1, (int) ceil($eventCount / $eventPageSize));
    $eventPage = max(1, min((int) ($_GET['events_page'] ?? 1), $eventPageCount));
    $dashboardEvents = app_fetch_dashboard_events(
        $db,
        (int) $user['id'],
        $eventPageSize,
        ($eventPage - 1) * $eventPageSize
    );
    $eventRows = '';
    foreach ($dashboardEvents as $event) {
        $createdAt = strtotime((string) ($event['created_at'] ?? ''));
        $eventDate = $createdAt !== false ? date('Y年n月j日 H:i', $createdAt) : '';
        $profileName = trim((string) ($event['profile_name'] ?? ''));
        if ($profileName === '') {
            $profileName = (string) ($event['profile_display_name'] ?? '共有プロフィール');
        }
        $message = preg_replace('/\s+/u', ' ', trim((string) ($event['body'] ?? ''))) ?? '';
        if ($message === '') {
            $message = 'メッセージなし';
        } elseif (function_exists('mb_strimwidth')) {
            $message = mb_strimwidth($message, 0, 72, '…', 'UTF-8');
        } elseif (strlen($message) > 72) {
            $message = substr($message, 0, 69) . '...';
        }
        $eventRows .= '<a class="event-row" href="' . app_h(app_url('/share-events/' . (int) $event['id'] . '/qr')) . '">';
        $eventRows .= '<span class="event-row-main"><span class="event-row-meta"><strong>' . app_h($profileName) . '</strong><time datetime="' . app_h((string) ($event['created_at'] ?? '')) . '">' . app_h($eventDate) . '</time></span>';
        $eventRows .= '<p>' . app_h($message) . '</p></span></a>';
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
<section class="page launch-section">
  <div class="card launch-card">
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
        <input type="file" name="photos[]" id="share-photos" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
        <span class="upload-note">写真は通信できないときに端末内に一時保存されます。</span>
      </label>
      <label>QRの有効期限
        <select name="expires_in" id="share-expires">
          <option value="24h" selected>24時間</option>
          <option value="3d">3日</option>
          <option value="7d">1週間</option>
          <option value="30d">1か月</option>
        </select>
      </label>
      <div class="location-option" data-location-option>
        <div class="location-option-row">
          <span>位置情報をつける</span>
          <label class="switch-control" aria-label="位置情報をつける">
            <input type="hidden" name="attach_location" value="0">
            <input type="checkbox" name="attach_location" id="attach-location" value="1" checked disabled>
            <span class="switch-slider" aria-hidden="true"></span>
          </label>
        </div>
        <p class="muted location-hint" id="location-enabled-hint" hidden>位置情報をつけると地図を添付できます</p>
        <p class="muted location-hint" id="location-disabled-hint">位置情報を許可すると使えます</p>
      </div>
      <div class="launch-actions">
        <button class="button primary" type="submit" data-publish="1">QRを作成</button>
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

<section class="pwa-install-card">
  <button class="button secondary" type="button" data-pwa-install hidden>ホーム画面に追加</button>
  <p>スマホに追加すると、次回からすぐQRを表示できます。</p>
</section>

<section class="page">
  <div class="section-head">
    <div>
      <p class="eyebrow">Share events</p>
      <h2>共有イベント</h2>
    </div>
    <?php if ($eventCount > 0): ?>
      <span class="muted"><?= $eventCount ?> 件</span>
    <?php endif; ?>
  </div>
  <div class="event-list">
    <?= $eventRows !== '' ? $eventRows : '<div class="empty">表示できる共有イベントはまだありません。</div>' ?>
  </div>
  <?php if ($eventPageCount > 1): ?>
    <nav class="pagination" aria-label="共有イベントのページ">
      <?php if ($eventPage > 1): ?>
        <a class="button secondary" href="<?= app_h(app_url('/dashboard?events_page=' . ($eventPage - 1))) ?>">前へ</a>
      <?php else: ?>
        <span class="button secondary disabled" aria-disabled="true">前へ</span>
      <?php endif; ?>
      <span class="pagination-state"><?= $eventPage ?> / <?= $eventPageCount ?></span>
      <?php if ($eventPage < $eventPageCount): ?>
        <a class="button secondary" href="<?= app_h(app_url('/dashboard?events_page=' . ($eventPage + 1))) ?>">次へ</a>
      <?php else: ?>
        <span class="button secondary disabled" aria-disabled="true">次へ</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</section>

<section class="page narrow">
  <div class="card">
    <details class="help-accordion">
      <summary>使い方</summary>
      <p><?= app_h($profileHint) ?> 必要ならメッセージと写真を追加して、QRを作成します。</p>
    </details>
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
    app_render('Dashboard', (string) ob_get_clean(), ['user' => $user, 'flash' => false]);
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
        $avatarUrl = trim((string) ($user['avatar_url'] ?? ''));
        if (isset($_POST['use_default_avatar']) && $_POST['use_default_avatar'] === '1') {
            $avatarUrl = app_random_default_avatar_url();
        }
        $avatarUpload = $_FILES['avatar_upload'] ?? null;
        if (is_array($avatarUpload) && ($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $storedAvatar = app_store_uploaded_photo($avatarUpload, 'account_avatar');
            $avatarUrl = '/media/' . basename((string) $storedAvatar['storage_path']);
        }
        if ($displayName === '' || mb_strlen($displayName) > 120) {
            app_flash('入力内容を確認してください。');
            app_redirect('/account');
        }
        if ($avatarUrl === '') {
            $avatarUrl = app_random_default_avatar_url();
        }
        $stmt = $db->prepare('UPDATE users SET account_display_name = :display_name, avatar_url = :avatar_url, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
            'id' => (int) $user['id'],
        ]);
        app_flash('アカウントプロフィールを更新しました。');
        app_redirect('/dashboard');
    }

    $displayName = trim((string) ($user['account_display_name'] ?? '')) ?: (string) $user['name'];
    $email = (string) ($user['email'] ?? '');
    $avatarUrl = app_account_avatar_url($user);
    $csrf = app_h(app_csrf_token());
    ob_start();
    ?>
<section class="page narrow">
  <div class="card">
    <p class="eyebrow">Account profile</p>
    <h1>アカウント情報</h1>
    <p>ヘッダや共有プロフィール画像が未設定のときに使う、アカウント共通の表示情報です。</p>
    <div class="account-summary">
      <?= app_render_profile_avatar($avatarUrl, $displayName) ?>
      <div>
        <h2><?= app_h($displayName) ?></h2>
        <p class="muted"><?= app_h($email) ?></p>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data" class="form">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <label>アカウントの名称
        <input name="display_name" required maxlength="120" value="<?= app_h($displayName) ?>">
      </label>
      <label class="upload-field">アカウントプロフィール画像 <span class="optional">未設定ならデフォルト画像を使います</span>
        <span class="avatar-upload-preview">
          <?= app_render_profile_avatar($avatarUrl, $displayName, 'avatar-preview') ?>
          <span>共有プロフィール画像が未設定のときにも、この画像を表示します。</span>
        </span>
        <input type="file" name="avatar_upload" accept="image/jpeg,image/png,image/webp,image/gif">
      </label>
      <label class="checkbox">
        <input type="checkbox" name="use_default_avatar" value="1"> デフォルト画像に戻す
      </label>
      <div class="form-block">
        <h2>Google認証情報</h2>
        <p class="muted">メールアドレスは確認用です。この画面では変更できません。</p>
        <label>メールアドレス
          <input value="<?= app_h($email) ?>" readonly>
        </label>
      </div>
      <div class="notice">メールアドレスや Google ID はゲスト画面には出ません。</div>
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
        $avatarUrl = $profile ? trim((string) ($profile['avatar_url'] ?? '')) : '';
        if (isset($_POST['clear_avatar']) && $_POST['clear_avatar'] === '1') {
            $avatarUrl = '';
        }
        $avatarUpload = $_FILES['avatar_upload'] ?? null;
        if (is_array($avatarUpload) && ($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $storedAvatar = app_store_uploaded_photo($avatarUpload, 'profile_avatar');
            $avatarUrl = '/media/' . basename((string) $storedAvatar['storage_path']);
        }
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

            $stmt = $db->prepare('INSERT INTO profiles (user_id, slug, public_token, profile_name, display_name, avatar_url, bio, headline, is_public) VALUES (:user_id, :slug, :public_token, :profile_name, :display_name, :avatar_url, :bio, :headline, :is_public) RETURNING id');
            $stmt->execute([
                'user_id' => (int) $user['id'],
                'slug' => $slug,
                'public_token' => app_generate_public_token($db),
                'profile_name' => $profileName,
                'display_name' => $displayName,
                'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
                'bio' => $bio !== '' ? $bio : null,
                'headline' => $headline !== '' ? $headline : null,
                'is_public' => $isPublic,
            ]);
            app_flash('共有プロフィールを作成しました。');
            $newProfileId = (int) $stmt->fetchColumn();
            app_save_profile_contacts($db, $newProfileId, (array) ($_POST['contacts'] ?? []));
            app_redirect('/dashboard');
        }

        $stmt = $db->prepare('UPDATE profiles SET profile_name = :profile_name, display_name = :display_name, avatar_url = :avatar_url, headline = :headline, bio = :bio, is_public = :is_public, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            'profile_name' => $profileName,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
            'headline' => $headline !== '' ? $headline : null,
            'bio' => $bio !== '' ? $bio : null,
            'is_public' => $isPublic,
            'id' => (int) $profile['id'],
        ]);
        app_flash('共有プロフィールを更新しました。');
        app_save_profile_contacts($db, (int) $profile['id'], (array) ($_POST['contacts'] ?? []));
        app_redirect('/profiles/' . (string) $profile['id'] . '/edit');
    }

    $defaultDisplay = (string) ($user['account_display_name'] ?? $user['name'] ?? '');
    $profileName = $profile ? (string) $profile['profile_name'] : '';
    $displayName = $profile ? (string) $profile['display_name'] : $defaultDisplay;
    $avatarUrl = $profile ? (string) ($profile['avatar_url'] ?? '') : '';
    $displayAvatarUrl = app_profile_display_avatar_url($profile ?: [], $user);
    $headline = $profile ? (string) ($profile['headline'] ?? '') : '';
    $bio = $profile ? (string) ($profile['bio'] ?? '') : '';
    $checked = $profile === null || (bool) $profile['is_public'] ? ' checked' : '';
    $title = $profile === null ? '共有プロフィールを作成' : '共有プロフィールを編集';
    $button = $profile === null ? '作成する' : '更新する';
    $csrf = app_h(app_csrf_token());
    $contactServices = app_fetch_contact_services($db);
    $contactValues = $profile ? app_profile_contact_values(app_fetch_profile_links($db, (int) $profile['id'])) : [];
    ob_start();
    ?>
<section class="page narrow">
  <div class="card">
    <p class="eyebrow">Share profile</p>
    <h1><?= app_h($title) ?></h1>
    <p>1アカウントで複数持てる共有プロフィールです。用途ごとに分けて使えます。</p>
    <form method="post" enctype="multipart/form-data" class="form">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <label>共有プロフィール名
        <input name="profile_name" required maxlength="120" value="<?= app_h($profileName) ?>" placeholder="仕事用 / 友人用 / 旅先用">
      </label>
      <label>表示名
        <input name="display_name" required maxlength="120" value="<?= app_h($displayName) ?>">
      </label>
      <label class="upload-field">プロフィール画像 <span class="optional">写真を選択、またはカメラで撮影</span>
        <span class="avatar-upload-preview">
          <?= app_render_profile_avatar($displayAvatarUrl, $displayName, 'avatar-preview') ?>
          <span><?= $avatarUrl !== '' ? '共有プロフィール画像が設定されています。' : '共有プロフィール画像が未設定のため、アカウントプロフィール画像を表示しています。' ?></span>
        </span>
        <input type="file" name="avatar_upload" accept="image/jpeg,image/png,image/webp,image/gif">
      </label>
      <?php if ($avatarUrl !== ''): ?>
        <label class="checkbox">
          <input type="checkbox" name="clear_avatar" value="1"> 共有プロフィール画像を削除してアカウント画像を使う
        </label>
        <p class="hint">この操作では、元のアカウントプロフィール画像は削除しません。</p>
      <?php endif; ?>
      <label>見出し
        <input name="headline" maxlength="160" value="<?= app_h($headline) ?>" placeholder="旅と写真が好きです">
      </label>
      <label>紹介文
        <textarea name="bio" rows="5" maxlength="1000"><?= app_h($bio) ?></textarea>
      </label>
      <div class="form-block contact-editor" data-contact-editor>
        <div class="contact-editor-head">
          <div>
            <h2>連絡先</h2>
            <p class="muted">登録済みの連絡先だけゲスト画面に表示されます。</p>
          </div>
          <button class="button secondary" type="button" data-contact-open>＋ 追加</button>
        </div>
        <div class="contact-fields" data-contact-fields>
          <?php foreach ($contactServices as $service): ?>
            <?php
              $code = (string) $service['code'];
              $value = (string) ($contactValues[$code] ?? '');
              if ($value === '') { continue; }
            ?>
            <div class="contact-field" data-contact-field data-contact-code="<?= app_h($code) ?>">
              <div class="contact-field-title">
                <span><?= app_h((string) ($service['display_name'] ?? $service['name'])) ?></span>
                <button class="icon-button danger" type="button" data-contact-remove aria-label="削除">🗑</button>
              </div>
              <input name="contacts[<?= app_h($code) ?>]" value="<?= app_h($value) ?>" placeholder="<?= app_h((string) ($service['placeholder'] ?? '')) ?>">
            </div>
          <?php endforeach; ?>
        </div>
        <p class="empty contact-empty" data-contact-empty<?= $contactValues !== [] ? ' hidden' : '' ?>>連絡先はまだありません。</p>
        <div class="contact-picker" data-contact-picker hidden>
          <div class="contact-picker-panel">
            <div class="contact-picker-head">
              <h3>追加する連絡先</h3>
              <button class="icon-button" type="button" data-contact-close aria-label="閉じる">×</button>
            </div>
            <div class="contact-picker-grid">
              <?php foreach ($contactServices as $service): ?>
                <?php $code = (string) $service['code']; ?>
                <button class="contact-service-button<?= isset($contactValues[$code]) ? ' selected' : '' ?>" type="button" data-contact-service
                  data-code="<?= app_h($code) ?>"
                  data-name="<?= app_h((string) ($service['display_name'] ?? $service['name'])) ?>"
                  data-placeholder="<?= app_h((string) ($service['placeholder'] ?? '')) ?>"
                  data-icon="<?= app_h((string) ($service['icon_url'] ?? '')) ?>">
                  <?php if (trim((string) ($service['icon_url'] ?? '')) !== ''): ?>
                    <img src="<?= app_h((string) $service['icon_url']) ?>" alt="" loading="lazy">
                  <?php endif; ?>
                  <span><?= app_h((string) ($service['display_name'] ?? $service['name'])) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
            <div class="contact-picker-actions">
              <button class="button primary" type="button" data-contact-add-selected>追加</button>
            </div>
          </div>
        </div>
      </div>
      <label class="checkbox">
        <input type="checkbox" name="is_public" value="1"<?= $checked ?>> 利用する
      </label>
      <button class="button primary" type="submit"><?= app_h($button) ?></button>
    </form>
  </div>
</section>
    <?php
    app_render($title, (string) ob_get_clean(), ['user' => $user]);
}

function app_fetch_contact_services(PDO $db): array
{
    $stmt = $db->query(
        "SELECT id, code, name,
                COALESCE(display_name, name) AS display_name,
                COALESCE(category, 'standard') AS category,
                COALESCE(input_kind, 'text') AS input_kind,
                icon_url, url_template, copy_template, placeholder, help_text, sort_order,
                COALESCE(allow_multibyte, FALSE) AS allow_multibyte,
                COALESCE(normalize_width, TRUE) AS normalize_width
         FROM sns_types
         WHERE COALESCE(is_active, TRUE) = TRUE
         ORDER BY sort_order ASC, id ASC"
    );

    return $stmt->fetchAll() ?: [];
}

function app_contact_service_by_code(array $services): array
{
    $map = [];
    foreach ($services as $service) {
        $code = (string) ($service['code'] ?? '');
        if ($code !== '') {
            $map[$code] = $service;
        }
    }

    return $map;
}

function app_contact_fill_template(?string $template, string $value, bool $encodeValue = true): string
{
    $template = trim((string) $template);
    if ($template === '') {
        return $value;
    }

    return str_replace('{value}', $encodeValue ? rawurlencode($value) : $value, $template);
}

function app_normalize_contact_width(string $value): string
{
    if (function_exists('mb_convert_kana')) {
        return mb_convert_kana($value, 'asKV', 'UTF-8');
    }

    return strtr($value, [
        '＠' => '@', '．' => '.', '＿' => '_', '－' => '-', 'ー' => '-', '＋' => '+',
        '／' => '/', '：' => ':', '？' => '?', '＆' => '&', '＝' => '=', '％' => '%',
        '＃' => '#', '！' => '!', '　' => ' ',
    ]);
}

function app_normalize_contact_raw_value(array $service, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (filter_var($service['normalize_width'] ?? true, FILTER_VALIDATE_BOOL)) {
        $value = app_normalize_contact_width($value);
    }

    $kind = (string) ($service['input_kind'] ?? 'text');
    if (in_array($kind, ['handle'], true)) {
        $value = ltrim($value, '@');
        if (!filter_var($service['allow_multibyte'] ?? false, FILTER_VALIDATE_BOOL)) {
            $value = preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?: '';
        }
    }
    if ($kind === 'email') {
        $value = strtolower($value);
    }
    if ($kind === 'phone') {
        return preg_replace('/[^\d+]/', '', $value) ?: '';
    }
    if ($kind === 'url' && !str_contains($value, ':')) {
        return 'https://' . $value;
    }

    return trim($value);
}

function app_contact_url_from_value(array $service, string $rawValue): string
{
    $rawValue = trim($rawValue);
    if ($rawValue === '') {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $rawValue) === 1) {
        return $rawValue;
    }

    return app_contact_fill_template((string) ($service['url_template'] ?? ''), $rawValue, true);
}

function app_contact_copy_value(array $service, string $rawValue): string
{
    $rawValue = trim($rawValue);
    if ($rawValue === '') {
        return '';
    }

    return app_contact_fill_template((string) ($service['copy_template'] ?? '{value}'), $rawValue, false);
}

function app_profile_contact_values(array $links): array
{
    $values = [];
    foreach ($links as $link) {
        $code = (string) ($link['sns_code'] ?? '');
        if ($code !== '') {
            $values[$code] = (string) (($link['raw_value'] ?? '') !== '' ? $link['raw_value'] : ($link['url'] ?? ''));
        }
    }

    return $values;
}

function app_save_profile_contacts(PDO $db, int $profileId, array $input): void
{
    $services = app_contact_service_by_code(app_fetch_contact_services($db));
    $delete = $db->prepare('DELETE FROM profile_sns WHERE profile_id = :profile_id AND sns_type_id = :sns_type_id');
    $upsertLink = $db->prepare(
        'INSERT INTO profile_sns (profile_id, sns_type_id, label, url, raw_value, sort_order, is_primary)
         VALUES (:profile_id, :sns_type_id, :label, :url, :raw_value, :sort_order, false)
         ON CONFLICT (profile_id, sns_type_id)
         DO UPDATE SET label = EXCLUDED.label, url = EXCLUDED.url, raw_value = EXCLUDED.raw_value, sort_order = EXCLUDED.sort_order, updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($input as $code => $value) {
        $code = (string) $code;
        if (!isset($services[$code])) {
            continue;
        }
        $service = $services[$code];
        $snsTypeId = (int) $service['id'];
        $rawValue = app_normalize_contact_raw_value($service, (string) $value);
        $url = app_contact_url_from_value($service, $rawValue);

        if ($rawValue === '' || $url === '') {
            $delete->execute(['profile_id' => $profileId, 'sns_type_id' => $snsTypeId]);
            continue;
        }

        $upsertLink->execute([
            'profile_id' => $profileId,
            'sns_type_id' => $snsTypeId,
            'label' => (string) ($service['display_name'] ?? $service['name'] ?? $code),
            'url' => $url,
            'raw_value' => $rawValue,
            'sort_order' => (int) ($service['sort_order'] ?? 0),
        ]);
    }
}

function render_share_event_qr(?PDO $db, ?int $eventId): void
{
    if ($db === null) {
        app_render('QR', '<section class="page narrow"><div class="card"><h1>DB に接続できません</h1></div></section>');
        return;
    }

    $user = app_current_user($db);
    if (!$user) {
        app_redirect('/login');
    }

    $requestedToken = isset($_GET['token']) && is_string($_GET['token']) ? trim((string) $_GET['token']) : '';
    $requestedProfileId = isset($_GET['profile']) ? (int) $_GET['profile'] : 0;
    $draftMode = isset($_GET['draft']) && $_GET['draft'] === '1';

    $event = null;
    $profile = null;
    if ($eventId !== null && $eventId > 0) {
        $stmt = $db->prepare(
            'SELECT se.*, p.user_id, p.display_name AS profile_display_name, p.profile_name,
                    p.headline AS profile_headline, p.bio AS profile_bio, p.avatar_url AS profile_avatar_url,
                    u.avatar_url AS account_avatar_url
             FROM share_events se
             INNER JOIN profiles p ON p.id = se.profile_id
             INNER JOIN users u ON u.id = p.user_id
             WHERE se.id = :id AND p.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $eventId,
            'user_id' => (int) $user['id'],
        ]);
        $event = $stmt->fetch() ?: null;
        if ($event === null) {
            http_response_code(404);
            app_render('Not found', '<section class="page narrow"><div class="card"><h1>共有イベントが見つかりません</h1></div></section>', ['user' => $user]);
            return;
        }
        $profile = [
            'id' => (int) $event['profile_id'],
            'user_id' => (int) $event['user_id'],
            'display_name' => (string) ($event['profile_display_name'] ?? ''),
            'profile_name' => (string) ($event['profile_name'] ?? ''),
            'avatar_url' => (string) ($event['profile_avatar_url'] ?? ''),
        ];
    } elseif ($draftMode && $requestedProfileId > 0 && $requestedToken !== '') {
        $profile = app_fetch_profile($db, $requestedProfileId);
        if (!$profile || (int) $profile['user_id'] !== (int) $user['id']) {
            http_response_code(404);
            app_render('Not found', '<section class="page narrow"><div class="card"><h1>共有プロフィールが見つかりません</h1></div></section>', ['user' => $user]);
            return;
        }
    } else {
        http_response_code(404);
        app_render('Not found', '<section class="page narrow"><div class="card"><h1>QRが見つかりません</h1></div></section>', ['user' => $user]);
        return;
    }

    $token = $event ? (string) $event['public_token'] : $requestedToken;
    $shareUrl = $token !== '' ? app_url('/s/' . $token) : app_url('/dashboard');
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&margin=12&ecc=H&data=' . rawurlencode($shareUrl);
    $displayName = trim((string) ($profile['display_name'] ?? ''));
    if ($displayName === '') {
        $displayName = '1G1A';
    }
    $avatarUrl = app_profile_display_avatar_url($profile ?: [], $user);
    $noticeJa = $draftMode && $event === null
        ? 'この画面を撮影してあとでQRからアクセスしてください。'
        : '私のプロフィールと連絡先です。この画面を撮影して後からQRでアクセスしてください。';
    $noticeEn = $draftMode && $event === null
        ? 'Please take a photo of this screen and access it via the QR code later.'
        : 'Here are my profile and contact info. Please take a photo of this screen and access it via the QR code later.';
    ob_start();
    ?>
<section class="page qr-page">
  <div class="card qr-card">
    <h1><?= app_h($displayName) ?></h1>
    <div class="qr-frame">
      <img class="qr-code-image" src="<?= $qrUrl ?>" alt="QR code">
      <?= app_render_profile_avatar($avatarUrl, $displayName, 'qr-center-avatar') ?>
    </div>
    <div class="qr-copy">
      <p><?= app_h($noticeJa) ?></p>
      <p class="muted"><?= app_h($noticeEn) ?></p>
    </div>
    <div class="qr-url">
      <!--<span>URL</span>-->
      <a href="<?= app_h($shareUrl) ?>"><?= app_h($shareUrl) ?></a>
    </div>
  </div>
</section>
    <?php
    app_render(
        'QR',
        (string) ob_get_clean(),
        [
            'user' => $user,
            'chrome' => false,
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
    $uploadMeta = [];
    if (isset($_FILES['photos']) && is_array($_FILES['photos'])) {
        $uploadMeta = [
            'token' => (string) ($_POST['public_token'] ?? ''),
            'names' => $_FILES['photos']['name'] ?? [],
            'types' => $_FILES['photos']['type'] ?? [],
            'errors' => $_FILES['photos']['error'] ?? [],
            'sizes' => $_FILES['photos']['size'] ?? [],
        ];
        error_log(date('c') . ' request ' . json_encode($uploadMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, app_root('logs/upload-debug.log'));
    }
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
        app_flash($syncMode ? '共有イベントを同期しました。' : 'QR を作成しました。');
        app_redirect('/share-events/' . (int) $event['id'] . '/qr');
    } catch (Throwable $e) {
        error_log(date('c') . ' failure ' . json_encode(['token' => (string) ($_POST['public_token'] ?? ''), 'exception' => get_class($e), 'message' => $e->getMessage(), 'files' => $uploadMeta, 'request' => ['content_length' => $_SERVER['CONTENT_LENGTH'] ?? null, 'content_type' => $_SERVER['CONTENT_TYPE'] ?? null, 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null], 'limits' => ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'), 'max_file_uploads' => ini_get('max_file_uploads')], 'storage_free_bytes' => @disk_free_space(app_root('storage/uploads'))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, app_root('logs/upload-debug.log'));
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
    if (!filter_var($event['attach_location'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
        app_json(['error' => 'location_disabled'], 409);
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
    if (!is_string($mimeType)) {
        $relativeUrl = '/media/' . $filename;
        $absoluteUrl = app_url($relativeUrl);
        $avatarStmt = $db->prepare(
            'SELECT 1 FROM profiles WHERE avatar_url IN (:relative_url, :absolute_url)
             UNION
             SELECT 1 FROM users WHERE avatar_url IN (:relative_url, :absolute_url)
             LIMIT 1'
        );
        $avatarStmt->execute([
            'relative_url' => $relativeUrl,
            'absolute_url' => $absoluteUrl,
        ]);
        if ($avatarStmt->fetchColumn()) {
            $info = is_file($file) ? @getimagesize($file) : false;
            $mimeType = is_array($info) && isset($info['mime']) ? (string) $info['mime'] : false;
        }
    }
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

    if ((string) ($event['status'] ?? 'ready') !== 'ready') {
        app_render('準備中', render_preparing_page($token));
        return;
    }

    if (app_share_event_expired($event)) {
        app_clear_guest_identity_cookie($token);
        app_render('期限切れ', render_expired_share_page(), ['chrome' => false]);
        return;
    }

    $currentUser = app_current_user($db);
    $isOwnerPreview = $currentUser !== null && (int) ($event['user_id'] ?? 0) === (int) $currentUser['id'];
    if ($isOwnerPreview) {
        if ($method === 'POST') {
            app_redirect('/s/' . $token);
        }

        $ownerName = trim((string) ($currentUser['account_display_name'] ?? $currentUser['name'] ?? ''));
        if ($ownerName === '') {
            $ownerName = app_guest_display_name($event);
        }
        $photos = app_fetch_share_event_photos($db, (int) $event['id']);
        $links = app_fetch_profile_links($db, (int) $event['profile_id']);
        $messages = app_fetch_share_event_messages($db, (int) $event['id']);
        app_render(app_guest_display_name($event), render_guest_profile_screen_simple($event, $ownerName, $photos, $links, $messages, false), ['chrome' => false]);
        return;
    }

    if ($method === 'POST') {
        app_require_csrf();
        if (array_key_exists('guest_message', $_POST)) {
            $guestMessage = trim((string) ($_POST['guest_message'] ?? ''));
            if ($guestMessage === '') {
                app_flash('メッセージを入力してください。');
                app_redirect('/s/' . $token);
            }
            $viewerToken = app_guest_token_from_cookie($token);
            $guestName = app_guest_name_from_cookie($token);
            if ($viewerToken !== null && $guestName === null) {
                $stmt = $db->prepare('SELECT display_name FROM guest_visitors WHERE viewer_token = :viewer_token LIMIT 1');
                $stmt->execute(['viewer_token' => $viewerToken]);
                $stored = $stmt->fetchColumn();
                if (is_string($stored) && $stored !== '') {
                    $guestName = $stored;
                }
            }
            if ($guestName === null) {
                app_flash('名前を登録してから送信してください。');
                app_redirect('/s/' . $token);
            }

            $guestMessage = preg_replace('/[\r\n]+/u', ' ', $guestMessage);
            $guestMessage = is_string($guestMessage) ? trim($guestMessage) : '';
            if ($guestMessage === '' || (function_exists('mb_strlen') ? mb_strlen($guestMessage, 'UTF-8') : strlen($guestMessage)) > 50) {
                app_flash('メッセージは50文字以内で入力してください。');
                app_redirect('/s/' . $token);
            }

            $owner = app_fetch_profile_owner($db, (int) $event['profile_id']);
            $recipientEmail = trim((string) ($owner['user_email'] ?? ''));
            if ($recipientEmail === '') {
                app_flash('受信先メールアドレスが設定されていません。');
                app_redirect('/s/' . $token);
            }

            $viewerToken = $viewerToken ?? bin2hex(random_bytes(16));
            app_upsert_guest_visitor($db, $viewerToken, $guestName);
            app_set_guest_identity_cookie($token, $viewerToken, $guestName);
            app_store_guest_message($db, (int) $event['profile_id'], (int) $event['id'], $guestName, $guestMessage, $recipientEmail);
            $sent = app_send_guest_message_notification($db, $event, $guestName, $guestMessage);
            app_flash($sent ? 'メッセージを送信しました。' : 'メッセージは保存しましたが、メール送信に失敗しました。');
            app_redirect('/s/' . $token);
        }
        $guestName = app_normalize_guest_name((string) ($_POST['guest_name'] ?? ''));
        if (!app_valid_guest_name($guestName)) {
            app_flash('名前を入力してください。');
            app_redirect('/s/' . $token);
        }

        $viewerToken = app_guest_token_from_cookie($token) ?? bin2hex(random_bytes(16));
        app_upsert_guest_visitor($db, $viewerToken, $guestName);
        app_set_guest_identity_cookie($token, $viewerToken, $guestName);
        $event = app_touch_share_event_first_access($db, (int) $event['id']) ?? $event;
        app_log_share_access($db, (int) $event['id'], (int) $event['profile_id'], $viewerToken);
        app_redirect('/s/' . $token);
    }

    $viewerToken = app_guest_token_from_cookie($token);
    $guestName = app_guest_name_from_cookie($token);
    if ($viewerToken !== null && $guestName === null) {
        $stmt = $db->prepare('SELECT display_name FROM guest_visitors WHERE viewer_token = :viewer_token LIMIT 1');
        $stmt->execute(['viewer_token' => $viewerToken]);
        $stored = $stmt->fetchColumn();
        if (is_string($stored) && $stored !== '') {
            $guestName = $stored;
        }
    }

    if ($guestName === null) {
        app_render(app_guest_display_name($event), render_guest_name_screen_simple($event, $token), ['chrome' => false]);
        return;
    }

    $event = app_touch_share_event_first_access($db, (int) $event['id']) ?? $event;
    if (app_share_event_expired($event)) {
        app_clear_guest_identity_cookie($token);
        app_render('期限切れ', render_expired_share_page(), ['chrome' => false]);
        return;
    }
    app_log_share_access($db, (int) $event['id'], (int) $event['profile_id'], $viewerToken);
    $photos = app_fetch_share_event_photos($db, (int) $event['id']);
    $links = app_fetch_profile_links($db, (int) $event['profile_id']);
    $messages = app_fetch_guest_messages($db, (int) $event['id'], $guestName);
    app_render(app_guest_display_name($event), render_guest_profile_screen_simple($event, $guestName, $photos, $links, $messages), ['chrome' => false]);
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

function render_expired_share_page(): string
{
    ob_start();
    ?>
<section class="page narrow">
  <div class="card center">
    <h1>このQRは期限切れです</h1>
    <p class="lead">共有されたプロフィールは表示できません。</p>
    <p><a class="button secondary" href="<?= app_h(app_url('/')) ?>">1G1Aを見る</a></p>
  </div>
</section>
    <?php
    return (string) ob_get_clean();
}

function render_guest_name_screen_simple(array $event, string $token): string
{
    ob_start();
    ?>
<section class="page narrow">
  <div class="card guest-card">
    <h1><?= app_h(app_guest_display_name($event)) ?></h1>
    <p class="lead">ニックネームを入れて次へ進んでください。</p>
    <form method="post" action="<?= app_h(app_url('/s/' . $token)) ?>" class="form">
      <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
      <label>
        <input name="guest_name" maxlength="40" required placeholder="ニックネーム">
      </label>
      <button class="button primary" type="submit">次へ</button>
    </form>
  </div>
</section>
    <?php
    return (string) ob_get_clean();
}

function app_guest_display_name(array $event): string
{
    $name = trim((string) ($event['profile_display_name'] ?? $event['profile_name'] ?? ''));

    return $name !== '' ? $name : '1G1A';
}

function render_guest_message_box(string $token): string
{
    ob_start();
    ?>
    <div class="message-box guest-message-box">
      <div class="message-head">
        <span class="guest-badge">ひとことメッセージ</span>
        <span class="muted">50文字まで</span>
      </div>
      <form method="post" action="<?= app_h(app_url('/s/' . $token)) ?>" class="form guest-message-form">
        <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
        <label>
          <textarea name="guest_message" maxlength="50" rows="2" placeholder="ひとことだけ送れます"></textarea>
        </label>
        <button class="button primary" type="submit">送信</button>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

function render_guest_profile_screen_simple(array $event, string $guestName, array $photos, array $links, array $messages = [], bool $showMessageBox = true): string
{
    $profileDisplayName = app_guest_display_name($event);
    $profileHeadline = trim((string) ($event['profile_headline'] ?? ''));
    $profileBio = trim((string) ($event['profile_bio'] ?? ''));
    $profileAvatarUrl = app_profile_display_avatar_url($event);
    $message = trim((string) ($event['body'] ?? ''));

    $photoHtml = '';
    foreach ($photos as $photo) {
        $photoHtml .= '<img src="' . app_h(app_url('/media/' . basename((string) $photo['storage_path']))) . '" alt="共有写真" loading="lazy">';
    }

    $linkHtml = '';
    foreach ($links as $link) {
        $serviceName = (string) ($link['service_display_name'] ?? $link['label'] ?? $link['sns_name'] ?? 'Link');
        $copyValue = app_contact_copy_value($link, (string) (($link['raw_value'] ?? '') !== '' ? $link['raw_value'] : ($link['url'] ?? '')));
        $iconUrl = trim((string) ($link['icon_url'] ?? ''));
        $iconHtml = $iconUrl !== '' ? '<img src="' . app_h($iconUrl) . '" alt="" loading="lazy">' : '<span>' . app_h(mb_substr($serviceName, 0, 1, 'UTF-8')) . '</span>';
        $linkHtml .= '<button class="contact-icon-button" type="button" data-contact-action data-service="' . app_h($serviceName) . '" data-copy="' . app_h($copyValue) . '" data-url="' . app_h((string) $link['url']) . '">' . $iconHtml . '<span>' . app_h($serviceName) . '</span></button>';
    }

    $mapHtml = '';
    $latitude = $event['latitude'] ?? null;
    $longitude = $event['longitude'] ?? null;
    if ($latitude !== null && $longitude !== null) {
        $lat = (float) $latitude;
        $lng = (float) $longitude;
        $delta = 0.006;
        $bbox = implode(',', [
            $lng - $delta,
            $lat - $delta,
            $lng + $delta,
            $lat + $delta,
        ]);
        $mapUrl = 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox) . '&layer=mapnik&marker=' . rawurlencode($lat . ',' . $lng);
        $mapAppUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lng);
        $mapHtml = '<section class="guest-map-block"><h3>場所</h3><div class="guest-map-frame"><iframe src="' . app_h($mapUrl) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="地図"></iframe></div><div class="map-actions"><a class="button primary" href="' . app_h($mapAppUrl) . '" target="_blank" rel="noreferrer">地図アプリで開く</a></div><p class="muted">地図データ © OpenStreetMap contributors</p></section>';
    }

    $messageHistoryHtml = '';
    foreach ($messages as $sentMessage) {
        $sentAt = strtotime((string) ($sentMessage['created_at'] ?? ''));
        $sentLabel = $sentAt !== false ? date('Y年n月j日 H:i', $sentAt) : '';
        $messageHistoryHtml .= '<article class="guest-sent-message"><time>' . app_h($sentLabel) . '</time><p>' . app_h((string) ($sentMessage['message'] ?? '')) . '</p></article>';
    }

    ob_start();
    ?>
<section class="guest-event-page">
  <header class="guest-welcome">
    <h1><?= app_h($guestName) ?>さん、ようこそ</h1>
  </header>

  <section class="card guest-card guest-event-card">
    <?php if ($message !== ''): ?>
      <div class="message-box">
        <p><?= nl2br(app_h($message)) ?></p>
      </div>
    <?php endif; ?>
    <?php if ($photoHtml !== ''): ?>
      <div class="photo-grid">
        <?= $photoHtml ?>
      </div>
    <?php endif; ?>
    <?= $mapHtml ?>
  </section>

  <section class="card guest-card guest-profile-card">
    <p class="eyebrow">Profile</p>
    <h2>プロフィール</h2>
    <div class="hero-profile">
      <?= app_render_profile_avatar($profileAvatarUrl, $profileDisplayName) ?>
      <div class="guest-profile-copy">
        <h3><?= app_h($profileDisplayName) ?></h3>
        <?php if ($profileHeadline !== ''): ?>
          <p class="lead"><?= app_h($profileHeadline) ?></p>
        <?php endif; ?>
        <?php if ($profileBio !== ''): ?>
          <p><?= nl2br(app_h($profileBio)) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($linkHtml !== ''): ?>
      <div class="guest-profile-links">
        <h3>SNS・リンク</h3>
        <div class="social-list">
          <?= $linkHtml ?>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($showMessageBox || $messageHistoryHtml !== ''): ?>
    <section class="card guest-card guest-message-section">
      <h2>ひとことメッセージ</h2>
      <?php if ($showMessageBox): ?>
        <?= render_guest_message_box((string) ($event['public_token'] ?? '')) ?>
      <?php endif; ?>
      <?php if ($messageHistoryHtml !== ''): ?>
        <div class="guest-sent-list">
          <h3>送信済み</h3>
          <?= $messageHistoryHtml ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</section>
<footer class="guest-footer">
  <a class="guest-app-link" href="<?= app_h(app_url('/')) ?>">
    <span class="guest-app-icon">1G1A</span>
    <span>このアプリを見る</span>
  </a>
</footer>
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
        <span class="muted"><?= app_h(app_guest_display_name($event)) ?></span>
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
        $serviceName = (string) ($link['service_display_name'] ?? $link['label'] ?? $link['sns_name'] ?? 'Link');
        $copyValue = app_contact_copy_value($link, (string) (($link['raw_value'] ?? '') !== '' ? $link['raw_value'] : ($link['url'] ?? '')));
        $iconUrl = trim((string) ($link['icon_url'] ?? ''));
        $iconHtml = $iconUrl !== '' ? '<img src="' . app_h($iconUrl) . '" alt="" loading="lazy">' : '<span>' . app_h(mb_substr($serviceName, 0, 1, 'UTF-8')) . '</span>';
        $linkHtml .= '<button class="contact-icon-button" type="button" data-contact-action data-service="' . app_h($serviceName) . '" data-copy="' . app_h($copyValue) . '" data-url="' . app_h((string) $link['url']) . '">' . $iconHtml . '<span>' . app_h($serviceName) . '</span></button>';
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
        <h2><?= app_h(app_guest_display_name($event)) ?></h2>
        <p class="lead"><?= app_h((string) ($event['profile_headline'] ?? '')) ?></p>
        <p><?= nl2br(app_h((string) ($event['profile_bio'] ?? ''))) ?></p>
      </div>
    </div>
    <div class="message-box">
      <p><?= nl2br(app_h((string) ($event['body'] ?? ''))) ?: '今日はありがとうございました。' ?></p>
    </div>
  </div>
  <div class="card guest-card">
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
