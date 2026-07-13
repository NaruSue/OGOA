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
      <a class="button secondary" href="<?= app_h(app_url('/p/demo-preview-1g1a')) ?>">デモを見る</a>
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
  <?= render_guest_message_box((string) ($event['public_token'] ?? '')) ?>
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
        <input type="file" name="photos[]" id="share-photos" accept="image/jpeg,image/png,image/webp,image/gif" capture="environment" multiple>
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
      <div class="launch-actions">
        <button class="button primary" type="submit" data-publish="1">QRを公開</button>
        <button class="button secondary" type="submit">下書きを保存</button>
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

<section class="page narrow">
  <div class="card">
    <details class="help-accordion">
      <summary>使い方</summary>
      <p><?= app_h($profileHint) ?> 必要ならメッセージと写真を追加して、QRを公開します。</p>
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

            $stmt = $db->prepare('INSERT INTO profiles (user_id, slug, public_token, profile_name, display_name, bio, headline, is_public) VALUES (:user_id, :slug, :public_token, :profile_name, :display_name, :bio, :headline, :is_public) RETURNING id');
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
            $newProfileId = (int) $stmt->fetchColumn();
            app_save_profile_contacts($db, $newProfileId, (array) ($_POST['contacts'] ?? []));
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
        app_save_profile_contacts($db, (int) $profile['id'], (array) ($_POST['contacts'] ?? []));
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
    $contactFields = app_profile_contact_fields();
    $contactValues = $profile ? app_profile_contact_values(app_fetch_profile_links($db, (int) $profile['id'])) : [];
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
      <div class="form-block">
        <h2>連絡先</h2>
        <p class="muted">入力したものだけゲスト画面に表示されます。</p>
        <?php foreach ($contactFields as $code => $field): ?>
          <label><?= app_h($field['name']) ?>
            <input name="contacts[<?= app_h((string) $code) ?>]" value="<?= app_h((string) ($contactValues[$code] ?? '')) ?>" placeholder="<?= app_h($field['placeholder']) ?>">
          </label>
        <?php endforeach; ?>
      </div>
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

function app_profile_contact_fields(): array
{
    return [
        'line' => ['name' => 'LINE', 'label' => 'LINE', 'placeholder' => 'https://line.me/... または LINE ID'],
        'instagram' => ['name' => 'Instagram', 'label' => 'Instagram', 'placeholder' => 'https://instagram.com/yourname または @yourname'],
        'x' => ['name' => 'X', 'label' => 'X', 'placeholder' => 'https://x.com/yourname または @yourname'],
        'email' => ['name' => 'メール', 'label' => 'Email', 'placeholder' => 'name@example.com'],
        'phone' => ['name' => '電話', 'label' => 'Phone', 'placeholder' => '090-0000-0000'],
    ];
}

function app_normalize_contact_url(string $code, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if ($code === 'email') {
        return str_starts_with($value, 'mailto:') ? $value : 'mailto:' . $value;
    }

    if ($code === 'phone') {
        return str_starts_with($value, 'tel:') ? $value : 'tel:' . preg_replace('/[^\d+]/', '', $value);
    }

    if ($code === 'instagram' && str_starts_with($value, '@')) {
        return 'https://instagram.com/' . ltrim($value, '@');
    }

    if ($code === 'x' && str_starts_with($value, '@')) {
        return 'https://x.com/' . ltrim($value, '@');
    }

    if ($code === 'line' && !str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
        return 'https://line.me/ti/p/' . ltrim($value, '@');
    }

    if (!str_contains($value, ':')) {
        return 'https://' . $value;
    }

    return $value;
}

function app_profile_contact_values(array $links): array
{
    $values = [];
    foreach ($links as $link) {
        $code = (string) ($link['sns_code'] ?? '');
        if ($code !== '') {
            $values[$code] = (string) ($link['url'] ?? '');
        }
    }

    return $values;
}

function app_save_profile_contacts(PDO $db, int $profileId, array $input): void
{
    $fields = app_profile_contact_fields();
    $upsertType = $db->prepare(
        'INSERT INTO sns_types (code, name, sort_order)
         VALUES (:code, :name, :sort_order)
         ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, sort_order = EXCLUDED.sort_order
         RETURNING id'
    );
    $delete = $db->prepare('DELETE FROM profile_sns WHERE profile_id = :profile_id AND sns_type_id = :sns_type_id');
    $upsertLink = $db->prepare(
        'INSERT INTO profile_sns (profile_id, sns_type_id, label, url, sort_order, is_primary)
         VALUES (:profile_id, :sns_type_id, :label, :url, :sort_order, false)
         ON CONFLICT (profile_id, sns_type_id)
         DO UPDATE SET label = EXCLUDED.label, url = EXCLUDED.url, sort_order = EXCLUDED.sort_order, updated_at = CURRENT_TIMESTAMP'
    );

    $order = 10;
    foreach ($fields as $code => $field) {
        $upsertType->execute([
            'code' => $code,
            'name' => $field['name'],
            'sort_order' => $order,
        ]);
        $snsTypeId = (int) $upsertType->fetchColumn();
        $url = app_normalize_contact_url($code, (string) ($input[$code] ?? ''));

        if ($url === '') {
            $delete->execute(['profile_id' => $profileId, 'sns_type_id' => $snsTypeId]);
        } else {
            $upsertLink->execute([
                'profile_id' => $profileId,
                'sns_type_id' => $snsTypeId,
                'label' => $field['label'],
                'url' => $url,
                'sort_order' => $order,
            ]);
        }
        $order += 10;
    }
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
    $noticeJa = $draftMode && $event === null
        ? '通信できない時は、この画面を写真で撮っておいてください。あとでQRからアクセスできます。'
        : '私のプロフィールと連絡先です。この画面を写真で撮って、あとでQRからアクセスしてください。';
    $noticeEn = $draftMode && $event === null
        ? 'If the connection is weak, please take a photo of this screen and visit from the QR later.'
        : 'My profile and contact links are here. Please take a photo of this screen and visit from the QR later.';
    $messageJa = trim((string) ($event['body'] ?? ''));
    $messageEn = $messageJa !== ''
        ? 'You can read my social links and message later from this QR.'
        : '';
    ob_start();
    ?>
<section class="page narrow">
  <div class="card qr-card">
    <h1><?= app_h((string) $profile['display_name']) ?></h1>
    <div class="qr-frame">
      <img src="<?= $qrUrl ?>" alt="QR code">
    </div>
    <?php if ($messageJa !== ''): ?>
    <div class="qr-message">
      <p><?= nl2br(app_h($messageJa)) ?></p>
    </div>
    <?php endif; ?>
    <div class="qr-copy">
      <p><?= app_h($noticeJa) ?></p>
      <p class="muted"><?= app_h($noticeEn) ?></p>
    </div>
    <div class="qr-url">
      <span>URL</span>
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
            'meta' => '<script>window.__QR_LOCATION_CAPTURE=true;window.__QR_EVENT_ID=' . json_encode($event ? (int) $event['id'] : null) . ';window.__QR_EVENT_TOKEN=' . json_encode($token) . ';</script>',
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
        if (array_key_exists('guest_message', $_POST)) {
            $guestMessage = trim((string) ($_POST['guest_message'] ?? ''));
            if ($guestMessage === '') {
                app_flash('メッセージを入力してください。');
                app_redirect('/s/' . $token);
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
            app_set_guest_identity_cookie($viewerToken, $guestName);
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

        $viewerToken = app_guest_token_from_cookie() ?? bin2hex(random_bytes(16));
        app_upsert_guest_visitor($db, $viewerToken, $guestName);
        app_set_guest_identity_cookie($viewerToken, $guestName);
        $event = app_touch_share_event_first_access($db, (int) $event['id']) ?? $event;
        app_log_share_access($db, (int) $event['id'], (int) $event['profile_id'], $viewerToken);
        app_redirect('/s/' . $token);
    }

    if ((string) ($event['status'] ?? 'ready') !== 'ready') {
        app_render('準備中', render_preparing_page($token));
        return;
    }

    if (app_share_event_expired($event)) {
        app_render('期限切れ', render_expired_share_page(), ['chrome' => false]);
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
        app_render(app_guest_display_name($event), render_guest_name_screen_simple($event, $token), ['chrome' => false]);
        return;
    }

    $event = app_touch_share_event_first_access($db, (int) $event['id']) ?? $event;
    if (app_share_event_expired($event)) {
        app_render('期限切れ', render_expired_share_page(), ['chrome' => false]);
        return;
    }
    app_log_share_access($db, (int) $event['id'], (int) $event['profile_id'], $viewerToken);
    $photos = app_fetch_share_event_photos($db, (int) $event['id']);
    $links = app_fetch_profile_links($db, (int) $event['profile_id']);
    app_render(app_guest_display_name($event), render_guest_profile_screen_simple($event, $guestName, $photos, $links), ['chrome' => false]);
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

function render_guest_profile_screen_simple(array $event, string $guestName, array $photos, array $links): string
{
    $profileHeadline = trim((string) ($event['profile_headline'] ?? ''));
    $profileBio = trim((string) ($event['profile_bio'] ?? ''));
    $message = trim((string) ($event['body'] ?? ''));

    $photoHtml = '';
    foreach ($photos as $photo) {
        $photoHtml .= '<img src="' . app_h(app_url('/media/' . basename((string) $photo['storage_path']))) . '" alt="共有写真" loading="lazy">';
    }

    $linkHtml = '';
    foreach ($links as $link) {
        $linkHtml .= '<a class="social-link" href="' . app_h((string) $link['url']) . '" target="_blank" rel="noreferrer"><span>' . app_h((string) $link['sns_name']) . '</span><strong>' . app_h((string) ($link['label'] ?? $link['sns_name'])) . '</strong></a>';
    }

    ob_start();
    ?>
<section class="guest-hero">
  <div class="hero-copy">
    <h1><?= app_h($guestName) ?>さん、ようこそ</h1>
    <h2><?= app_h(app_guest_display_name($event)) ?></h2>
    <?php if ($profileHeadline !== ''): ?>
      <p class="lead"><?= app_h($profileHeadline) ?></p>
    <?php endif; ?>
    <?php if ($profileBio !== ''): ?>
      <p><?= nl2br(app_h($profileBio)) ?></p>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
      <div class="message-box">
        <div class="message-head">
          <span class="guest-badge">今日のメッセージ</span>
        </div>
        <p><?= nl2br(app_h($message)) ?></p>
      </div>
    <?php endif; ?>
  </div>
    <div class="card guest-card">
      <?php if ($photoHtml !== ''): ?>
        <h2>今日の写真</h2>
        <div class="photo-grid">
          <?= $photoHtml ?>
        </div>
      <?php endif; ?>
      <?php if ($linkHtml !== ''): ?>
        <h2>SNS・リンク</h2>
        <div class="social-list">
          <?= $linkHtml ?>
        </div>
      <?php endif; ?>
      <?= render_guest_message_box((string) ($event['public_token'] ?? '')) ?>
    </div>
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
        <h2><?= app_h(app_guest_display_name($event)) ?></h2>
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
