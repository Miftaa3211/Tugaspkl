<?php
/**
 * Xcodehoster v11 - Web Installer
 * X-Code Media - xcode.or.id
 */

// Keamanan: hanya bisa diakses jika file lock belum ada
define('LOCK_FILE', __DIR__ . '/.installed');
define('LOG_FILE', __DIR__ . '/install.log');

session_start();

function logMsg(string $msg): void {
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

function runCmd(string $cmd): array {
    exec($cmd . ' 2>&1', $output, $code);
    return ['output' => implode("\n", $output), 'code' => $code];
}

function sedReplace(string $file, string $from, string $to): bool {
    if (!file_exists($file)) return false;
    $content = str_replace($from, $to, file_get_contents($file));
    return file_put_contents($file, $content) !== false;
}

function generateVouchers(int $count = 1000): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $vouchers = [];
    for ($i = 0; $i < $count; $i++) {
        $v = '';
        for ($j = 0; $j < 8; $j++) $v .= $chars[random_int(0, 61)];
        $vouchers[] = $v;
    }
    return implode("\n", $vouchers);
}

// ── AJAX: proses instalasi step by step ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    if ($action === 'start') {
        $_SESSION['install_data'] = [
            'ip'         => trim($_POST['ip'] ?? ''),
            'password'   => trim($_POST['password'] ?? ''),
            'domain'     => trim($_POST['domain'] ?? ''),
            'zoneid'     => trim($_POST['zoneid'] ?? ''),
            'email'      => trim($_POST['email'] ?? ''),
            'apikey'     => trim($_POST['apikey'] ?? ''),
            'pem'        => trim($_POST['pem'] ?? ''),
            'key'        => trim($_POST['key'] ?? ''),
        ];
        $_SESSION['install_step'] = 0;
        echo json_encode(['ok' => true, 'msg' => 'Data diterima, memulai instalasi...']);
        exit;
    }

    if ($action === 'step') {
        $d    = $_SESSION['install_data'] ?? [];
        $step = $_SESSION['install_step'] ?? 0;

        $domain   = $d['domain'];
        $ip       = $d['ip'];
        $password = $d['password'];
        $zoneid   = $d['zoneid'];
        $email    = $d['email'];
        $apikey   = $d['apikey'];
        $pem      = $d['pem'];
        $key      = $d['key'];
        $pwParam  = "-p$password";
        $base     = __DIR__;

        $steps = [
            // 0
            ['label' => 'Update sistem (apt-get update)', 'fn' => function() {
                $r = runCmd('apt-get update');
                logMsg('apt-get update: ' . $r['output']);
                return $r['code'] === 0;
            }],
            // 1
            ['label' => 'Install paket dasar', 'fn' => function() {
                $r = runCmd('apt install -y software-properties-common apache2 php mysql-server phpmyadmin zip unzip php-zip jq imagemagick');
                logMsg('install packages: ' . $r['output']);
                return $r['code'] === 0;
            }],
            // 2
            ['label' => 'Aktifkan modul Apache (SSL & CGI)', 'fn' => function() {
                runCmd('a2enmod ssl');
                runCmd('a2enmod cgi');
                runCmd('service apache2 restart');
                return true;
            }],
            // 3
            ['label' => 'Konfigurasi MySQL root password', 'fn' => function() use ($password) {
                $esc = addslashes($password);
                $r = runCmd("mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$esc';\"");
                logMsg('mysql config: ' . $r['output']);
                return true;
            }],
            // 4
            ['label' => 'Backup & salin konfigurasi Apache', 'fn' => function() use ($base) {
                runCmd('cp /etc/apache2/apache2.conf /etc/apache2/apache2.conf.backup');
                if (file_exists("$base/support/apache2.conf"))
                    runCmd("cp $base/support/apache2.conf /etc/apache2/");
                return true;
            }],
            // 5
            ['label' => 'Konfigurasi PHP', 'fn' => function() use ($base) {
                $phpIni = '/etc/php/8.3/apache2/php.ini';
                if (file_exists($phpIni)) runCmd("cp $phpIni {$phpIni}.backup");
                if (file_exists("$base/support/php.ini"))
                    runCmd("cp $base/support/php.ini /etc/php/8.3/apache2/");
                return true;
            }],
            // 6
            ['label' => 'Membuat struktur direktori', 'fn' => function() {
                $dirs = ['/home/root','/home/pma','/home/www','/home/datauser',
                         '/home/xcodehoster','/home/datapengguna','/home/domain',
                         '/home/checkdata','/home/checkdata2'];
                foreach ($dirs as $dir) {
                    @mkdir($dir, 0777, true);
                    @touch("$dir/locked");
                }
                runCmd('chmod -R 777 /home');
                runCmd('chmod 777 /usr/lib/cgi-bin');
                runCmd('chmod 777 /etc/apache2/sites-available');
                return true;
            }],
            // 7
            ['label' => 'Konfigurasi sudoers', 'fn' => function() {
                $line = "www-data ALL=(ALL) NOPASSWD: ALL\n";
                $content = file_get_contents('/etc/sudoers');
                if (strpos($content, 'www-data ALL=(ALL) NOPASSWD: ALL') === false)
                    file_put_contents('/etc/sudoers', $line, FILE_APPEND);
                return true;
            }],
            // 8
            ['label' => 'Instalasi File Manager', 'fn' => function() use ($base, $domain) {
                if (is_dir("$base/filemanager")) {
                    runCmd("cp -r $base/filemanager /home/filemanager");
                    runCmd('chmod -R 777 /home/filemanager');
                    sedReplace('/home/filemanager/index.html', 'xcodehoster.com', $domain);
                }
                return true;
            }],
            // 9
            ['label' => 'Konfigurasi file support', 'fn' => function() use ($base, $domain, $password, $pwParam, $zoneid, $email, $apikey, $ip) {
                $files = [
                    "$base/support/run.cgi"       => ['-ppasswordmysql' => $pwParam, 'xcodehoster.com' => $domain],
                    "$base/support/formdata.cgi"   => ['xcodehoster.com' => $domain],
                    "$base/support/subdomain.conf" => ['xcodehoster.com' => $domain, 'xcodehoster.com.pem' => "$domain.pem", 'xcodehoster.com.key' => "$domain.key"],
                    "$base/support/domain.conf"    => ['xcodehoster.com' => $domain, 'xcodehoster.com.pem' => "$domain.pem", 'xcodehoster.com.key' => "$domain.key"],
                    "$base/support/index.html"     => ['xcodehoster.com' => $domain],
                    "$base/support/aktivasi3.cgi"  => ['xcodehoster.com' => $domain, 'zoneid' => $zoneid, 'email' => $email, 'globalapikey' => $apikey, 'ipserver' => $ip],
                ];
                foreach ($files as $file => $replacements)
                    foreach ($replacements as $from => $to)
                        sedReplace($file, $from, $to);
                return true;
            }],
            // 10
            ['label' => 'Salin CGI scripts', 'fn' => function() use ($base, $domain, $pwParam, $zoneid, $email, $apikey, $ip) {
                $cgi = [
                    "$base/support/formfree.cgi"  => '/usr/lib/cgi-bin/formfree.cgi',
                    "$base/support/run.cgi"        => '/usr/lib/cgi-bin/run.cgi',
                    "$base/support/aktivasi3.cgi"  => '/usr/lib/cgi-bin/aktivasi3.cgi',
                    "$base/support/formdata.cgi"   => '/usr/lib/cgi-bin/formdata.cgi',
                    "$base/support/acak.txt"       => '/usr/lib/cgi-bin/acak.txt',
                ];
                foreach ($cgi as $src => $dst) if (file_exists($src)) copy($src, $dst);
                runCmd('chmod 755 /usr/lib/cgi-bin/formdata.cgi');
                runCmd('chmod 755 /usr/lib/cgi-bin/run.cgi');
                runCmd('chmod 755 /usr/lib/cgi-bin/aktivasi3.cgi');
                runCmd('chmod 755 /usr/lib/cgi-bin/formfree.cgi');
                runCmd('chmod 777 /usr/lib/cgi-bin/acak.txt');
                @touch('/usr/lib/cgi-bin/vouchers.txt');
                file_put_contents('/usr/lib/cgi-bin/ip.txt', $ip);
                // Update CGI yang sudah disalin
                sedReplace('/usr/lib/cgi-bin/run.cgi', '-ppasswordmysql', $pwParam);
                sedReplace('/usr/lib/cgi-bin/run.cgi', 'xcodehoster.com', $domain);
                sedReplace('/usr/lib/cgi-bin/aktivasi3.cgi', 'domain', $domain);
                sedReplace('/usr/lib/cgi-bin/aktivasi3.cgi', 'zoneid', $zoneid);
                sedReplace('/usr/lib/cgi-bin/aktivasi3.cgi', 'email', $email);
                sedReplace('/usr/lib/cgi-bin/aktivasi3.cgi', 'globalapikey', $apikey);
                sedReplace('/usr/lib/cgi-bin/aktivasi3.cgi', 'ipserver', $ip);
                return true;
            }],
            // 11
            ['label' => 'Deploy file web', 'fn' => function() use ($base, $domain) {
                $files = ['domain.conf','domain2.conf','subdomain.conf','index.html',
                          'bootstrap.min.css','hosting.jpg','xcodehoster21x.png','coverxcodehoster.png'];
                foreach ($files as $f)
                    if (file_exists("$base/support/$f")) copy("$base/support/$f", "/home/xcodehoster/$f");
                if (file_exists('/var/www/html/index.html'))
                    copy('/var/www/html/index.html', '/var/www/html/backup1.html');
                if (file_exists("$base/support/phpinfo.php"))
                    copy("$base/support/phpinfo.php", '/var/www/html/phpinfo.php');
                runCmd('cp /home/xcodehoster/* /var/www/html/');
                return true;
            }],
            // 12
            ['label' => 'Konfigurasi SSL', 'fn' => function() use ($domain, $pem, $key) {
                @mkdir('/etc/apache2/ssl', 0777, true);
                file_put_contents("/etc/apache2/ssl/$domain.pem", $pem);
                file_put_contents("/etc/apache2/ssl/$domain.key", $key);
                return true;
            }],
            // 13
            ['label' => 'Konfigurasi Virtual Host Apache', 'fn' => function() use ($base, $domain) {
                $src = "$base/support/domain.conf";
                $dst = "/etc/apache2/sites-available/$domain.conf";
                if (file_exists($src)) {
                    copy($src, $dst);
                    sedReplace($dst, "sample.$domain", $domain);
                    sedReplace($dst, "xcodehoster.com.pem", "$domain.pem");
                    sedReplace($dst, "xcodehoster.com.key", "$domain.key");
                    runCmd("a2ensite $domain.conf");
                }
                return true;
            }],
            // 14
            ['label' => 'Generate 1000 voucher', 'fn' => function() use ($domain) {
                $vouchers = generateVouchers(1000);
                file_put_contents('/usr/lib/cgi-bin/vouchers.txt', $vouchers);
                $rand = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
                copy('/usr/lib/cgi-bin/vouchers.txt', "/home/xcodehoster/vouchers{$rand}.txt");
                $_SESSION['voucher_file'] = "vouchers{$rand}.txt";
                return true;
            }],
            // 15
            ['label' => 'Restart Apache & finalisasi', 'fn' => function() {
                runCmd('service apache2 restart');
                touch(LOCK_FILE);
                return true;
            }],
        ];

        if ($step >= count($steps)) {
            echo json_encode(['ok' => true, 'done' => true,
                'domain' => $domain,
                'voucher' => "https://$domain/" . ($_SESSION['voucher_file'] ?? '')
            ]);
            exit;
        }

        $current = $steps[$step];
        try {
            $result = ($current['fn'])();
            $_SESSION['install_step'] = $step + 1;
            echo json_encode([
                'ok'       => true,
                'step'     => $step,
                'total'    => count($steps),
                'label'    => $current['label'],
                'success'  => $result,
                'done'     => false,
            ]);
        } catch (Throwable $e) {
            logMsg('ERROR step ' . $step . ': ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'step' => $step]);
        }
        exit;
    }
}

// Cek apakah sudah terinstall
$alreadyInstalled = file_exists(LOCK_FILE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Xcodehoster v11 – Web Installer</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg: #0a0e1a;
    --panel: #111827;
    --border: #1e2d45;
    --accent: #00d4ff;
    --accent2: #7c3aed;
    --green: #10b981;
    --red: #ef4444;
    --yellow: #f59e0b;
    --text: #e2e8f0;
    --muted: #64748b;
    --radius: 12px;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Syne', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
    background-image:
      radial-gradient(ellipse at 20% 20%, rgba(0,212,255,0.06) 0%, transparent 60%),
      radial-gradient(ellipse at 80% 80%, rgba(124,58,237,0.08) 0%, transparent 60%);
  }

  .container {
    width: 100%;
    max-width: 680px;
  }

  /* Header */
  .header {
    text-align: center;
    margin-bottom: 2.5rem;
  }
  .logo {
    display: inline-flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 1rem;
  }
  .logo-icon {
    width: 48px; height: 48px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
  }
  .logo-text { font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; }
  .logo-text span { color: var(--accent); }
  .header p { color: var(--muted); font-size: .9rem; font-family: 'Space Mono', monospace; }

  /* Card */
  .card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 2rem;
    margin-bottom: 1.5rem;
  }
  .card-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: .1em;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: .5rem;
  }
  .card-title::before {
    content: '';
    display: block;
    width: 4px; height: 16px;
    background: var(--accent);
    border-radius: 2px;
  }

  /* Form */
  .form-grid { display: grid; gap: 1rem; }
  .form-group { display: flex; flex-direction: column; gap: .4rem; }
  .form-group label {
    font-size: .8rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .08em;
    font-family: 'Space Mono', monospace;
  }
  .form-group input, .form-group textarea {
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .7rem 1rem;
    color: var(--text);
    font-family: 'Space Mono', monospace;
    font-size: .85rem;
    transition: border-color .2s, box-shadow .2s;
    width: 100%;
  }
  .form-group textarea { resize: vertical; min-height: 100px; }
  .form-group input:focus, .form-group textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(0,212,255,.1);
  }
  .form-group .hint {
    font-size: .75rem;
    color: var(--muted);
    font-family: 'Space Mono', monospace;
  }

  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

  /* Button */
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    padding: .85rem 2rem;
    border-radius: 8px;
    font-family: 'Syne', sans-serif;
    font-weight: 700;
    font-size: .95rem;
    cursor: pointer;
    border: none;
    transition: all .2s;
    width: 100%;
    margin-top: .5rem;
  }
  .btn-primary {
    background: linear-gradient(135deg, var(--accent), #0090cc);
    color: #000;
  }
  .btn-primary:hover { opacity: .9; transform: translateY(-1px); }
  .btn-primary:disabled { opacity: .4; cursor: not-allowed; transform: none; }

  /* Progress */
  #progress-section { display: none; }
  .progress-bar-wrap {
    background: rgba(255,255,255,.06);
    border-radius: 99px;
    height: 8px;
    overflow: hidden;
    margin-bottom: 1.5rem;
  }
  .progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    border-radius: 99px;
    transition: width .4s ease;
    width: 0%;
  }
  .step-list { display: flex; flex-direction: column; gap: .5rem; max-height: 320px; overflow-y: auto; }
  .step-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .6rem .75rem;
    border-radius: 8px;
    font-size: .85rem;
    font-family: 'Space Mono', monospace;
    background: rgba(255,255,255,.03);
    transition: background .2s;
  }
  .step-item.active { background: rgba(0,212,255,.07); }
  .step-item.done { opacity: .6; }
  .step-item.error { background: rgba(239,68,68,.08); }
  .step-icon { font-size: 1rem; flex-shrink: 0; }
  .spinner {
    width: 16px; height: 16px;
    border: 2px solid rgba(0,212,255,.3);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin .7s linear infinite;
    flex-shrink: 0;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* Success */
  #success-section { display: none; }
  .success-box {
    text-align: center;
    padding: 2rem;
  }
  .success-icon { font-size: 3.5rem; margin-bottom: 1rem; }
  .success-title { font-size: 1.6rem; font-weight: 800; margin-bottom: .5rem; color: var(--green); }
  .success-links { margin-top: 1.5rem; display: flex; flex-direction: column; gap: .75rem; }
  .link-box {
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .75rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
  }
  .link-label { font-size: .75rem; color: var(--muted); font-family: 'Space Mono', monospace; text-transform: uppercase; }
  .link-url { font-family: 'Space Mono', monospace; font-size: .85rem; color: var(--accent); word-break: break-all; }

  /* Already installed */
  .warning-box {
    background: rgba(245,158,11,.08);
    border: 1px solid rgba(245,158,11,.3);
    border-radius: 8px;
    padding: 1rem 1.25rem;
    font-size: .85rem;
    color: var(--yellow);
    font-family: 'Space Mono', monospace;
    margin-bottom: 1.5rem;
  }

  /* Scrollbar */
  .step-list::-webkit-scrollbar { width: 4px; }
  .step-list::-webkit-scrollbar-track { background: transparent; }
  .step-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

  @media (max-width: 500px) {
    .form-row { grid-template-columns: 1fr; }
    .card { padding: 1.25rem; }
  }
</style>
</head>
<body>
<div class="container">

  <!-- Header -->
  <div class="header">
    <div class="logo">
      <div class="logo-icon">🖥️</div>
      <div class="logo-text">Xcode<span>Hoster</span></div>
    </div>
    <p>Web Installer v11 &nbsp;·&nbsp; xcode.or.id</p>
  </div>

  <?php if ($alreadyInstalled): ?>
  <div class="warning-box">
    ⚠️ Installer telah dijalankan sebelumnya. Hapus file <code>.installed</code> jika ingin install ulang.
  </div>
  <?php endif; ?>

  <!-- FORM SECTION -->
  <div id="form-section">

    <!-- Server -->
    <div class="card">
      <div class="card-title">Informasi Server</div>
      <div class="form-grid">
        <div class="form-row">
          <div class="form-group">
            <label>IP Publik Server</label>
            <input type="text" id="ip" placeholder="103.xxx.xxx.xxx">
          </div>
          <div class="form-group">
            <label>Nama Domain</label>
            <input type="text" id="domain" placeholder="hosting.namadomain.com">
          </div>
        </div>
        <div class="form-group">
          <label>Password Root MySQL</label>
          <input type="password" id="password" placeholder="Buat password baru">
          <span class="hint">Password ini akan digunakan untuk akses MySQL root</span>
        </div>
      </div>
    </div>

    <!-- Cloudflare -->
    <div class="card">
      <div class="card-title">Konfigurasi Cloudflare</div>
      <div class="form-grid">
        <div class="form-row">
          <div class="form-group">
            <label>Email Cloudflare</label>
            <input type="email" id="email" placeholder="email@domain.com">
          </div>
          <div class="form-group">
            <label>Zone ID</label>
            <input type="text" id="zoneid" placeholder="Zone ID dari dashboard CF">
          </div>
        </div>
        <div class="form-group">
          <label>Global API Key</label>
          <input type="password" id="apikey" placeholder="Global API Key Cloudflare">
          <span class="hint">Profil Cloudflare → API Tokens → Global API Key</span>
        </div>
      </div>
    </div>

    <!-- SSL -->
    <div class="card">
      <div class="card-title">Sertifikat SSL</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Isi File .pem (Certificate)</label>
          <textarea id="pem" placeholder="-----BEGIN CERTIFICATE-----
...
-----END CERTIFICATE-----"></textarea>
        </div>
        <div class="form-group">
          <label>Isi File .key (Private Key)</label>
          <textarea id="key" placeholder="-----BEGIN PRIVATE KEY-----
...
-----END PRIVATE KEY-----"></textarea>
        </div>
      </div>
    </div>

    <button class="btn btn-primary" onclick="startInstall()" <?= $alreadyInstalled ? 'disabled' : '' ?>>
      🚀 Mulai Instalasi
    </button>
  </div>

  <!-- PROGRESS SECTION -->
  <div id="progress-section">
    <div class="card">
      <div class="card-title">Proses Instalasi</div>
      <div class="progress-bar-wrap">
        <div class="progress-bar" id="progress-bar"></div>
      </div>
      <div class="step-list" id="step-list"></div>
    </div>
  </div>

  <!-- SUCCESS SECTION -->
  <div id="success-section">
    <div class="card">
      <div class="success-box">
        <div class="success-icon">✅</div>
        <div class="success-title">Instalasi Selesai!</div>
        <p style="color:var(--muted);font-size:.9rem">Xcodehoster v11 berhasil diinstal di server Anda.</p>
        <div class="success-links" id="success-links"></div>
      </div>
    </div>
  </div>

</div>

<script>
const TOTAL_STEPS = 16;

async function startInstall() {
  const fields = {
    ip: document.getElementById('ip').value.trim(),
    password: document.getElementById('password').value.trim(),
    domain: document.getElementById('domain').value.trim(),
    zoneid: document.getElementById('zoneid').value.trim(),
    email: document.getElementById('email').value.trim(),
    apikey: document.getElementById('apikey').value.trim(),
    pem: document.getElementById('pem').value.trim(),
    key: document.getElementById('key').value.trim(),
  };

  // Validasi
  for (const [k, v] of Object.entries(fields)) {
    if (!v) {
      alert(`Field "${k}" tidak boleh kosong!`);
      return;
    }
  }

  // Sembunyikan form, tampilkan progress
  document.getElementById('form-section').style.display = 'none';
  document.getElementById('progress-section').style.display = 'block';

  // Kirim data ke server
  const body = new FormData();
  body.append('action', 'start');
  for (const [k, v] of Object.entries(fields)) body.append(k, v);
  await fetch('', { method: 'POST', body });

  // Jalankan step satu per satu
  runNextStep();
}

async function runNextStep() {
  const body = new FormData();
  body.append('action', 'step');
  const res = await fetch('', { method: 'POST', body });
  const data = await res.json();

  if (!data.ok) {
    addStep(`❌ Error: ${data.error}`, 'error');
    return;
  }

  if (data.done) {
    showSuccess(data.domain, data.voucher);
    return;
  }

  const pct = Math.round(((data.step + 1) / data.total) * 100);
  document.getElementById('progress-bar').style.width = pct + '%';
  addStep(data.label, data.success ? 'done' : 'error', data.success ? '✔' : '✖');

  setTimeout(runNextStep, 300);
}

function addStep(label, status, icon = '') {
  const list = document.getElementById('step-list');
  const item = document.createElement('div');
  item.className = `step-item ${status}`;
  item.innerHTML = `<span class="step-icon">${icon || (status === 'done' ? '✔' : status === 'error' ? '✖' : '⟳')}</span> ${label}`;
  list.appendChild(item);
  list.scrollTop = list.scrollHeight;
}

function showSuccess(domain, voucher) {
  document.getElementById('progress-section').style.display = 'none';
  document.getElementById('success-section').style.display = 'block';
  document.getElementById('progress-bar').style.width = '100%';

  const links = document.getElementById('success-links');
  links.innerHTML = `
    <div class="link-box">
      <div><div class="link-label">Website</div><div class="link-url">https://${domain}</div></div>
      <a href="https://${domain}" target="_blank" style="color:var(--accent);text-decoration:none;font-size:.8rem">Buka →</a>
    </div>
    <div class="link-box">
      <div><div class="link-label">phpMyAdmin</div><div class="link-url">https://${domain}/phpmyadmin</div></div>
      <a href="https://${domain}/phpmyadmin" target="_blank" style="color:var(--accent);text-decoration:none;font-size:.8rem">Buka →</a>
    </div>
    <div class="link-box">
      <div><div class="link-label">Daftar Voucher</div><div class="link-url">${voucher}</div></div>
      <a href="${voucher}" target="_blank" style="color:var(--accent);text-decoration:none;font-size:.8rem">Buka →</a>
    </div>
  `;
}
</script>
</body>
</html>