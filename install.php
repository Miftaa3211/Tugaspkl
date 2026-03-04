<?php
/**
 * Xcodehoster v11 - Web Installer
 * Programmer: Kurniawan | xcode.or.id
 * PHP 8.2/8.3 Compatible
 */

define('INSTALL_LOCK', __DIR__ . '/.installed');
define('SUPPORT_DIR', __DIR__ . '/support');
define('FILEMANAGER_DIR', __DIR__ . '/filemanager');

// ─── ALREADY INSTALLED CHECK ─────────────────────────────────────────────────
if (file_exists(INSTALL_LOCK)) {
    showAlreadyInstalled();
    exit;
}

$step   = $_POST['step']   ?? 'form';
$errors = [];
$result = [];

// ─── PROCESS INSTALLATION ────────────────────────────────────────────────────
if ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ipserver     = trim($_POST['ipserver']     ?? '');
    $domain       = trim($_POST['domain']       ?? '');
    $zone_id      = trim($_POST['zone_id']      ?? '');
    $admin_email  = trim($_POST['admin_email']  ?? '');
    $api_key      = trim($_POST['api_key']      ?? '');
    $cf_key       = trim($_POST['cf_key']       ?? '');
    $cf_pem       = trim($_POST['cf_pem']       ?? '');

    // Validation
    if (empty($ipserver))    $errors[] = 'IP Server wajib diisi.';
    if (empty($domain))      $errors[] = 'Domain utama wajib diisi.';
    if (empty($zone_id))     $errors[] = 'Zone ID Cloudflare wajib diisi.';
    if (empty($admin_email)) $errors[] = 'Email Admin wajib diisi.';
    if (empty($api_key))     $errors[] = 'API Key wajib diisi.';
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL) && !empty($admin_email))
        $errors[] = 'Format Email Admin tidak valid.';
    if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain) && !empty($domain))
        $errors[] = 'Format domain tidak valid.';

    if (empty($errors)) {
        $result = runInstallation($ipserver, $domain, $zone_id, $admin_email, $api_key, $cf_key, $cf_pem);
        if ($result['success']) {
            // Create lock file
            file_put_contents(INSTALL_LOCK, date('Y-m-d H:i:s') . "\nDomain: $domain\nIP: $ipserver\n");
            showSuccess($result, $domain);
            exit;
        } else {
            $errors = array_merge($errors, $result['errors']);
        }
    }
}

// ─── INSTALLATION LOGIC ───────────────────────────────────────────────────────
function runInstallation(string $ip, string $domain, string $zoneId, string $email, string $apiKey, string $cfKey, string $cfPem): array
{
    $errors = [];
    $logs   = [];

    // 1. Create required directories
    $dirs = [
        '/home/root', '/home/pma', '/home/www',
        '/home/datauser', '/home/xcodehoster',
        '/home/datapengguna', '/home/domain',
        '/home/checkdata', '/home/checkdata2',
        '/home/filemanager', '/etc/apache2/ssl',
        '/etc/apache2/sites-available'
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true)) {
                $logs[] = "⚠️  Tidak dapat membuat direktori: $dir (mungkin perlu sudo, lanjut...)";
            } else {
                $logs[] = "✅ Direktori dibuat: $dir";
            }
        } else {
            $logs[] = "✅ Direktori sudah ada: $dir";
        }
    }

    // 2. Write .env / config
    $envContent = generateEnvConfig($ip, $domain, $zoneId, $email, $apiKey, $cfKey, $cfPem);
    if (file_put_contents(__DIR__ . '/.env', $envContent) === false) {
        $errors[] = 'Gagal menulis file .env — periksa permission direktori.';
    } else {
        $logs[] = '✅ File .env berhasil dibuat';
    }

    // 3. Process support files — replace placeholders
    $supportFiles = [
        'formdata.cgi', 'run.cgi', 'aktivasi3.cgi',
        'subdomain.conf', 'domain.conf', 'index.html'
    ];

    foreach ($supportFiles as $file) {
        $src = SUPPORT_DIR . '/' . $file;
        if (!file_exists($src)) {
            $logs[] = "⚠️  File support tidak ditemukan: $file";
            continue;
        }
        $content = file_get_contents($src);
        $content = str_replace('xcodehoster.com', $domain, $content);
        $content = str_replace('sample.xcodehoster.com', $domain, $content);
        $content = str_replace('zoneid', $zoneId, $content);
        $content = str_replace('email', $email, $content);
        $content = str_replace('globalapikey', $apiKey, $content);
        $content = str_replace('ipserver', $ip, $content);
        $content = str_replace('xcodehoster.com.pem', "$domain.pem", $content);
        $content = str_replace('xcodehoster.com.key', "$domain.key", $content);
        if (file_put_contents($src, $content) !== false) {
            $logs[] = "✅ File support diupdate: $file";
        } else {
            $logs[] = "⚠️  Gagal update file: $file";
        }
    }

    // 4. Copy support files to /home/xcodehoster
    $copyFiles = [
        'domain.conf', 'domain2.conf', 'subdomain.conf', 'index.html',
        'bootstrap.min.css', 'hosting.jpg', 'xcodehoster21x.png', 'coverxcodehoster.png'
    ];
    foreach ($copyFiles as $file) {
        $src  = SUPPORT_DIR . '/' . $file;
        $dest = '/home/xcodehoster/' . $file;
        if (file_exists($src)) {
            if (@copy($src, $dest)) {
                $logs[] = "✅ Disalin ke /home/xcodehoster: $file";
            } else {
                $logs[] = "⚠️  Gagal salin: $file ke /home/xcodehoster/";
            }
        }
    }

    // 5. Copy support files to /var/www/html
    $wwwFiles = [
        'index.html', 'bootstrap.min.css', 'hosting.jpg',
        'xcodehoster21x.png', 'coverxcodehoster.png'
    ];
    foreach ($wwwFiles as $file) {
        $src  = SUPPORT_DIR . '/' . $file;
        $dest = '/var/www/html/' . $file;
        if (file_exists($src)) {
            @copy($src, $dest);
        }
    }
    $logs[] = '✅ File web disalin ke /var/www/html/';

    // 6. Copy filemanager
    if (is_dir(FILEMANAGER_DIR)) {
        copyDir(FILEMANAGER_DIR, '/home/filemanager');
        $logs[] = '✅ File manager disalin ke /home/filemanager/';

        // Update domain in filemanager
        $fmIndex = '/home/filemanager/index.html';
        if (file_exists($fmIndex)) {
            $content = file_get_contents($fmIndex);
            $content = str_replace('xcodehoster.com', $domain, $content);
            file_put_contents($fmIndex, $content);
        }
    }

    // 7. Create CGI bin files
    $cgiBin = '/usr/lib/cgi-bin';
    $cgiFiles = ['formdata.cgi', 'run.cgi', 'aktivasi3.cgi'];
    if (is_dir($cgiBin)) {
        foreach ($cgiFiles as $file) {
            $src = SUPPORT_DIR . '/' . $file;
            if (file_exists($src)) {
                $dest = $cgiBin . '/' . $file;
                if (@copy($src, $dest)) {
                    @chmod($dest, 0777);
                    $logs[] = "✅ CGI file disalin: $file";
                } else {
                    $logs[] = "⚠️  Gagal salin CGI: $file (perlu sudo)";
                }
            }
        }
        // ip.txt
        @file_put_contents($cgiBin . '/ip.txt', $ip);
        $logs[] = '✅ File ip.txt dibuat di cgi-bin';
    } else {
        $logs[] = "⚠️  /usr/lib/cgi-bin tidak ditemukan — CGI files perlu disalin manual";
    }

    // 8. SSL placeholder certs
    $sslDir = '/etc/apache2/ssl';
    if (is_dir($sslDir)) {
        if (!empty($cfPem)) {
            @file_put_contents("$sslDir/$domain.pem", $cfPem);
            $logs[] = "✅ SSL PEM disimpan: $domain.pem";
        } else {
            @touch("$sslDir/$domain.pem");
            $logs[] = "⚠️  SSL PEM kosong — isi manual di $sslDir/$domain.pem";
        }
        @touch("$sslDir/$domain.key");
        $logs[] = "✅ SSL KEY placeholder dibuat: $domain.key";
    }

    // 9. Apache VirtualHost config
    $apacheSites = '/etc/apache2/sites-available';
    if (is_dir($apacheSites)) {
        $domainConf = SUPPORT_DIR . '/domain.conf';
        if (file_exists($domainConf)) {
            $vhostContent = file_get_contents($domainConf);
            $vhostContent = str_replace('sample.', '', $vhostContent);
            $vhostContent = str_replace('xcodehoster', $domain, $vhostContent);
            $destConf = "$apacheSites/$domain.conf";
            if (@file_put_contents($destConf, $vhostContent)) {
                $logs[] = "✅ Apache config dibuat: $domain.conf";
            }
        }
    }

    // 10. Generate 600 voucher codes
    $vouchers = generateVouchers(600);
    $voucherFilename = 'vouchers600.txt';
    
    // Save to multiple locations
    $voucherContent = implode("\n", $vouchers);
    $savedPaths = [];

    $voucherPaths = [
        __DIR__ . '/' . $voucherFilename,
        '/var/www/html/' . $voucherFilename,
        '/home/xcodehoster/' . $voucherFilename,
    ];

    foreach ($voucherPaths as $path) {
        if (@file_put_contents($path, $voucherContent) !== false) {
            $savedPaths[] = $path;
        }
    }

    // Also save to cgi-bin as vouchers.txt
    if (is_dir('/usr/lib/cgi-bin')) {
        @file_put_contents('/usr/lib/cgi-bin/vouchers.txt', $voucherContent);
    }

    if (!empty($savedPaths)) {
        $logs[] = "✅ 600 voucher kode berhasil dibuat: $voucherFilename";
    } else {
        $errors[] = 'Gagal membuat file voucher — periksa permission direktori.';
    }

    return [
        'success'          => empty($errors),
        'errors'           => $errors,
        'logs'             => $logs,
        'domain'           => $domain,
        'ip'               => $ip,
        'voucher_file'     => $voucherFilename,
        'voucher_count'    => 600,
    ];
}

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function generateVouchers(int $count): array
{
    $vouchers = [];
    $chars    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $used     = [];
    while (count($vouchers) < $count) {
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        if (!isset($used[$code])) {
            $used[$code] = true;
            $vouchers[]  = $code;
        }
    }
    return $vouchers;
}

function generateEnvConfig(string $ip, string $domain, string $zoneId, string $email, string $apiKey, string $cfKey, string $cfPem): string
{
    $date        = date('Y-m-d H:i:s');
    $installDate = date('Y-m-d');
    return "# Xcodehoster v11 - Configuration\n"
         . "# Generated: $date\n"
         . "# ----------------------------------------\n"
         . "APP_VERSION=11\n"
         . "INSTALL_DATE=$installDate\n\n"
         . "SERVER_IP=$ip\n"
         . "MAIN_DOMAIN=$domain\n\n"
         . "CF_ZONE_ID=$zoneId\n"
         . "CF_EMAIL=$email\n"
         . "CF_API_KEY=$apiKey\n"
         . "CF_GLOBAL_KEY=$cfKey\n\n"
         . "SSL_PEM_FILE=$domain.pem\n"
         . "SSL_KEY_FILE=$domain.key\n";
}

function copyDir(string $src, string $dst): void
{
    if (!is_dir($dst)) @mkdir($dst, 0777, true);
    foreach (scandir($src) as $file) {
        if ($file === '.' || $file === '..') continue;
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;
        if (is_dir($srcPath)) {
            copyDir($srcPath, $dstPath);
        } else {
            @copy($srcPath, $dstPath);
        }
    }
}

// ─── PAGE RENDERERS ───────────────────────────────────────────────────────────
function showAlreadyInstalled(): void
{
    $lockData = file_get_contents(INSTALL_LOCK);
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Xcodehoster v11 — Already Installed</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Syne:wght@700;800&display=swap');
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root { --red: #FF3B3B; --green: #00FF87; --dark: #0A0A0F; --card: #12121A; --border: #1E1E30; }
  body { background: var(--dark); color: #fff; font-family: 'JetBrains Mono', monospace; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .box { background: var(--card); border: 1px solid var(--red); border-radius: 16px; padding: 48px; max-width: 500px; width: 90%; text-align: center; }
  .icon { font-size: 56px; margin-bottom: 16px; }
  h1 { font-family: 'Syne', sans-serif; font-size: 24px; color: var(--red); margin-bottom: 12px; }
  p { color: #888; font-size: 13px; line-height: 1.8; }
  pre { background: #0D0D14; border: 1px solid var(--border); border-radius: 8px; padding: 16px; margin-top: 20px; font-size: 11px; color: var(--green); text-align: left; }
</style>
</head>
<body>
  <div class="box">
    <div class="icon">🔒</div>
    <h1>System Already Installed</h1>
    <p>Xcodehoster v11 sudah terinstall.<br>Installer tidak dapat dijalankan ulang.</p>
    <pre><?= htmlspecialchars($lockData) ?></pre>
  </div>
</body>
</html>
    <?php
}

function showSuccess(array $result, string $domain): void
{
    $vFile = $result['voucher_file'];
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Xcodehoster v11 — Instalasi Berhasil</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@700;800;900&display=swap');
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --green: #00FF87; --blue: #0066FF; --dark: #06060F; --card: #0E0E1A;
    --border: #1A1A2E; --text: #C8C8E0; --accent: #7B61FF;
  }
  body { background: var(--dark); color: var(--text); font-family: 'JetBrains Mono', monospace; min-height: 100vh; padding: 40px 20px; }
  body::before {
    content: ''; position: fixed; top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--green), var(--accent), var(--blue));
    z-index: 100;
  }

  .container { max-width: 860px; margin: 0 auto; }
  .header { text-align: center; margin-bottom: 48px; animation: fadeDown 0.6s ease both; }
  .logo-wrap { display: inline-flex; align-items: center; gap: 12px; margin-bottom: 24px; }
  .logo-icon { width: 52px; height: 52px; background: linear-gradient(135deg, var(--green), var(--accent)); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 26px; }
  h1 { font-family: 'Syne', sans-serif; font-size: clamp(28px, 5vw, 42px); font-weight: 900; letter-spacing: -1px; }
  h1 span { color: var(--green); }
  .subtitle { color: #666; font-size: 13px; margin-top: 8px; }

  .success-banner {
    background: linear-gradient(135deg, rgba(0,255,135,0.08), rgba(123,97,255,0.08));
    border: 1px solid rgba(0,255,135,0.25);
    border-radius: 16px; padding: 28px 32px; margin-bottom: 32px;
    display: flex; align-items: center; gap: 20px;
    animation: fadeUp 0.5s 0.1s ease both;
  }
  .success-banner .big-check { font-size: 48px; flex-shrink: 0; }
  .success-banner h2 { font-family: 'Syne', sans-serif; font-size: 22px; color: var(--green); margin-bottom: 6px; }
  .success-banner p { font-size: 13px; color: #888; line-height: 1.7; }

  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; animation: fadeUp 0.5s 0.2s ease both; }
  @media (max-width: 640px) { .grid { grid-template-columns: 1fr; } }

  .card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 24px; }
  .card-label { font-size: 10px; text-transform: uppercase; letter-spacing: 2px; color: var(--accent); margin-bottom: 10px; }
  .card-title { font-family: 'Syne', sans-serif; font-size: 16px; font-weight: 700; margin-bottom: 4px; color: #fff; }

  .link-card { animation: fadeUp 0.5s 0.3s ease both; }
  .link-item {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 14px; padding: 20px 24px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 16px;
    text-decoration: none; transition: border-color 0.2s, transform 0.2s;
  }
  .link-item:hover { border-color: var(--green); transform: translateX(4px); }
  .link-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
  .link-icon.domain { background: rgba(0,102,255,0.15); }
  .link-icon.voucher { background: rgba(0,255,135,0.12); }
  .link-text-label { font-size: 10px; text-transform: uppercase; letter-spacing: 2px; color: #555; margin-bottom: 3px; }
  .link-text-url { font-size: 14px; color: var(--green); font-weight: 600; word-break: break-all; }

  .checklist { animation: fadeUp 0.5s 0.4s ease both; }
  .check-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
  .check-item:last-child { border-bottom: none; }
  .check-item .dot { color: var(--green); font-size: 18px; flex-shrink: 0; line-height: 1.2; }

  .log-box { animation: fadeUp 0.5s 0.5s ease both; }
  .log-box summary { cursor: pointer; font-size: 12px; color: #555; padding: 8px 0; user-select: none; }
  .log-box summary:hover { color: #888; }
  .log-scroll { max-height: 240px; overflow-y: auto; background: #060610; border: 1px solid var(--border); border-radius: 10px; padding: 16px; margin-top: 12px; }
  .log-scroll::-webkit-scrollbar { width: 4px; }
  .log-scroll::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
  .log-line { font-size: 11px; line-height: 2; color: #555; }
  .log-line.ok { color: #3DBA6F; }
  .log-line.warn { color: #F0A500; }

  .next-steps { background: var(--card); border: 1px solid var(--border); border-left: 3px solid var(--accent); border-radius: 14px; padding: 24px; margin-top: 24px; animation: fadeUp 0.5s 0.6s ease both; }
  .next-steps h3 { font-family: 'Syne', sans-serif; font-size: 16px; margin-bottom: 16px; color: var(--accent); }
  .step { display: flex; gap: 12px; margin-bottom: 12px; font-size: 12px; line-height: 1.7; }
  .step-num { background: var(--accent); color: #fff; border-radius: 50%; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; margin-top: 1px; }
  .step-num.warn { background: #F0A500; }
  .step-num.ok { background: var(--green); color: #000; }
  code { background: rgba(255,255,255,0.07); padding: 2px 6px; border-radius: 4px; font-size: 11px; }

  @keyframes fadeDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body>
<div class="container">

  <div class="header">
    <div class="logo-wrap">
      <div class="logo-icon">⚡</div>
      <div>
        <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:900;letter-spacing:-1px;">Xcode<span style="color:var(--green)">hoster</span></div>
        <div style="font-size:10px;color:#444;letter-spacing:2px;text-transform:uppercase;">v11 Web Installer</div>
      </div>
    </div>
    <h1>Instalasi <span>Berhasil</span> 🎉</h1>
    <p class="subtitle">Server berhasil dikonfigurasi · <?= date('d M Y, H:i') ?> WIB</p>
  </div>

  <!-- Success Banner -->
  <div class="success-banner">
    <div class="big-check">✅</div>
    <div>
      <h2>Xcodehoster v11 aktif!</h2>
      <p>
        ✅ Konfigurasi sistem berhasil disimpan<br>
        ✅ Domain <strong style="color:#fff"><?= htmlspecialchars($domain) ?></strong> berhasil dikonfigurasi<br>
        ✅ <?= $result['voucher_count'] ?> kode voucher berhasil dibuat
      </p>
    </div>
  </div>

  <!-- Stats Grid -->
  <div class="grid">
    <div class="card">
      <div class="card-label">Domain Utama</div>
      <div class="card-title"><?= htmlspecialchars($domain) ?></div>
      <div style="font-size:11px;color:#555;margin-top:4px;">Apache VirtualHost terkonfigurasi</div>
    </div>
    <div class="card">
      <div class="card-label">Server IP</div>
      <div class="card-title"><?= htmlspecialchars($result['ip']) ?></div>
      <div style="font-size:11px;color:#555;margin-top:4px;">Disimpan di ip.txt · Cloudflare DNS</div>
    </div>
    <div class="card">
      <div class="card-label">Voucher Dibuat</div>
      <div class="card-title" style="color:var(--green)"><?= $result['voucher_count'] ?> kode</div>
      <div style="font-size:11px;color:#555;margin-top:4px;"><?= htmlspecialchars($vFile) ?></div>
    </div>
    <div class="card">
      <div class="card-label">PHP Version</div>
      <div class="card-title" style="color:var(--accent)"><?= PHP_VERSION ?></div>
      <div style="font-size:11px;color:#555;margin-top:4px;">Target: PHP 8.3</div>
    </div>
  </div>

  <!-- Links -->
  <div class="link-card">
    <a class="link-item" href="https://<?= htmlspecialchars($domain) ?>" target="_blank">
      <div class="link-icon domain">🌐</div>
      <div>
        <div class="link-text-label">🔗 Akses Website</div>
        <div class="link-text-url">https://<?= htmlspecialchars($domain) ?></div>
      </div>
    </a>
    <a class="link-item" href="https://<?= htmlspecialchars($domain) ?>/<?= htmlspecialchars($vFile) ?>" target="_blank">
      <div class="link-icon voucher">🎟️</div>
      <div>
        <div class="link-text-label">🎟️ File Voucher</div>
        <div class="link-text-url">https://<?= htmlspecialchars($domain) ?>/<?= htmlspecialchars($vFile) ?></div>
      </div>
    </a>
    <a class="link-item" href="https://<?= htmlspecialchars($domain) ?>/cgi-bin/formdata.cgi" target="_blank">
      <div class="link-icon" style="background:rgba(123,97,255,0.15);">📝</div>
      <div>
        <div class="link-text-label">📋 Form Pendaftaran Hosting</div>
        <div class="link-text-url">https://<?= htmlspecialchars($domain) ?>/cgi-bin/formdata.cgi</div>
      </div>
    </a>
  </div>

  <!-- Checklist -->
  <div class="card checklist" style="margin-top:24px;">
    <div class="card-label" style="margin-bottom:16px;">Ringkasan Instalasi</div>
    <div class="check-item"><span class="dot">✅</span><span>File <code>.env</code> konfigurasi sistem berhasil dibuat</span></div>
    <div class="check-item"><span class="dot">✅</span><span>File support (CGI, HTML, conf) telah diupdate dengan domain & IP</span></div>
    <div class="check-item"><span class="dot">✅</span><span>File Manager disalin ke <code>/home/filemanager</code></span></div>
    <div class="check-item"><span class="dot">✅</span><span>Apache VirtualHost config dibuat di <code>/etc/apache2/sites-available/</code></span></div>
    <div class="check-item"><span class="dot">✅</span><span>600 kode voucher unik dibuat dan disimpan di <code><?= htmlspecialchars($vFile) ?></code></span></div>
    <div class="check-item"><span class="dot">✅</span><span>Lock file dibuat — installer tidak bisa dijalankan ulang</span></div>
  </div>

  <!-- Install Log -->
  <div class="card log-box" style="margin-top:24px;">
    <details>
      <summary>📋 Lihat log instalasi lengkap (<?= count($result['logs']) ?> entri)</summary>
      <div class="log-scroll">
        <?php foreach ($result['logs'] as $log): ?>
          <div class="log-line <?= str_starts_with($log, '✅') ? 'ok' : 'warn' ?>">
            <?= htmlspecialchars($log) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  </div>

  <!-- Next Steps -->
  <div class="next-steps">
    <h3>📌 Langkah Selanjutnya</h3>
    <div class="step">
      <div class="step-num warn">1</div>
      <div>Upload SSL Certificate ke <code>/etc/apache2/ssl/<?= htmlspecialchars($domain) ?>.pem</code> dan <code>.key</code> agar HTTPS aktif.</div>
    </div>
    <div class="step">
      <div class="step-num warn">2</div>
      <div>Jalankan <code>a2ensite <?= htmlspecialchars($domain) ?>.conf</code> lalu <code>service apache2 restart</code> untuk mengaktifkan VirtualHost.</div>
    </div>
    <div class="step">
      <div class="step-num warn">3</div>
      <div>Aktifkan CGI: <code>sudo a2enmod cgi</code> lalu pastikan permission <code>/usr/lib/cgi-bin</code> adalah 777.</div>
    </div>
    <div class="step">
      <div class="step-num ok">4</div>
      <div>Akses <a href="https://<?= htmlspecialchars($domain) ?>" style="color:var(--green)" target="_blank">https://<?= htmlspecialchars($domain) ?></a> untuk memverifikasi halaman welcome.</div>
    </div>
    <div class="step">
      <div class="step-num ok">5</div>
      <div>Bagikan kode voucher ke pengguna via link: <a href="https://<?= htmlspecialchars($domain) ?>/<?= htmlspecialchars($vFile) ?>" style="color:var(--green)" target="_blank">https://<?= htmlspecialchars($domain) ?>/<?= htmlspecialchars($vFile) ?></a></div>
    </div>
  </div>

  <div style="text-align:center;margin-top:40px;font-size:11px;color:#333;">
    Xcodehoster v11 · PT. Teknologi Server Indonesia · xcode.or.id<br>
    <span style="color:#1A1A2E;">install.php</span>
  </div>
</div>
</body>
</html>
    <?php
}

// ─── RENDER FORM PAGE ─────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Xcodehoster v11 — Web Installer</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Syne:wght@700;800;900&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --green: #00FF87; --accent: #7B61FF; --blue: #0066FF;
    --dark: #06060F; --card: #0C0C18; --card2: #10101E;
    --border: #1A1A30; --text: #B0B0CC; --red: #FF4D4D;
  }

  body {
    background: var(--dark); color: var(--text);
    font-family: 'JetBrains Mono', monospace;
    min-height: 100vh; display: flex; flex-direction: column;
  }

  /* Animated grid bg */
  body::before {
    content: '';
    position: fixed; inset: 0; z-index: 0;
    background-image:
      linear-gradient(rgba(123,97,255,0.03) 1px, transparent 1px),
      linear-gradient(90deg, rgba(123,97,255,0.03) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events: none;
  }

  /* Top bar */
  .topbar {
    position: fixed; top: 0; left: 0; right: 0; height: 2px; z-index: 999;
    background: linear-gradient(90deg, var(--green), var(--accent), var(--blue), var(--green));
    background-size: 200% 100%;
    animation: slide 3s linear infinite;
  }
  @keyframes slide { from { background-position: 0 0; } to { background-position: 200% 0; } }

  main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 60px 20px; position: relative; z-index: 1; }

  .wrapper { width: 100%; max-width: 700px; }

  /* Header */
  .header { text-align: center; margin-bottom: 40px; animation: rise 0.7s ease both; }
  .logo { display: inline-flex; align-items: center; gap: 14px; margin-bottom: 20px; }
  .logo-box {
    width: 56px; height: 56px; border-radius: 16px;
    background: linear-gradient(135deg, var(--green) 0%, var(--accent) 100%);
    display: flex; align-items: center; justify-content: center; font-size: 28px;
    box-shadow: 0 0 32px rgba(0,255,135,0.3);
  }
  .logo-text { text-align: left; }
  .logo-name { font-family: 'Syne', sans-serif; font-size: 26px; font-weight: 900; letter-spacing: -1px; }
  .logo-name em { color: var(--green); font-style: normal; }
  .logo-ver { font-size: 10px; color: #444; letter-spacing: 3px; text-transform: uppercase; }
  .tagline { font-size: 14px; color: #555; margin-top: 6px; }

  /* Card */
  .card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 20px; overflow: hidden;
    box-shadow: 0 0 60px rgba(0,0,0,0.5);
    animation: rise 0.7s 0.1s ease both;
  }

  .card-header {
    background: var(--card2); border-bottom: 1px solid var(--border);
    padding: 20px 28px; display: flex; align-items: center; gap: 12px;
  }
  .dot-row { display: flex; gap: 6px; }
  .dot-row span { width: 10px; height: 10px; border-radius: 50%; }
  .dot-row .r { background: #FF5F57; }
  .dot-row .y { background: #FFBC2E; }
  .dot-row .g { background: #28C840; }
  .card-header-text { font-size: 12px; color: #555; margin-left: 6px; }

  .card-body { padding: 32px 28px; }

  /* Section divider */
  .section-label {
    display: flex; align-items: center; gap: 10px;
    font-size: 10px; text-transform: uppercase; letter-spacing: 2px;
    color: var(--accent); margin-bottom: 18px; margin-top: 28px;
  }
  .section-label:first-child { margin-top: 0; }
  .section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

  /* Form */
  .field { margin-bottom: 16px; }
  label { display: block; font-size: 11px; color: #666; margin-bottom: 7px; letter-spacing: 0.5px; }
  label span { color: var(--red); margin-left: 3px; }

  input[type="text"], input[type="email"], input[type="password"], textarea {
    width: 100%; background: #080812; border: 1px solid var(--border);
    border-radius: 10px; padding: 12px 16px; color: #fff;
    font-family: 'JetBrains Mono', monospace; font-size: 13px;
    transition: border-color 0.2s, box-shadow 0.2s; outline: none;
  }
  input:focus, textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(123,97,255,0.12);
  }
  input::placeholder, textarea::placeholder { color: #333; }
  textarea { resize: vertical; min-height: 100px; }

  .field-hint { font-size: 10px; color: #3A3A55; margin-top: 5px; line-height: 1.5; }

  /* Two col */
  .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 580px) { .two-col { grid-template-columns: 1fr; } }

  /* Errors */
  .errors {
    background: rgba(255,77,77,0.08); border: 1px solid rgba(255,77,77,0.3);
    border-radius: 12px; padding: 16px 20px; margin-bottom: 24px;
  }
  .errors p { font-size: 12px; color: var(--red); margin-bottom: 4px; }
  .errors p:last-child { margin-bottom: 0; }
  .errors p::before { content: '✕ '; }

  /* Submit */
  .btn-wrap { margin-top: 28px; }
  button[type="submit"] {
    width: 100%; padding: 16px; border: none; border-radius: 12px;
    background: linear-gradient(135deg, var(--green), var(--accent));
    color: #000; font-family: 'Syne', sans-serif; font-size: 16px; font-weight: 800;
    letter-spacing: 0.5px; cursor: pointer;
    transition: opacity 0.2s, transform 0.2s;
    box-shadow: 0 4px 24px rgba(0,255,135,0.25);
  }
  button[type="submit"]:hover { opacity: 0.9; transform: translateY(-1px); }
  button[type="submit"]:active { transform: translateY(0); }

  .spinner { display: none; }

  /* Info bar */
  .info-bar {
    display: flex; gap: 16px; flex-wrap: wrap;
    background: rgba(123,97,255,0.06); border: 1px solid rgba(123,97,255,0.15);
    border-radius: 12px; padding: 16px 20px; margin-bottom: 24px;
    font-size: 11px;
  }
  .info-tag { display: flex; align-items: center; gap: 6px; color: #555; }
  .info-tag span { color: var(--green); font-weight: 600; }

  footer { text-align: center; padding: 24px; font-size: 10px; color: #222; position: relative; z-index: 1; }

  @keyframes rise { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body>
<div class="topbar"></div>

<main>
<div class="wrapper">

  <!-- Header -->
  <div class="header">
    <div class="logo">
      <div class="logo-box">⚡</div>
      <div class="logo-text">
        <div class="logo-name">Xcode<em>hoster</em></div>
        <div class="logo-ver">Web Installer · v11</div>
      </div>
    </div>
    <p class="tagline">Ubuntu Server 24.04 · Apache · PHP 8.3 · MySQL · Cloudflare</p>
  </div>

  <!-- Info bar -->
  <div class="info-bar">
    <div class="info-tag">📦 <span>v11</span> 2025</div>
    <div class="info-tag">🐘 PHP <span><?= PHP_VERSION ?></span></div>
    <div class="info-tag">⏰ <span><?= date('d M Y') ?></span></div>
    <div class="info-tag">🔐 Secure · <span>PHP Native</span></div>
  </div>

  <!-- Main Card -->
  <div class="card">
    <div class="card-header">
      <div class="dot-row"><span class="r"></span><span class="y"></span><span class="g"></span></div>
      <div class="card-header-text">install.php — Xcodehoster v11 Setup Configuration</div>
    </div>

    <div class="card-body">

      <?php if (!empty($errors)): ?>
      <div class="errors">
        <?php foreach ($errors as $e): ?>
          <p><?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="install.php" onsubmit="handleSubmit(this)">
        <input type="hidden" name="step" value="install">

        <!-- SERVER -->
        <div class="section-label">🖥️ Konfigurasi Server</div>
        <div class="two-col">
          <div class="field">
            <label>IP Server Publik <span>*</span></label>
            <input type="text" name="ipserver" placeholder="103.21.244.x" value="<?= htmlspecialchars($_POST['ipserver'] ?? '') ?>" required>
            <div class="field-hint">IP publik VPS / server Ubuntu 24.04</div>
          </div>
          <div class="field">
            <label>Domain Utama <span>*</span></label>
            <input type="text" name="domain" placeholder="namadomain.com" value="<?= htmlspecialchars($_POST['domain'] ?? '') ?>" required>
            <div class="field-hint">Tanpa http:// dan tanpa www</div>
          </div>
        </div>

        <!-- CLOUDFLARE -->
        <div class="section-label">☁️ Konfigurasi Cloudflare</div>
        <div class="field">
          <label>Zone ID Cloudflare <span>*</span></label>
          <input type="text" name="zone_id" placeholder="a1b2c3d4e5f6..." value="<?= htmlspecialchars($_POST['zone_id'] ?? '') ?>" required>
          <div class="field-hint">Didapat dari dashboard Cloudflare → Pilih domain → Overview</div>
        </div>
        <div class="two-col">
          <div class="field">
            <label>Email Admin Cloudflare <span>*</span></label>
            <input type="email" name="admin_email" placeholder="admin@domain.com" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label>Global API Key Cloudflare <span>*</span></label>
            <input type="password" name="api_key" placeholder="••••••••••••••••" value="<?= htmlspecialchars($_POST['api_key'] ?? '') ?>" required>
            <div class="field-hint">My Profile → API Tokens → Global API Key</div>
          </div>
        </div>
        <div class="field">
          <label>Cloudflare Key (opsional)</label>
          <input type="text" name="cf_key" placeholder="Cloudflare Key tambahan..." value="<?= htmlspecialchars($_POST['cf_key'] ?? '') ?>">
        </div>

        <!-- SSL -->
        <div class="section-label">🔐 SSL Certificate</div>
        <div class="field">
          <label>Cloudflare PEM (opsional — bisa diisi setelah install)</label>
          <textarea name="cf_pem" placeholder="-----BEGIN CERTIFICATE-----&#10;(isi dengan isi file .pem dari Cloudflare atau SSL provider)&#10;-----END CERTIFICATE-----"><?= htmlspecialchars($_POST['cf_pem'] ?? '') ?></textarea>
          <div class="field-hint">Jika kosong, file placeholder akan dibuat. Isi manual di <code>/etc/apache2/ssl/domain.pem</code></div>
        </div>

        <div class="btn-wrap">
          <button type="submit">
            <span class="spinner" id="spinner">⏳ </span>
            ⚡ Mulai Instalasi Xcodehoster v11
          </button>
        </div>
      </form>
    </div>
  </div>

</div>
</main>

<footer>
  Xcodehoster v11 · PT. Teknologi Server Indonesia · xcode.or.id · xcodetraining.com<br>
  Programmer: Kurniawan · GNU GPL v3
</footer>

<script>
function handleSubmit(form) {
  document.getElementById('spinner').style.display = 'inline';
  form.querySelector('button[type="submit"]').disabled = true;
}
</script>
</body>
</html>
