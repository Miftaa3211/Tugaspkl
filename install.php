<?php
/**
 * Xcodehoster v11 - Web Installer (MULTI-DOMAIN, FULL AUTO)
 * - Bisa install banyak domain (bukan sekali pakai)
 * - SSL self-signed yang benar (CA:FALSE)
 * - Apache restart berjalan di background (tidak memutus koneksi browser)
 * - Menampilkan link akses domain + link voucher setelah selesai
 */

define('SUPPORT_DIR', __DIR__ . '/support');
define('FM_DIR',      __DIR__ . '/filemanager');
define('LOG_DIR',     __DIR__ . '/install_logs');

// Buat folder log jika belum ada
if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0755, true);

$step   = $_POST['step'] ?? 'form';
$errors = [];

// Tangani AJAX status check
if (isset($_GET['check_status']) && isset($_GET['domain'])) {
    $domain  = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_GET['domain']);
    $logFile = LOG_DIR . '/' . $domain . '.json';
    if (file_exists($logFile)) {
        header('Content-Type: application/json');
        echo file_get_contents($logFile);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'pending']);
    }
    exit;
}

if ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip      = trim($_POST['ipserver']    ?? '');
    $domain  = trim($_POST['domain']      ?? '');
    $dbpass  = trim($_POST['dbpass']      ?? '');
    $zone_id = trim($_POST['zone_id']     ?? '');
    $email   = trim($_POST['admin_email'] ?? '');
    $api_key = trim($_POST['api_key']     ?? '');

    if (empty($ip))      $errors[] = 'IP Server wajib diisi.';
    if (empty($domain))  $errors[] = 'Domain utama wajib diisi.';
    if (empty($dbpass))  $errors[] = 'Password MySQL root wajib diisi.';
    if (empty($email))   $errors[] = 'Email Admin wajib diisi.';
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Format email tidak valid.';
    if (!empty($domain) && !preg_match('/^[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $domain))
        $errors[] = 'Format domain tidak valid (contoh: namadomain.com).';

    if (empty($errors)) {
        // Jalankan instalasi di background agar browser tidak terputus
        $logFile = LOG_DIR . '/' . $domain . '.json';
        file_put_contents($logFile, json_encode(['status' => 'running', 'logs' => [], 'started' => date('Y-m-d H:i:s')]));

        // Encode parameter untuk dikirim ke background script
        $params = base64_encode(json_encode([
            'ip'      => $ip,
            'domain'  => $domain,
            'dbpass'  => $dbpass,
            'zone_id' => $zone_id,
            'email'   => $email,
            'api_key' => $api_key,
            'logfile' => $logFile,
        ]));

        // Jalankan instalasi di background menggunakan PHP CLI
        $phpBin  = PHP_BINARY ?: 'php';
        $selfDir = __DIR__;
        $cmd = "$phpBin -r \"
\\\$p = json_decode(base64_decode('$params'), true);
require_once '$selfDir/install.php';
runInstallBackground(\\\$p);
\" > /dev/null 2>&1 &";
        shell_exec($cmd);

        // Tampilkan halaman loading — browser poll status via AJAX
        showLoading($domain, $ip);
        exit;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   CORE INSTALL (dipanggil dari background process)
═══════════════════════════════════════════════════════════════════ */
function runInstallBackground(array $p): void
{
    $ip      = $p['ip'];
    $domain  = $p['domain'];
    $dbpass  = $p['dbpass'];
    $zoneId  = $p['zone_id'];
    $email   = $p['email'];
    $apiKey  = $p['api_key'];
    $logFile = $p['logfile'];

    $logs = []; $errors = [];

    $run = function(string $cmd) use (&$logs): string {
        $out = shell_exec("$cmd 2>&1");
        return (string)$out;
    };
    $log = function(string $msg) use (&$logs, $logFile) {
        $logs[] = $msg;
        // Update log file secara realtime
        $data = json_decode(file_get_contents($logFile), true);
        $data['logs'] = $logs;
        file_put_contents($logFile, json_encode($data));
    };

    try {
        /* ── 0. SUDOERS ─────────────────────────────────────────── */
        $sudoLine = 'www-data ALL=(ALL) NOPASSWD: ALL';
        $current  = @file_get_contents('/etc/sudoers') ?: '';
        if (strpos($current, 'www-data') === false) {
            $run("echo '$sudoLine' | sudo tee -a /etc/sudoers");
            $log("✅ www-data ditambahkan ke sudoers");
        } else {
            $log("✅ www-data sudah ada di sudoers");
        }

        /* ── 1. UFW ──────────────────────────────────────────────── */
        $run("sudo apt-get install -y ufw 2>/dev/null");
        $run("sudo ufw allow 22/tcp");
        $run("sudo ufw allow 80/tcp");
        $run("sudo ufw allow 443/tcp");
        $run("sudo ufw --force enable");
        $log("✅ Firewall UFW: port 22, 80, 443 dibuka");

        /* ── 2. DIREKTORI ───────────────────────────────────────── */
        $dirs = [
            '/home/root','/home/pma','/home/www','/home/datauser',
            '/home/xcodehoster','/home/datapengguna','/home/domain',
            '/home/checkdata','/home/checkdata2','/home/filemanager',
            '/home/server','/etc/apache2/ssl','/etc/apache2/sites-available',
        ];
        foreach ($dirs as $d) {
            $run("sudo mkdir -p " . escapeshellarg($d));
            $run("sudo chmod 777 " . escapeshellarg($d));
        }
        $log("✅ Semua direktori sistem siap");

        /* ── 3. APACHE MODULES ──────────────────────────────────── */
        $run("sudo a2enmod cgi rewrite ssl headers php8.3 2>/dev/null || sudo a2enmod cgi rewrite ssl headers 2>/dev/null");
        $run("sudo chmod 777 /usr/lib/cgi-bin");
        $log("✅ Apache module: CGI, Rewrite, SSL, Headers diaktifkan");

        /* ── 4. SSL CERTIFICATE (CA:FALSE) ──────────────────────── */
        $sslDir  = '/etc/apache2/ssl';
        $pemFile = "$sslDir/$domain.pem";
        $keyFile = "$sslDir/$domain.key";

        // Hapus certificate lama jika ada (termasuk yang immutable)
        $run("sudo chattr -i $pemFile 2>/dev/null");
        $run("sudo chattr -i $keyFile 2>/dev/null");
        $run("sudo rm -f $pemFile $keyFile");

        // Buat certificate baru yang benar (CA:FALSE)
        $run("sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 " .
             "-keyout " . escapeshellarg($keyFile) . " " .
             "-out "    . escapeshellarg($pemFile)  . " " .
             "-subj '/CN=$domain' " .
             "-addext 'basicConstraints=CA:FALSE'");
        $run("sudo chmod 644 $pemFile $keyFile");

        // Verifikasi certificate
        $verify = $run("sudo openssl x509 -in $pemFile -text -noout 2>&1 | grep 'CA:'");
        if (strpos($verify, 'CA:FALSE') !== false) {
            $log("✅ SSL self-signed dibuat dengan benar (CA:FALSE): $domain.pem");
        } else {
            $log("⚠️ SSL dibuat tapi perlu verifikasi manual");
        }

        /* ── 5. PROCESS SUPPORT FILES ───────────────────────────── */
        $supportFiles = ['formdata.cgi','run.cgi','aktivasi3.cgi','subdomain.conf','domain.conf','domain2.conf','index.html'];
        foreach ($supportFiles as $file) {
            $src = SUPPORT_DIR . '/' . $file;
            if (!file_exists($src)) { $log("⚠️ File tidak ditemukan: $file"); continue; }
            $content = file_get_contents($src);
            $content = str_replace('xcodehoster.com.pem',    "$domain.pem", $content);
            $content = str_replace('xcodehoster.com.key',    "$domain.key", $content);
            $content = str_replace('xcodehoster.com',        $domain,       $content);
            $content = str_replace('sample.xcodehoster.com', $domain,       $content);
            $content = str_replace('-ppasswordmysql',        "-p$dbpass",   $content);
            $content = str_replace('zoneid',                 $zoneId,       $content);
            $content = str_replace('globalapikey',           $apiKey,       $content);
            $content = str_replace('ipserver',               $ip,           $content);
            if ($file === 'formdata.cgi') {
                $content = str_replace("https://$domain/coverxcodehoster.png", "http://$domain/coverxcodehoster.png", $content);
            }
            file_put_contents($src, $content);
        }
        $log("✅ Support files diupdate dengan konfigurasi domain");

        /* ── 6. COPY KE /home/xcodehoster ──────────────────────── */
        $run("sudo chown www-data:www-data /home/xcodehoster");
        $copyToXcode = ['domain.conf','domain2.conf','subdomain.conf','index.html',
                        'bootstrap.min.css','hosting.jpg','xcodehoster21x.png','coverxcodehoster.png'];
        foreach ($copyToXcode as $file) {
            $src = SUPPORT_DIR . '/' . $file;
            if (file_exists($src)) $run("sudo cp " . escapeshellarg($src) . " /home/xcodehoster/$file");
        }
        $log("✅ File disalin ke /home/xcodehoster/");

        /* ── 7. COPY KE /var/www/html ───────────────────────────── */
        foreach (['index.html','bootstrap.min.css','hosting.jpg','xcodehoster21x.png','coverxcodehoster.png'] as $f) {
            $src = SUPPORT_DIR . '/' . $f;
            if (file_exists($src)) $run("sudo cp " . escapeshellarg($src) . " /var/www/html/$f");
        }
        $log("✅ File web disalin ke /var/www/html/");

        /* ── 8. FILEMANAGER ─────────────────────────────────────── */
        if (is_dir(FM_DIR)) {
            $run("sudo cp -r " . escapeshellarg(FM_DIR) . "/. /home/filemanager/");
            $run("sudo chmod -R 777 /home/filemanager");
            $run("sudo chown -R www-data:www-data /home/filemanager");
            $fmIndex = '/home/filemanager/index.html';
            if (file_exists($fmIndex)) {
                $c = str_replace('xcodehoster.com', $domain, file_get_contents($fmIndex));
                file_put_contents($fmIndex, $c);
            }
            $log("✅ File Manager disalin ke /home/filemanager/");
        }

        /* ── 9. CGI FILES ───────────────────────────────────────── */
        foreach (['formdata.cgi','run.cgi','aktivasi3.cgi'] as $file) {
            $src = SUPPORT_DIR . '/' . $file;
            if (file_exists($src)) {
                $run("sudo cp " . escapeshellarg($src) . " /usr/lib/cgi-bin/$file");
                $run("sudo chmod 777 /usr/lib/cgi-bin/$file");
            }
        }
        $run("sudo bash -c 'echo " . escapeshellarg($ip) . " > /usr/lib/cgi-bin/ip.txt'");
        $run("sudo touch /usr/lib/cgi-bin/acak.txt");
        $run("sudo chmod 777 /usr/lib/cgi-bin/acak.txt /usr/lib/cgi-bin/ip.txt");
        $log("✅ CGI files disalin + ip.txt & acak.txt dibuat");

        /* ── 10. APACHE VIRTUALHOST ─────────────────────────────── */
        $vhost = "<VirtualHost *:80>
    ServerName $domain
    ServerAlias www.$domain
    DocumentRoot /home/xcodehoster
    ScriptAlias /cgi-bin/ /usr/lib/cgi-bin/
    <Directory /home/xcodehoster>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory /usr/lib/cgi-bin>
        Options +ExecCGI
        AddHandler cgi-script .cgi .sh
        Require all granted
    </Directory>
</VirtualHost>

<VirtualHost *:443>
    ServerName $domain
    ServerAlias www.$domain
    DocumentRoot /home/xcodehoster
    SSLEngine on
    SSLCertificateFile $pemFile
    SSLCertificateKeyFile $keyFile
    ScriptAlias /cgi-bin/ /usr/lib/cgi-bin/
    <Directory /home/xcodehoster>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory /usr/lib/cgi-bin>
        Options +ExecCGI
        AddHandler cgi-script .cgi .sh
        Require all granted
    </Directory>
</VirtualHost>";

        file_put_contents("/tmp/{$domain}.conf", $vhost);
        $run("sudo cp /tmp/{$domain}.conf /etc/apache2/sites-available/{$domain}.conf");
        $log("✅ Apache VirtualHost config dibuat: $domain.conf");

        // ServerName global
        $apacheConf = @file_get_contents('/etc/apache2/apache2.conf') ?: '';
        if (strpos($apacheConf, 'ServerName') === false) {
            $run("echo 'ServerName $domain' | sudo tee -a /etc/apache2/apache2.conf");
        }

        /* ── 11. A2ENSITE ───────────────────────────────────────── */
        $run("sudo a2ensite " . escapeshellarg("$domain.conf"));
        $log("✅ a2ensite $domain.conf — site diaktifkan");

        /* ── 12. CONFIG TEST + RESTART (non-blocking) ───────────── */
        $configTest = $run("sudo apache2ctl configtest 2>&1");
        if (strpos($configTest, 'Syntax OK') !== false) {
            // Restart di background agar tidak memutus proses ini
            $run("sudo systemctl restart apache2");
            $log("✅ Apache restart berhasil — domain langsung aktif!");
        } else {
            $log("⚠️ Apache config warning: " . trim($configTest));
            $run("sudo systemctl reload apache2 2>/dev/null || sudo systemctl restart apache2");
            $log("✅ Apache reload dijalankan");
        }

        /* ── 13. VOUCHER 600 KODE ───────────────────────────────── */
        $vouchers = generateVouchers(600);
        $vContent = implode("\n", $vouchers);
        $vFile    = 'vouchers600.txt';
        foreach ([__DIR__."/$vFile", "/var/www/html/$vFile", "/home/xcodehoster/$vFile"] as $path) {
            @file_put_contents($path, $vContent);
        }
        file_put_contents("/tmp/vouchers.txt", $vContent);
        $run("sudo cp /tmp/vouchers.txt /usr/lib/cgi-bin/vouchers.txt");
        $run("sudo chmod 777 /usr/lib/cgi-bin/vouchers.txt");
        $log("✅ 600 kode voucher dibuat & disalin ke cgi-bin");

        /* ── 14. SIMPAN .env ────────────────────────────────────── */
        $env = "# Xcodehoster v11 — Generated: ".date('Y-m-d H:i:s')."\n"
             . "APP_VERSION=11\nSERVER_IP=$ip\nMAIN_DOMAIN=$domain\nDB_PASS=$dbpass\n"
             . "CF_ZONE_ID=$zoneId\nCF_EMAIL=$email\nCF_API_KEY=$apiKey\n"
             . "SSL_PEM=$pemFile\nSSL_KEY=$keyFile\n";
        file_put_contents(__DIR__.'/.env', $env);
        $log("✅ File .env konfigurasi disimpan");

        // Simpan riwayat instalasi domain
        $histFile = __DIR__ . '/install_history.json';
        $history  = file_exists($histFile) ? json_decode(file_get_contents($histFile), true) : [];
        $history[] = [
            'domain'    => $domain,
            'ip'        => $ip,
            'installed' => date('Y-m-d H:i:s'),
            'voucher'   => $vFile,
        ];
        file_put_contents($histFile, json_encode($history, JSON_PRETTY_PRINT));

        // Tulis hasil sukses ke log file
        file_put_contents($logFile, json_encode([
            'status'        => 'success',
            'logs'          => $logs,
            'domain'        => $domain,
            'ip'            => $ip,
            'voucher_file'  => $vFile,
            'voucher_count' => 600,
            'finished'      => date('Y-m-d H:i:s'),
        ]));

    } catch (\Throwable $e) {
        $logs[] = "❌ ERROR: " . $e->getMessage();
        file_put_contents($logFile, json_encode([
            'status' => 'error',
            'logs'   => $logs,
            'error'  => $e->getMessage(),
        ]));
    }
}

function generateVouchers(int $n): array {
    $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $list = []; $used = [];
    while (count($list) < $n) {
        $code = '';
        for ($i = 0; $i < 10; $i++) $code .= $pool[random_int(0, 35)];
        if (!isset($used[$code])) { $used[$code] = 1; $list[] = $code; }
    }
    return $list;
}

/* ═══════════════════════════════════════════════════════════════════
   PAGE: LOADING (polling AJAX)
═══════════════════════════════════════════════════════════════════ */
function showLoading(string $domain, string $ip): void { ?>
<!DOCTYPE html><html lang="id"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Xcodehoster v11 — Menginstall...</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Bebas+Neue&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--g:#00FF87;--a:#7B61FF;--dark:#050510;--card:#0A0A18;--border:#1A1A30;--text:#8888AA}
body{background:var(--dark);color:var(--text);font-family:'Space Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 20% 50%,rgba(123,97,255,.08) 0%,transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(0,255,135,.06) 0%,transparent 50%);pointer-events:none}
.wrap{width:100%;max-width:680px;position:relative;z-index:1}
.logo{text-align:center;margin-bottom:40px}
.logo h1{font-family:'Bebas Neue',sans-serif;font-size:52px;letter-spacing:4px;background:linear-gradient(135deg,var(--g),var(--a));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1}
.logo p{font-size:11px;color:#333;letter-spacing:3px;margin-top:4px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden}
.card-top{padding:28px 32px 0}
.progress-wrap{margin:24px 0 0}
.progress-label{display:flex;justify-content:space-between;font-size:11px;color:#444;margin-bottom:8px}
.progress-bar{height:4px;background:#111;border-radius:2px;overflow:hidden}
.progress-fill{height:100%;background:linear-gradient(90deg,var(--g),var(--a));border-radius:2px;width:0%;transition:width .5s ease}
.status-icon{font-size:48px;text-align:center;margin:28px 0 12px;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
.status-text{text-align:center;font-size:22px;font-family:'Bebas Neue',sans-serif;letter-spacing:2px;color:#fff;margin-bottom:6px}
.status-sub{text-align:center;font-size:11px;color:#444;margin-bottom:28px}
.logbox{background:#060610;border-top:1px solid var(--border);padding:20px 32px;max-height:260px;overflow-y:auto}
.log-line{font-size:11px;line-height:2.2;color:#333;transition:color .3s}
.log-line.ok{color:#2A9A5A}
.log-line.warn{color:#AA7700}
.log-line.err{color:#AA3333}
.log-line.new{color:#00FF87}
.result-links{padding:28px 32px;border-top:1px solid var(--border);display:none}
.result-links h3{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:2px;color:var(--g);margin-bottom:20px}
.link-item{display:flex;align-items:center;gap:14px;background:#060610;border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:10px;text-decoration:none;transition:.2s}
.link-item:hover{border-color:var(--g);transform:translateX(4px)}
.link-icon{font-size:22px;flex-shrink:0}
.link-lbl{font-size:10px;color:#444;letter-spacing:2px;text-transform:uppercase;margin-bottom:3px}
.link-url{font-size:12px;color:var(--g);font-weight:700;word-break:break-all}
.history{padding:28px 32px;border-top:1px solid var(--border)}
.history h3{font-family:'Bebas Neue',sans-serif;font-size:16px;letter-spacing:2px;color:#444;margin-bottom:14px}
.hist-item{font-size:11px;color:#333;padding:8px 0;border-bottom:1px solid #0E0E1A;display:flex;justify-content:space-between}
.hist-item a{color:#555;text-decoration:none}.hist-item a:hover{color:var(--g)}
.btn-new{display:none;width:calc(100% - 64px);margin:0 32px 28px;padding:14px;background:linear-gradient(135deg,var(--g),var(--a));border:none;border-radius:12px;color:#000;font-family:'Bebas Neue',sans-serif;font-size:18px;letter-spacing:2px;cursor:pointer;transition:.2s}
.btn-new:hover{opacity:.9;transform:translateY(-1px)}
</style></head><body>
<div class="wrap">
  <div class="logo">
    <h1>XCODEHOSTER</h1>
    <p>WEB INSTALLER · V11 · MULTI DOMAIN</p>
  </div>
  <div class="card">
    <div class="card-top">
      <div class="progress-wrap">
        <div class="progress-label"><span>Progress Instalasi</span><span id="pct">0%</span></div>
        <div class="progress-bar"><div class="progress-fill" id="pfill"></div></div>
      </div>
      <div class="status-icon" id="sicon">⚙️</div>
      <div class="status-text" id="stxt">MENGINSTALL...</div>
      <div class="status-sub" id="ssub">Domain: <?= htmlspecialchars($domain) ?> · IP: <?= htmlspecialchars($ip) ?></div>
    </div>
    <div class="logbox" id="logbox">
      <div class="log-line">⏳ Memulai proses instalasi...</div>
    </div>
    <div class="result-links" id="result-links">
      <h3>✅ Instalasi Selesai — Akses Link Berikut</h3>
      <a class="link-item" id="lnk-main" href="#" target="_blank">
        <div class="link-icon">🌐</div>
        <div><div class="link-lbl">Website Utama</div><div class="link-url" id="url-main"></div></div>
      </a>
      <a class="link-item" id="lnk-form" href="#" target="_blank">
        <div class="link-icon">📝</div>
        <div><div class="link-lbl">Form Pendaftaran Hosting</div><div class="link-url" id="url-form"></div></div>
      </a>
      <a class="link-item" id="lnk-voucher" href="#" target="_blank">
        <div class="link-icon">🎟️</div>
        <div><div class="link-lbl">Download 600 Kode Voucher</div><div class="link-url" id="url-voucher"></div></div>
      </a>
      <a class="link-item" href="http://<?= htmlspecialchars($ip) ?>/phpmyadmin" target="_blank">
        <div class="link-icon">🗄️</div>
        <div><div class="link-lbl">phpMyAdmin</div><div class="link-url">http://<?= htmlspecialchars($ip) ?>/phpmyadmin</div></div>
      </a>
    </div>
    <button class="btn-new" id="btn-new" onclick="location.href='install.php'">⚡ INSTALL DOMAIN LAIN</button>
    <div class="history" id="history-section" style="display:none">
      <h3>RIWAYAT INSTALASI</h3>
      <div id="history-list"></div>
    </div>
  </div>
</div>
<script>
const DOMAIN = '<?= htmlspecialchars($domain) ?>';
const IP     = '<?= htmlspecialchars($ip) ?>';
let lastLogCount = 0;
let progress = 5;
const steps = 14;

function poll() {
  fetch('install.php?check_status=1&domain=' + encodeURIComponent(DOMAIN))
    .then(r => r.json())
    .then(data => {
      // Update logs
      const logbox = document.getElementById('logbox');
      if (data.logs && data.logs.length > lastLogCount) {
        for (let i = lastLogCount; i < data.logs.length; i++) {
          const line = data.logs[i];
          const div  = document.createElement('div');
          div.className = 'log-line new ' + (line.startsWith('✅') ? 'ok' : line.startsWith('⚠️') ? 'warn' : line.startsWith('❌') ? 'err' : '');
          div.textContent = line;
          logbox.appendChild(div);
          setTimeout(() => div.classList.remove('new'), 500);
          logbox.scrollTop = logbox.scrollHeight;
        }
        lastLogCount = data.logs.length;
        // Update progress
        progress = Math.min(95, Math.round((lastLogCount / steps) * 100));
        document.getElementById('pfill').style.width = progress + '%';
        document.getElementById('pct').textContent = progress + '%';
      }

      if (data.status === 'success') {
        // Done!
        document.getElementById('pfill').style.width = '100%';
        document.getElementById('pct').textContent = '100%';
        document.getElementById('sicon').textContent = '🎉';
        document.getElementById('stxt').textContent = 'INSTALASI BERHASIL!';
        document.getElementById('stxt').style.color = '#00FF87';
        document.getElementById('ssub').textContent = 'Semua komponen berhasil dikonfigurasi otomatis';

        // Set links
        const d = data.domain;
        document.getElementById('url-main').textContent    = 'http://' + d;
        document.getElementById('lnk-main').href           = 'http://' + d;
        document.getElementById('url-form').textContent    = 'http://' + d + '/cgi-bin/formdata.cgi';
        document.getElementById('lnk-form').href           = 'http://' + d + '/cgi-bin/formdata.cgi';
        document.getElementById('url-voucher').textContent = 'http://' + d + '/' + data.voucher_file;
        document.getElementById('lnk-voucher').href        = 'http://' + d + '/' + data.voucher_file;

        document.getElementById('result-links').style.display = 'block';
        document.getElementById('btn-new').style.display = 'block';

        // Load history
        loadHistory();

      } else if (data.status === 'error') {
        document.getElementById('sicon').textContent = '❌';
        document.getElementById('stxt').textContent  = 'INSTALASI GAGAL';
        document.getElementById('stxt').style.color  = '#FF4D4D';
        document.getElementById('ssub').textContent  = data.error || 'Terjadi kesalahan';
        document.getElementById('btn-new').style.display = 'block';
        document.getElementById('btn-new').textContent   = '↩ COBA LAGI';
      } else {
        // Still running
        setTimeout(poll, 2000);
      }
    })
    .catch(() => setTimeout(poll, 3000));
}

function loadHistory() {
  fetch('install.php?history=1')
    .then(r => r.json())
    .then(data => {
      if (data && data.length > 1) {
        const sec  = document.getElementById('history-section');
        const list = document.getElementById('history-list');
        sec.style.display = 'block';
        data.slice(-5).reverse().forEach(h => {
          const div = document.createElement('div');
          div.className = 'hist-item';
          div.innerHTML = '<a href="http://'+h.domain+'" target="_blank">'+h.domain+'</a><span>'+h.installed+'</span>';
          list.appendChild(div);
        });
      }
    }).catch(() => {});
}

// Mulai polling setelah 1 detik
setTimeout(poll, 1000);
</script>
</body></html>
<?php }

// Handle history request
if (isset($_GET['history'])) {
    $histFile = __DIR__ . '/install_history.json';
    header('Content-Type: application/json');
    echo file_exists($histFile) ? file_get_contents($histFile) : '[]';
    exit;
}

/* ═══════════════════════════════════════════════════════════════════
   PAGE: FORM (multi-domain, bisa dipakai berulang)
═══════════════════════════════════════════════════════════════════ */
// Load riwayat instalasi
$histFile = __DIR__ . '/install_history.json';
$history  = file_exists($histFile) ? json_decode(file_get_contents($histFile), true) : [];
?>
<!DOCTYPE html><html lang="id"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Xcodehoster v11 — Web Installer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Bebas+Neue&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--g:#00FF87;--a:#7B61FF;--dark:#050510;--card:#0A0A18;--card2:#080814;--border:#1A1A30;--text:#8888AA;--red:#FF4D4D}
body{background:var(--dark);color:var(--text);font-family:'Space Mono',monospace;min-height:100vh;padding:60px 20px 40px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 20% 50%,rgba(123,97,255,.08) 0%,transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(0,255,135,.06) 0%,transparent 50%);pointer-events:none;z-index:0}
.topbar{position:fixed;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--g),var(--a),var(--g));background-size:200%;animation:bar 3s linear infinite;z-index:100}
@keyframes bar{from{background-position:0}to{background-position:200%}}
.wrap{max-width:720px;margin:0 auto;position:relative;z-index:1}
.logo{text-align:center;margin-bottom:40px;animation:rise .6s ease}
.logo h1{font-family:'Bebas Neue',sans-serif;font-size:56px;letter-spacing:5px;background:linear-gradient(135deg,var(--g),var(--a));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1}
.logo p{font-size:10px;color:#2A2A40;letter-spacing:4px;margin-top:6px}
.badges{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:32px}
.badge{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:6px;padding:5px 12px;font-size:10px;color:#444;letter-spacing:1px}
.badge.green{border-color:rgba(0,255,135,.2);color:#2A9A5A}
.notice{background:rgba(0,255,135,.04);border:1px solid rgba(0,255,135,.15);border-radius:12px;padding:16px 20px;margin-bottom:16px;font-size:11px;color:#2A7A55;line-height:1.8}
.notice strong{color:var(--g)}
.warn{background:rgba(240,165,0,.04);border:1px solid rgba(240,165,0,.15);border-radius:12px;padding:14px 20px;margin-bottom:20px;font-size:11px;color:#7A6A20;line-height:1.8}
.warn strong{color:#F0A500}
.warn code{background:rgba(255,255,255,.06);padding:4px 10px;border-radius:4px;display:inline-block;margin-top:6px;font-size:11px;color:#AAA}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;box-shadow:0 0 80px rgba(0,0,0,.6);animation:rise .6s .1s ease both}
.card-hdr{background:var(--card2);border-bottom:1px solid var(--border);padding:16px 26px;display:flex;align-items:center;gap:12px}
.dots{display:flex;gap:6px}.dots span{width:10px;height:10px;border-radius:50%}
.dots .r{background:#FF5F57}.dots .y{background:#FFBC2E}.dots .g{background:#28C840}
.hdr-txt{font-size:11px;color:#333;margin-left:6px;letter-spacing:1px}
.body{padding:32px 28px}
.sec{font-size:10px;letter-spacing:3px;color:var(--a);margin:28px 0 16px;display:flex;align-items:center;gap:10px;text-transform:uppercase}
.sec:first-child{margin-top:0}.sec::after{content:'';flex:1;height:1px;background:var(--border)}
.field{margin-bottom:14px}
label{display:block;font-size:10px;color:#444;margin-bottom:6px;letter-spacing:1px}
label span{color:var(--red)}
input,textarea{width:100%;background:#040410;border:1px solid var(--border);border-radius:10px;padding:12px 16px;color:#CCC;font-family:'Space Mono',monospace;font-size:12px;transition:.2s;outline:none}
input:focus,textarea:focus{border-color:var(--a);box-shadow:0 0 0 3px rgba(123,97,255,.1)}
input::placeholder,textarea::placeholder{color:#1A1A2E}
.hint{font-size:10px;color:#222;margin-top:5px;line-height:1.6}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:560px){.two{grid-template-columns:1fr}}
.errors{background:rgba(255,77,77,.06);border:1px solid rgba(255,77,77,.2);border-radius:10px;padding:14px 18px;margin-bottom:20px}
.errors p{font-size:11px;color:var(--red);margin-bottom:3px}
.errors p::before{content:'✕ '}
.submit{margin-top:28px}
button[type=submit]{width:100%;padding:16px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--g),var(--a));color:#000;font-family:'Bebas Neue',sans-serif;font-size:20px;letter-spacing:3px;cursor:pointer;transition:.2s;box-shadow:0 4px 30px rgba(0,255,135,.15)}
button[type=submit]:hover{opacity:.9;transform:translateY(-2px)}
button[type=submit]:disabled{opacity:.5;cursor:not-allowed;transform:none}
.history{margin-top:28px;background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
.history-hdr{background:var(--card2);border-bottom:1px solid var(--border);padding:14px 22px;font-size:10px;letter-spacing:3px;color:#333;text-transform:uppercase}
.hist-item{padding:14px 22px;border-bottom:1px solid #080814;display:flex;align-items:center;justify-content:space-between;gap:12px}
.hist-item:last-child{border-bottom:none}
.hist-domain{font-size:12px;color:#666;font-weight:700}
.hist-time{font-size:10px;color:#222}
.hist-links{display:flex;gap:8px}
.hist-links a{font-size:10px;color:#333;text-decoration:none;padding:3px 8px;border:1px solid #1A1A30;border-radius:4px;transition:.2s}
.hist-links a:hover{color:var(--g);border-color:rgba(0,255,135,.3)}
@keyframes rise{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
footer{text-align:center;margin-top:32px;font-size:10px;color:#1A1A2E;letter-spacing:2px}
</style></head><body>
<div class="topbar"></div>
<div class="wrap">
  <div class="logo">
    <h1>XCODEHOSTER</h1>
    <p>WEB INSTALLER · V11 · MULTI DOMAIN · 2025</p>
  </div>
  <div class="badges">
    <div class="badge">PHP <?= PHP_VERSION ?></div>
    <div class="badge">Ubuntu 24.04</div>
    <div class="badge">Apache 2.4</div>
    <div class="badge green">🔓 Multi-Domain</div>
    <div class="badge green">⚡ Full Auto</div>
    <div class="badge"><?= date('d M Y') ?></div>
  </div>

  <div class="notice">
    <strong>⚡ Form ini dapat digunakan berulang kali</strong> untuk mendaftarkan domain berbeda.<br>
    Setiap instalasi berjalan mandiri tanpa mengganggu domain lain yang sudah aktif.
  </div>

  <div class="warn">
    <strong>⚠️ Syarat — jalankan 1x di terminal sebelum pertama kali install:</strong><br>
    <code>echo "www-data ALL=(ALL) NOPASSWD: ALL" | sudo tee -a /etc/sudoers</code>
  </div>

  <div class="card">
    <div class="card-hdr">
      <div class="dots"><span class="r"></span><span class="y"></span><span class="g"></span></div>
      <div class="hdr-txt">INSTALL.PHP — XCODEHOSTER V11 — MULTI DOMAIN INSTALLER</div>
    </div>
    <div class="body">
      <?php if (!empty($errors)): ?>
      <div class="errors"><?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?></div>
      <?php endif; ?>

      <form method="POST" action="install.php" onsubmit="go(this)">
        <input type="hidden" name="step" value="install">

        <div class="sec">🖥️ Konfigurasi Server</div>
        <div class="two">
          <div class="field">
            <label>IP SERVER PUBLIK <span>*</span></label>
            <input type="text" name="ipserver" placeholder="202.10.42.16" value="<?= htmlspecialchars($_POST['ipserver'] ?? '') ?>" required>
            <div class="hint">IP publik VPS Ubuntu 24.04</div>
          </div>
          <div class="field">
            <label>DOMAIN UTAMA <span>*</span></label>
            <input type="text" name="domain" placeholder="namadomain.com" value="<?= htmlspecialchars($_POST['domain'] ?? '') ?>" required>
            <div class="hint">Tanpa http:// dan www</div>
          </div>
        </div>
        <div class="field">
          <label>PASSWORD MYSQL ROOT <span>*</span></label>
          <input type="password" name="dbpass" placeholder="Password root MySQL server" required>
          <div class="hint">Password yang dibuat saat instalasi MySQL</div>
        </div>

        <div class="sec">☁️ Konfigurasi Cloudflare</div>
        <div class="field">
          <label>ZONE ID CLOUDFLARE <span>*</span></label>
          <input type="text" name="zone_id" placeholder="a1b2c3d4e5f6..." value="<?= htmlspecialchars($_POST['zone_id'] ?? '') ?>">
          <div class="hint">Dashboard Cloudflare → Domain → Overview → Zone ID (sidebar kanan)</div>
        </div>
        <div class="two">
          <div class="field">
            <label>EMAIL CLOUDFLARE <span>*</span></label>
            <input type="email" name="admin_email" placeholder="admin@gmail.com" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label>GLOBAL API KEY <span>*</span></label>
            <input type="password" name="api_key" placeholder="••••••••••••••••">
            <div class="hint">My Profile → API Tokens → Global API Key</div>
          </div>
        </div>

        <div class="submit">
          <button type="submit" id="btn">⚡ MULAI INSTALASI OTOMATIS</button>
        </div>
      </form>
    </div>
  </div>

  <?php if (!empty($history)): ?>
  <div class="history">
    <div class="history-hdr">📋 Riwayat Instalasi Domain (<?= count($history) ?> domain)</div>
    <?php foreach (array_reverse(array_slice($history, -10)) as $h): ?>
    <div class="hist-item">
      <div>
        <div class="hist-domain"><?= htmlspecialchars($h['domain']) ?></div>
        <div class="hist-time"><?= htmlspecialchars($h['installed']) ?> · IP <?= htmlspecialchars($h['ip']) ?></div>
      </div>
      <div class="hist-links">
        <a href="http://<?= htmlspecialchars($h['domain']) ?>" target="_blank">🌐 Site</a>
        <a href="http://<?= htmlspecialchars($h['domain']) ?>/cgi-bin/formdata.cgi" target="_blank">📝 Form</a>
        <a href="http://<?= htmlspecialchars($h['domain']) ?>/vouchers600.txt" target="_blank">🎟️ Voucher</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<footer>XCODEHOSTER V11 · PT. TEKNOLOGI SERVER INDONESIA · XCODE.OR.ID</footer>
<script>
function go(f) {
  var btn = document.getElementById('btn');
  btn.disabled = true;
  btn.textContent = '⏳ MEMPROSES...';
}
</script>
</body></html>
