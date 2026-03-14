<?php
session_start();

// Baca data user dari config.php filemanager
$config_file = __DIR__ . '/config.php';
$username = '';
$name = basename(__DIR__); // nama folder = nama subdomain user

// Baca domain dari maindomain.txt
$maindomain = trim(@file_get_contents('/usr/lib/cgi-bin/maindomain.txt')) ?: 'tugaspkl.my.id';

// Cek apakah sudah login via session filemanager
// Panel ini diakses setelah login, jadi cek session
$is_logged_in = isset($_SESSION['panel_logged_in']) && $_SESSION['panel_logged_in'] === true;

// Handle login form
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $input_user = trim($_POST['username'] ?? '');
    $input_pass = trim($_POST['password'] ?? '');
    
    // Baca password hash dari config.php
    if (file_exists($config_file)) {
        include $config_file;
        if (isset($auth_users['admin']) && password_verify($input_pass, $auth_users['admin']) && $input_user === 'admin') {
            $_SESSION['panel_logged_in'] = true;
            $_SESSION['panel_name'] = $name;
            $is_logged_in = true;
        } else {
            $login_error = 'Username atau password salah!';
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: panel.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Xcodehoster Panel — <?= htmlspecialchars($name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@600;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #060810;
    --bg2: #0C0F1E;
    --bg3: #111428;
    --border: #1E2240;
    --green: #00FF87;
    --blue: #4D9FFF;
    --purple: #9B6DFF;
    --red: #FF4D6D;
    --yellow: #FFD166;
    --text: #C8CFEA;
    --muted: #4A5075;
    --white: #EEF0FF;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Space Mono', monospace;
    min-height: 100vh;
    overflow-x: hidden;
}

/* Grid background */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: 
        linear-gradient(rgba(77,159,255,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(77,159,255,.04) 1px, transparent 1px);
    background-size: 32px 32px;
    pointer-events: none;
    z-index: 0;
}

/* Glow top bar */
.topbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--purple), var(--blue), var(--green), var(--blue), var(--purple));
    background-size: 300% 100%;
    animation: shimmer 4s linear infinite;
    z-index: 100;
}
@keyframes shimmer { from { background-position: 0 0; } to { background-position: 300% 0; } }

/* ===== LOGIN PAGE ===== */
.login-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    position: relative;
    z-index: 1;
}

.login-box {
    width: 100%;
    max-width: 420px;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 40px;
    box-shadow: 0 0 80px rgba(77,159,255,.08), 0 0 0 1px rgba(77,159,255,.05);
    animation: fadeUp .5s ease;
}

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.login-logo {
    text-align: center;
    margin-bottom: 32px;
}

.login-logo img {
    width: 72px;
    height: 72px;
    border-radius: 18px;
    margin-bottom: 12px;
    filter: drop-shadow(0 0 16px rgba(0,255,135,.3));
}

.login-logo h1 {
    font-family: 'Syne', sans-serif;
    font-size: 22px;
    font-weight: 900;
    color: var(--white);
    letter-spacing: -0.5px;
}

.login-logo h1 span { color: var(--green); }

.login-logo p {
    font-size: 11px;
    color: var(--muted);
    margin-top: 4px;
    letter-spacing: 2px;
    text-transform: uppercase;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--muted);
    margin-bottom: 8px;
}

.form-group input {
    width: 100%;
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 16px;
    color: var(--white);
    font-family: 'Space Mono', monospace;
    font-size: 13px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}

.form-group input:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(77,159,255,.1);
}

.btn-login {
    width: 100%;
    background: linear-gradient(135deg, var(--blue), var(--purple));
    border: none;
    border-radius: 10px;
    padding: 13px;
    color: #fff;
    font-family: 'Syne', sans-serif;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 8px;
    transition: opacity .2s, transform .1s;
    letter-spacing: 0.5px;
}

.btn-login:hover { opacity: .9; transform: translateY(-1px); }
.btn-login:active { transform: translateY(0); }

.error-msg {
    background: rgba(255,77,109,.1);
    border: 1px solid rgba(255,77,109,.3);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12px;
    color: var(--red);
    margin-bottom: 16px;
    text-align: center;
}

/* ===== DASHBOARD ===== */
.dashboard {
    min-height: 100vh;
    position: relative;
    z-index: 1;
}

/* Header */
.header {
    background: var(--bg2);
    border-bottom: 1px solid var(--border);
    padding: 0 32px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 50;
    backdrop-filter: blur(12px);
}

.header-logo {
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-logo img {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    filter: drop-shadow(0 0 8px rgba(0,255,135,.3));
}

.header-logo span {
    font-family: 'Syne', sans-serif;
    font-size: 16px;
    font-weight: 900;
    color: var(--white);
}

.header-logo span em {
    color: var(--green);
    font-style: normal;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 16px;
}

.user-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 30px;
    padding: 6px 14px 6px 8px;
}

.user-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--blue), var(--purple));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    font-family: 'Syne', sans-serif;
}

.user-badge span {
    font-size: 12px;
    color: var(--text);
}

.btn-logout {
    background: rgba(255,77,109,.1);
    border: 1px solid rgba(255,77,109,.2);
    border-radius: 8px;
    padding: 7px 14px;
    color: var(--red);
    font-family: 'Space Mono', monospace;
    font-size: 11px;
    cursor: pointer;
    text-decoration: none;
    transition: background .2s;
}
.btn-logout:hover { background: rgba(255,77,109,.2); }

/* Main content */
.main {
    padding: 32px;
    max-width: 1100px;
    margin: 0 auto;
}

/* Welcome banner */
.welcome-banner {
    background: linear-gradient(135deg, rgba(77,159,255,.08), rgba(155,109,255,.08));
    border: 1px solid rgba(77,159,255,.15);
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    gap: 16px;
}

.welcome-icon { font-size: 36px; }

.welcome-text h2 {
    font-family: 'Syne', sans-serif;
    font-size: 20px;
    font-weight: 800;
    color: var(--white);
    margin-bottom: 4px;
}

.welcome-text p {
    font-size: 12px;
    color: var(--muted);
}

/* Info cards */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.info-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px 20px;
    transition: border-color .2s, transform .2s;
}

.info-card:hover {
    border-color: rgba(77,159,255,.3);
    transform: translateY(-2px);
}

.info-card-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--muted);
    margin-bottom: 8px;
}

.info-card-value {
    font-family: 'Syne', sans-serif;
    font-size: 14px;
    font-weight: 700;
    color: var(--white);
    word-break: break-all;
}

.info-card-value.green { color: var(--green); }
.info-card-value.blue { color: var(--blue); }
.info-card-value.purple { color: var(--purple); }
.info-card-value.yellow { color: var(--yellow); }

/* Section title */
.section-title {
    font-family: 'Syne', sans-serif;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 3px;
    color: var(--muted);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* Tool cards */
.tools-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.tool-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 24px;
    text-decoration: none;
    display: block;
    transition: border-color .2s, transform .2s, box-shadow .2s;
    position: relative;
    overflow: hidden;
}

.tool-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    opacity: 0;
    transition: opacity .2s;
}

.tool-card.fm::before { background: linear-gradient(90deg, var(--blue), var(--purple)); }
.tool-card.pma::before { background: linear-gradient(90deg, var(--yellow), var(--green)); }

.tool-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 40px rgba(0,0,0,.3);
}

.tool-card.fm:hover { border-color: rgba(77,159,255,.4); }
.tool-card.pma:hover { border-color: rgba(255,209,102,.4); }

.tool-card:hover::before { opacity: 1; }

.tool-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin-bottom: 16px;
}

.tool-card.fm .tool-icon { background: rgba(77,159,255,.12); }
.tool-card.pma .tool-icon { background: rgba(255,209,102,.1); }

.tool-card h3 {
    font-family: 'Syne', sans-serif;
    font-size: 16px;
    font-weight: 800;
    color: var(--white);
    margin-bottom: 6px;
}

.tool-card p {
    font-size: 11px;
    color: var(--muted);
    line-height: 1.7;
    margin-bottom: 16px;
}

.tool-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 11px;
    font-family: 'Space Mono', monospace;
    font-weight: 700;
    transition: opacity .2s;
}

.tool-card.fm .tool-btn {
    background: rgba(77,159,255,.15);
    color: var(--blue);
    border: 1px solid rgba(77,159,255,.2);
}

.tool-card.pma .tool-btn {
    background: rgba(255,209,102,.1);
    color: var(--yellow);
    border: 1px solid rgba(255,209,102,.2);
}

.tool-btn:hover { opacity: .8; }

/* Status bar */
.status-bar {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}

.status-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: var(--muted);
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 6px var(--green);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .4; }
}

.status-item span.val { color: var(--text); }

/* Footer */
.footer {
    text-align: center;
    padding: 24px 32px;
    font-size: 11px;
    color: var(--muted);
    border-top: 1px solid var(--border);
    margin-top: 32px;
}

@media (max-width: 600px) {
    .header { padding: 0 16px; }
    .main { padding: 20px 16px; }
    .tools-grid { grid-template-columns: 1fr; }
    .info-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>
<div class="topbar"></div>

<?php if (!$is_logged_in): ?>
<!-- ===== LOGIN PAGE ===== -->
<div class="login-wrap">
    <div class="login-box">
        <div class="login-logo">
            <img src="/xcodehoster21x.png" alt="Xcodehoster" onerror="this.style.display='none'">
            <h1>Xcode<span>hoster</span></h1>
            <p>User Panel v11</p>
        </div>

        <?php if ($login_error): ?>
        <div class="error-msg">⚠️ <?= htmlspecialchars($login_error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="admin" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn-login">🔐 Masuk ke Panel</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ===== DASHBOARD ===== -->
<?php
// Ambil info user
$subdomain = $name;
$domain_user = '';
$db_name = $subdomain;

// Cari domain dari files
$domain_file = "/home/domain/";
if (is_dir($domain_file)) {
    $domains = scandir($domain_file);
    foreach ($domains as $d) {
        if ($d !== '.' && $d !== '..') {
            $content = trim(file_get_contents($domain_file . $d));
            if ($content === $d) { $domain_user = $d; break; }
        }
    }
}

$php_version = PHP_VERSION;
$disk_free = round(disk_free_space('/') / 1073741824, 1);
$disk_total = round(disk_total_space('/') / 1073741824, 1);
?>
<div class="dashboard">
    <div class="header">
        <div class="header-logo">
            <img src="/xcodehoster21x.png" alt="Xcodehoster" onerror="this.style.display='none'">
            <span>Xcode<em>hoster</em></span>
        </div>
        <div class="header-right">
            <div class="user-badge">
                <div class="user-avatar"><?= strtoupper(substr($subdomain, 0, 1)) ?></div>
                <span><?= htmlspecialchars($subdomain) ?></span>
            </div>
            <a href="?logout=1" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="main">
        <!-- Welcome -->
        <div class="welcome-banner">
            <div class="welcome-icon">👋</div>
            <div class="welcome-text">
                <h2>Selamat datang, <?= htmlspecialchars($subdomain) ?>!</h2>
                <p>Kelola website dan database kamu dari panel ini.</p>
            </div>
        </div>

        <!-- Info Cards -->
        <div class="section-title">Informasi Akun</div>
        <div class="info-grid">
            <div class="info-card">
                <div class="info-card-label">Subdomain</div>
                <div class="info-card-value blue"><?= htmlspecialchars($subdomain) ?>.<?= htmlspecialchars($maindomain) ?></div>
            </div>
            <?php if ($domain_user): ?>
            <div class="info-card">
                <div class="info-card-label">Domain</div>
                <div class="info-card-value green"><?= htmlspecialchars($domain_user) ?></div>
            </div>
            <?php endif; ?>
            <div class="info-card">
                <div class="info-card-label">Database</div>
                <div class="info-card-value purple"><?= htmlspecialchars($db_name) ?></div>
            </div>
            <div class="info-card">
                <div class="info-card-label">PHP Version</div>
                <div class="info-card-value yellow"><?= $php_version ?></div>
            </div>
            <div class="info-card">
                <div class="info-card-label">Storage</div>
                <div class="info-card-value"><?= $disk_free ?> GB free / <?= $disk_total ?> GB</div>
            </div>
            <div class="info-card">
                <div class="info-card-label">Status</div>
                <div class="info-card-value green">● Aktif</div>
            </div>
        </div>

        <!-- Tools -->
        <div class="section-title">Kelola Website</div>
        <div class="tools-grid">
            <!-- File Manager -->
            <a href="login.php" class="tool-card fm" target="_blank">
                <div class="tool-icon">📁</div>
                <h3>File Manager</h3>
                <p>Upload, edit, dan kelola file website kamu. Support drag & drop, zip/unzip, dan editor kode.</p>
                <div class="tool-btn">→ Buka File Manager</div>
            </a>

            <!-- phpMyAdmin -->
            <a href="phpmyadmin/" class="tool-card pma" target="_blank">
                <div class="tool-icon">🗄️</div>
                <h3>phpMyAdmin</h3>
                <p>Kelola database MySQL kamu. Import/export database, buat tabel, dan jalankan query SQL.</p>
                <div class="tool-btn">→ Buka phpMyAdmin</div>
            </a>
        </div>

        <!-- Status -->
        <div class="section-title">Status Server</div>
        <div class="status-bar">
            <div class="status-item">
                <div class="status-dot"></div>
                <span>Apache: <span class="val">Running</span></span>
            </div>
            <div class="status-item">
                <div class="status-dot"></div>
                <span>MySQL: <span class="val">Running</span></span>
            </div>
            <div class="status-item">
                <div class="status-dot"></div>
                <span>PHP: <span class="val"><?= $php_version ?></span></span>
            </div>
            <div class="status-item">
                <span>🕐 <span class="val"><?= date('d M Y, H:i') ?> WIB</span></span>
            </div>
        </div>
    </div>

    <div class="footer">
        Xcodehoster v11 · PT. Teknologi Server Indonesia · 
        <a href="https://xcode.co.id" style="color:var(--blue);text-decoration:none;">xcode.co.id</a>
    </div>
</div>

<?php endif; ?>
</body>
</html>
