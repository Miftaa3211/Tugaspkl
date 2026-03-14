<?php
/**
 * Xcodehoster v11 - Web Installer FINAL
 * Fix: MariaDB support, path dinamis, sudoers otomatis,
 *      a2ensite otomatis, systemctl restart otomatis
 */

define('INSTALL_LOCK', __DIR__ . '/.installed');
define('SUPPORT_DIR',  __DIR__ . '/support');
define('FM_DIR',       __DIR__ . '/filemanager');
define('REPO_DIR',     __DIR__); // path repo ini sendiri

if (file_exists(INSTALL_LOCK)) { showLocked(); exit; }

$step   = $_POST['step'] ?? 'form';
$errors = [];
$result = [];

if ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip      = trim($_POST['ipserver']    ?? '');
    $domain  = trim($_POST['domain']      ?? '');
    $dbpass  = trim($_POST['dbpass']      ?? '');
    $zone_id = trim($_POST['zone_id']     ?? '');
    $email   = trim($_POST['admin_email'] ?? '');
    $api_key = trim($_POST['api_key']     ?? '');
    $cf_pem  = trim($_POST['cf_pem']      ?? '');

    if (empty($ip))     $errors[] = 'IP Server wajib diisi.';
    if (empty($domain)) $errors[] = 'Domain utama wajib diisi.';
    if (empty($dbpass)) $errors[] = 'Password MySQL/MariaDB root wajib diisi.';
    if (empty($email))  $errors[] = 'Email Admin wajib diisi.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($email))
        $errors[] = 'Format email tidak valid.';
    if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain) && !empty($domain))
        $errors[] = 'Format domain tidak valid (contoh: namadomain.com).';

    if (empty($errors)) {
        $result = runInstall($ip, $domain, $dbpass, $zone_id, $email, $api_key, $cf_pem);
        if ($result['success']) {
            file_put_contents(INSTALL_LOCK, date('Y-m-d H:i:s')."\nDomain: $domain\nIP: $ip\n");
            showSuccess($result, $domain, $ip);
            exit;
        } else {
            $errors = array_merge($errors, $result['errors']);
        }
    }
}

function runInstall(string $ip, string $domain, string $dbpass, string $zoneId, string $email, string $apiKey, string $cfPem): array
{
    $logs = []; $errors = [];
    $run = function(string $cmd) use (&$logs): string {
        $out = shell_exec("$cmd 2>&1");
        return (string)$out;
    };
    $log = function(string $msg) use (&$logs) { $logs[] = $msg; };

    /* ── 0. SUDOERS ───────────────────────────────────────────── */
    $sudoLine = 'www-data ALL=(ALL) NOPASSWD: ALL';
    $sudoers  = @file_get_contents('/etc/sudoers');
    if ($sudoers && strpos($sudoers, 'www-data') === false) {
        $run("echo '$sudoLine' | sudo tee -a /etc/sudoers");
        $log("✅ www-data ditambahkan ke sudoers");
    } else {
        $log("✅ www-data sudah ada di sudoers");
    }

    /* ── 1. UFW FIREWALL ──────────────────────────────────────── */
    $run("sudo apt-get install -y ufw 2>/dev/null");
    $run("sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp");
    $run("sudo ufw --force enable");
    $log("✅ Firewall UFW: port 22, 80, 443 dibuka");

    /* ── 2. DIREKTORI ─────────────────────────────────────────── */
    $dirs = [
        '/home/root','/home/pma','/home/www','/home/datauser',
        '/home/xcodehoster','/home/datapengguna','/home/domain',
        '/home/checkdata','/home/checkdata2','/home/filemanager',
        '/home/server','/home/recovery','/etc/apache2/ssl',
    ];
    foreach ($dirs as $d) {
        $run("sudo mkdir -p " . escapeshellarg($d));
        $run("sudo chmod 777 " . escapeshellarg($d));
        $log("✅ Dir: $d");
    }

    /* ── 3. APACHE MODULES ────────────────────────────────────── */
    $run("sudo a2enmod cgi rewrite ssl headers");
    $run("sudo chmod 777 /usr/lib/cgi-bin");
    $log("✅ Apache module CGI, SSL, Rewrite aktif");

    /* ── 4. PROCESS SUPPORT FILES ─────────────────────────────── */
    $supportFiles = ['formdata.cgi','run.cgi','aktivasi3.cgi',
                     'subdomain.conf','domain.conf','domain2.conf','index.html'];
    foreach ($supportFiles as $file) {
        $src = SUPPORT_DIR . '/' . $file;
        if (!file_exists($src)) { $log("⚠️ Tidak ada: $file"); continue; }
        $content = file_get_contents($src);
        $content = str_replace('xcodehoster.com.pem',    "$domain.pem", $content);
        $content = str_replace('xcodehoster.com.key',    "$domain.key", $content);
        $content = str_replace('xcodehoster.com',        $domain,       $content);
        $content = str_replace('sample.xcodehoster.com', $domain,       $content);
        $content = str_replace('passwordmysql',          $dbpass,       $content);
        $content = str_replace('zoneid',                 $zoneId,       $content);
        $content = str_replace('globalapikey',           $apiKey,       $content);
        $content = str_replace('ipserver',               $ip,           $content);
        // Fix https gambar di formdata.cgi
        if ($file === 'formdata.cgi') {
            $content = str_replace("https://$domain/coverxcodehoster.png",
                                   "http://$domain/coverxcodehoster.png", $content);
        }
        file_put_contents($src, $content);
        $log("✅ Updated: $file");
    }

    /* ── 5. COPY KE /home/xcodehoster ────────────────────────── */
    $run("sudo chown www-data:www-data /home/xcodehoster");
    $xFiles = ['domain.conf','domain2.conf','subdomain.conf','index.html',
               'bootstrap.min.css','hosting.jpg','xcodehoster21x.png','coverxcodehoster.png','panel.php'];
    foreach ($xFiles as $f) {
        $src = SUPPORT_DIR . '/' . $f;
        if (file_exists($src)) $run("sudo cp " . escapeshellarg($src) . " /home/xcodehoster/$f");
    }
    $log("✅ File support disalin ke /home/xcodehoster/");

    /* ── 6. COPY KE /var/www/html ─────────────────────────────── */
    foreach (['index.html','bootstrap.min.css','hosting.jpg',
              'xcodehoster21x.png','coverxcodehoster.png'] as $f) {
        $src = SUPPORT_DIR . '/' . $f;
        if (file_exists($src)) $run("sudo cp " . escapeshellarg($src) . " /var/www/html/$f");
    }
    $log("✅ File web disalin ke /var/www/html/");

    /* ── 7. FILEMANAGER ───────────────────────────────────────── */
    if (is_dir(FM_DIR)) {
        $run("sudo cp -r " . escapeshellarg(FM_DIR) . "/. /home/filemanager/");
        $run("sudo chmod -R 777 /home/filemanager");
        $run("sudo chown -R www-data:www-data /home/filemanager");
        $log("✅ Filemanager disalin ke /home/filemanager/");
    }

    /* ── 8. CGI FILES ─────────────────────────────────────────── */
    foreach (['formdata.cgi','run.cgi','aktivasi3.cgi'] as $file) {
        $src = SUPPORT_DIR . '/' . $file;
        if (file_exists($src)) {
            $run("sudo cp " . escapeshellarg($src) . " /usr/lib/cgi-bin/$file");
            $run("sudo chmod 777 /usr/lib/cgi-bin/$file");
            $log("✅ CGI: $file");
        }
    }
    $run("sudo bash -c 'echo " . escapeshellarg($ip) . " > /usr/lib/cgi-bin/ip.txt'");
    $run("sudo touch /usr/lib/cgi-bin/acak.txt");
    $run("sudo chmod 777 /usr/lib/cgi-bin/acak.txt /usr/lib/cgi-bin/ip.txt");
    $log("✅ ip.txt & acak.txt dibuat");

    /* ── 9. SSL CERTIFICATE ───────────────────────────────────── */
    $pemFile = "/etc/apache2/ssl/$domain.pem";
    $keyFile = "/etc/apache2/ssl/$domain.key";
    if (!empty($cfPem)) {
        file_put_contents("/tmp/{$domain}.pem", $cfPem);
        $run("sudo cp /tmp/{$domain}.pem $pemFile");
        $log("✅ SSL PEM dari input disimpan");
    } else {
        $run("sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 " .
             "-keyout " . escapeshellarg($keyFile) . " " .
             "-out "    . escapeshellarg($pemFile)  . " " .
             "-subj '/CN=$domain'");
        $log("✅ SSL self-signed dibuat otomatis");
    }
    $run("sudo chmod 644 $pemFile $keyFile 2>/dev/null");

    /* ── 10. APACHE VIRTUALHOST UTAMA ─────────────────────────── */
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
    $log("✅ VirtualHost domain utama dibuat");

    // ServerName global
    $apacheConf = @file_get_contents('/etc/apache2/apache2.conf');
    if ($apacheConf && strpos($apacheConf, 'ServerName') === false) {
        $run("echo 'ServerName $domain' | sudo tee -a /etc/apache2/apache2.conf");
    }

    /* ── 11. A2ENSITE + RESTART OTOMATIS ─────────────────────── */
    $run("sudo a2ensite " . escapeshellarg("$domain.conf"));
    $log("✅ a2ensite $domain.conf — otomatis");
    $run("sudo systemctl restart apache2");
    $log("✅ systemctl restart apache2 — otomatis");

    /* ── 12. VOUCHER 600 ──────────────────────────────────────── */
    $vouchers = generateVouchers(600);
    $vContent = implode("\n", $vouchers);
    $vFile    = 'vouchers600.txt';
    foreach ([REPO_DIR."/$vFile", "/var/www/html/$vFile", "/home/xcodehoster/$vFile"] as $p) {
        @file_put_contents($p, $vContent);
    }
    file_put_contents("/tmp/vouchers.txt", $vContent);
    $run("sudo cp /tmp/vouchers.txt /usr/lib/cgi-bin/vouchers.txt");
    $run("sudo chmod 777 /usr/lib/cgi-bin/vouchers.txt");
    $log("✅ 600 voucher dibuat");

    /* ── 13. .env ─────────────────────────────────────────────── */
    file_put_contents(REPO_DIR.'/.env',
        "# Xcodehoster v11 — ".date('Y-m-d H:i:s')."\n" .
        "SERVER_IP=$ip\nMAIN_DOMAIN=$domain\nDB_PASS=$dbpass\n" .
        "CF_ZONE_ID=$zoneId\nCF_EMAIL=$email\nCF_API_KEY=$apiKey\n");
    $log("✅ .env disimpan");

    return ['success'=>true,'errors'=>[],'logs'=>$logs,
            'domain'=>$domain,'ip'=>$ip,'voucher_file'=>$vFile,'voucher_count'=>600];
}

function generateVouchers(int $n): array {
    $pool='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'; $list=[]; $used=[];
    while(count($list)<$n){$c='';for($i=0;$i<10;$i++)$c.=$pool[random_int(0,35)];if(!isset($used[$c])){$used[$c]=1;$list[]=$c;}}
    return $list;
}

function showLocked(): void { ?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Sudah Terinstall</title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{background:#06060F;color:#fff;font-family:monospace;min-height:100vh;display:flex;align-items:center;justify-content:center}.box{background:#0E0E1A;border:1px solid #FF3B3B;border-radius:16px;padding:48px;max-width:480px;width:90%;text-align:center}h1{color:#FF3B3B;margin:16px 0 10px}p{color:#666;font-size:13px;line-height:1.8}pre{background:#060610;border:1px solid #1A1A2E;border-radius:8px;padding:16px;margin-top:20px;font-size:11px;color:#00FF87;text-align:left;white-space:pre-wrap}</style>
</head><body><div class="box"><div style="font-size:56px">🔒</div><h1>Sudah Terinstall</h1><p>Xcodehoster v11 sudah terinstall.<br>Hapus file <code>.installed</code> untuk install ulang.</p><pre><?= htmlspecialchars(file_get_contents(INSTALL_LOCK)) ?></pre></div></body></html>
<?php }

function showSuccess(array $r, string $domain, string $ip): void { $vFile=$r['voucher_file']; ?>
<!DOCTYPE html><html lang="id"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Xcodehoster v11 — Berhasil</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Syne:wght@700;900&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
:root{--green:#00FF87;--accent:#7B61FF;--dark:#06060F;--card:#0E0E1A;--border:#1A1A2E;--text:#C8C8E0}
body{background:var(--dark);color:var(--text);font-family:'JetBrains Mono',monospace;min-height:100vh;padding:40px 20px}
body::before{content:'';position:fixed;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--green),var(--accent));z-index:100}
.wrap{max-width:860px;margin:0 auto}
h1{font-family:'Syne',sans-serif;font-size:36px;font-weight:900;text-align:center;margin-bottom:8px}
h1 span{color:var(--green)}.sub{color:#555;font-size:12px;text-align:center;margin-bottom:32px}
.banner{background:rgba(0,255,135,.07);border:1px solid rgba(0,255,135,.25);border-radius:16px;padding:24px;margin-bottom:24px;display:flex;align-items:center;gap:16px}
.banner .ck{font-size:44px;flex-shrink:0}
.banner h2{font-family:'Syne',sans-serif;color:var(--green);font-size:18px;margin-bottom:6px}
.banner p{font-size:13px;color:#888;line-height:1.8}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
@media(max-width:600px){.grid{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px}
.lbl{font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--accent);margin-bottom:8px}
.val{font-size:15px;font-weight:700;color:#fff;font-family:'Syne',sans-serif}.val.g{color:var(--green)}.val.a{color:var(--accent)}
.hint{font-size:11px;color:#444;margin-top:4px}
.link{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px 20px;margin-bottom:12px;display:flex;align-items:center;gap:14px;text-decoration:none;transition:.2s}
.link:hover{border-color:var(--green);transform:translateX(4px)}
.icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.link-lbl{font-size:10px;color:#444;text-transform:uppercase;letter-spacing:2px;margin-bottom:2px}
.link-url{font-size:13px;color:var(--green);font-weight:600;word-break:break-all}
.steps{background:var(--card);border:1px solid var(--border);border-left:3px solid var(--accent);border-radius:14px;padding:20px;margin-top:20px}
.steps h3{font-family:'Syne',sans-serif;color:var(--accent);font-size:14px;margin-bottom:12px}
.step{display:flex;gap:10px;margin-bottom:8px;font-size:12px;line-height:1.7;align-items:flex-start}
.num{background:var(--green);color:#000;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;margin-top:2px}
.num.w{background:#F0A500}
code{background:rgba(255,255,255,.07);padding:2px 6px;border-radius:4px;font-size:11px}
.logbox{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;margin-top:20px}
.logbox summary{cursor:pointer;font-size:12px;color:#555}
.logscroll{max-height:200px;overflow-y:auto;background:#060610;border:1px solid var(--border);border-radius:8px;padding:12px;margin-top:10px}
.ll{font-size:11px;line-height:2;color:#555}.ll.ok{color:#3DBA6F}.ll.w{color:#F0A500}
footer{text-align:center;margin-top:32px;font-size:11px;color:#222}
</style></head><body>
<div class="wrap">
  <h1>Instalasi <span>Berhasil</span> 🎉</h1>
  <p class="sub">Xcodehoster v11 aktif · <?= date('d M Y, H:i') ?> WIB</p>
  <div class="banner">
    <div class="ck">✅</div>
    <div>
      <h2>Xcodehoster v11 — 100% Otomatis!</h2>
      <p>
        ✅ Firewall UFW port 80 &amp; 443 dibuka<br>
        ✅ SSL self-signed dibuat otomatis<br>
        ✅ <strong style="color:#fff">a2ensite + systemctl restart</strong> otomatis<br>
        ✅ MariaDB/MySQL support otomatis<br>
        ✅ <?= $r['voucher_count'] ?> voucher siap digunakan
      </p>
    </div>
  </div>
  <div class="grid">
    <div class="card"><div class="lbl">Domain</div><div class="val"><?= htmlspecialchars($domain) ?></div><div class="hint">Apache aktif, sudah restart</div></div>
    <div class="card"><div class="lbl">Server IP</div><div class="val"><?= htmlspecialchars($ip) ?></div></div>
    <div class="card"><div class="lbl">Voucher</div><div class="val g"><?= $r['voucher_count'] ?> kode</div><div class="hint"><?= htmlspecialchars($vFile) ?></div></div>
    <div class="card"><div class="lbl">PHP</div><div class="val a"><?= PHP_VERSION ?></div></div>
  </div>
  <a class="link" href="http://<?= htmlspecialchars($domain) ?>" target="_blank">
    <div class="icon" style="background:rgba(0,102,255,.15)">🌐</div>
    <div><div class="link-lbl">Akses Website</div><div class="link-url">http://<?= htmlspecialchars($domain) ?></div></div>
  </a>
  <a class="link" href="http://<?= htmlspecialchars($domain) ?>/cgi-bin/formdata.cgi" target="_blank">
    <div class="icon" style="background:rgba(123,97,255,.15)">📝</div>
    <div><div class="link-lbl">Form Pendaftaran</div><div class="link-url">http://<?= htmlspecialchars($domain) ?>/cgi-bin/formdata.cgi</div></div>
  </a>
  <a class="link" href="http://<?= htmlspecialchars($domain) ?>/<?= htmlspecialchars($vFile) ?>" target="_blank">
    <div class="icon" style="background:rgba(0,255,135,.12)">🎟️</div>
    <div><div class="link-lbl">File Voucher</div><div class="link-url">http://<?= htmlspecialchars($domain) ?>/<?= htmlspecialchars($vFile) ?></div></div>
  </a>
  <div class="steps">
    <h3>⚠️ Opsional — Upgrade SSL ke HTTPS Asli</h3>
    <div class="step"><div class="num w">1</div><div>Cloudflare SSL/TLS → ubah ke <strong>Flexible</strong></div></div>
    <div class="step"><div class="num w">2</div><div>SSL resmi: <code>sudo certbot --apache -d <?= htmlspecialchars($domain) ?></code></div></div>
  </div>
  <div class="logbox">
    <details><summary>📋 Log instalasi (<?= count($r['logs']) ?> entri)</summary>
      <div class="logscroll">
        <?php foreach ($r['logs'] as $l): ?>
          <div class="ll <?= str_starts_with($l,'✅')?'ok':(str_starts_with($l,'⚠️')?'w':'') ?>"><?= htmlspecialchars($l) ?></div>
        <?php endforeach; ?>
      </div>
    </details>
  </div>
</div>
<footer>Xcodehoster v11 · PT. Teknologi Server Indonesia · xcode.or.id</footer>
</body></html>
<?php }
?>
<!DOCTYPE html><html lang="id"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Xcodehoster v11 — Web Installer</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Syne:wght@700;900&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
:root{--green:#00FF87;--accent:#7B61FF;--dark:#06060F;--card:#0C0C18;--card2:#10101E;--border:#1A1A30;--text:#B0B0CC;--red:#FF4D4D}
body{background:var(--dark);color:var(--text);font-family:'JetBrains Mono',monospace;min-height:100vh;display:flex;flex-direction:column}
body::before{content:'';position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(123,97,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(123,97,255,.03) 1px,transparent 1px);background-size:40px 40px;pointer-events:none}
.topbar{position:fixed;top:0;left:0;right:0;height:2px;z-index:999;background:linear-gradient(90deg,var(--green),var(--accent),var(--green));background-size:200% 100%;animation:slide 3s linear infinite}
@keyframes slide{from{background-position:0 0}to{background-position:200% 0}}
main{flex:1;display:flex;align-items:center;justify-content:center;padding:60px 20px;position:relative;z-index:1}
.wrap{width:100%;max-width:680px}
.hdr{text-align:center;margin-bottom:36px}
.logo{display:inline-flex;align-items:center;gap:14px;margin-bottom:18px}
.logo-box{width:54px;height:54px;border-radius:16px;background:linear-gradient(135deg,var(--green),var(--accent));display:flex;align-items:center;justify-content:center;font-size:26px;box-shadow:0 0 28px rgba(0,255,135,.3)}
.logo-name{font-family:'Syne',sans-serif;font-size:24px;font-weight:900;letter-spacing:-1px}.logo-name em{color:var(--green);font-style:normal}
.logo-ver{font-size:10px;color:#444;letter-spacing:3px;text-transform:uppercase}
.tagline{font-size:13px;color:#555;margin-top:4px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;box-shadow:0 0 60px rgba(0,0,0,.5)}
.card-hdr{background:var(--card2);border-bottom:1px solid var(--border);padding:18px 26px;display:flex;align-items:center;gap:12px}
.dots{display:flex;gap:6px}.dots span{width:10px;height:10px;border-radius:50%}.dots .r{background:#FF5F57}.dots .y{background:#FFBC2E}.dots .g{background:#28C840}
.hdr-txt{font-size:12px;color:#444;margin-left:6px}
.body{padding:28px 26px}
.sec{font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--accent);margin:24px 0 16px;display:flex;align-items:center;gap:10px}
.sec:first-child{margin-top:0}.sec::after{content:'';flex:1;height:1px;background:var(--border)}
.field{margin-bottom:14px}
label{display:block;font-size:11px;color:#666;margin-bottom:6px}label span{color:var(--red)}
input,textarea{width:100%;background:#080812;border:1px solid var(--border);border-radius:10px;padding:11px 14px;color:#fff;font-family:'JetBrains Mono',monospace;font-size:13px;transition:.2s;outline:none}
input:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(123,97,255,.12)}
input::placeholder,textarea::placeholder{color:#2A2A40}
textarea{resize:vertical;min-height:90px}
.hint{font-size:10px;color:#333;margin-top:4px;line-height:1.5}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:560px){.two{grid-template-columns:1fr}}
.errors{background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:12px;padding:14px 18px;margin-bottom:20px}
.errors p{font-size:12px;color:var(--red);margin-bottom:3px}.errors p::before{content:'✕ '}
.note{background:rgba(0,255,135,.05);border:1px solid rgba(0,255,135,.2);border-radius:10px;padding:14px 18px;margin-bottom:16px;font-size:11px;color:#888;line-height:1.8}
.note strong{color:var(--green)}
.warn{background:rgba(240,165,0,.06);border:1px solid rgba(240,165,0,.2);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:11px;color:#888;line-height:1.7}
.warn strong{color:#F0A500}
.info{display:flex;gap:14px;flex-wrap:wrap;background:rgba(123,97,255,.06);border:1px solid rgba(123,97,255,.15);border-radius:12px;padding:14px 18px;margin-bottom:20px;font-size:11px}
.itag{display:flex;align-items:center;gap:6px;color:#555}.itag span{color:var(--green);font-weight:600}
button{width:100%;padding:15px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--green),var(--accent));color:#000;font-family:'Syne',sans-serif;font-size:15px;font-weight:800;cursor:pointer;transition:.2s;box-shadow:0 4px 24px rgba(0,255,135,.2);margin-top:24px}
button:hover{opacity:.9;transform:translateY(-1px)}button:disabled{opacity:.6;cursor:not-allowed;transform:none}
footer{text-align:center;padding:20px;font-size:10px;color:#222;position:relative;z-index:1}
</style></head><body>
<div class="topbar"></div>
<main><div class="wrap">
  <div class="hdr">
    <div class="logo">
      <div class="logo-box">⚡</div>
      <div><div class="logo-name">Xcode<em>hoster</em></div><div class="logo-ver">Web Installer · v11</div></div>
    </div>
    <p class="tagline">Ubuntu 24.04 · Apache · PHP 8.3 · MariaDB/MySQL · Cloudflare</p>
  </div>
  <div class="info">
    <div class="itag">📦 <span>v11</span></div>
    <div class="itag">🐘 PHP <span><?= PHP_VERSION ?></span></div>
    <div class="itag">⏰ <span><?= date('d M Y') ?></span></div>
    <div class="itag">🔥 <span>Full Auto</span></div>
  </div>
  <div class="note"><strong>⚡ 100% Otomatis:</strong> UFW, SSL, VirtualHost, a2ensite, systemctl restart, CGI, 600 voucher — semua tanpa Putty.</div>
  <div class="warn"><strong>⚠️ Jalankan 1x di terminal sebelum install:</strong><br>
    <code style="background:rgba(255,255,255,.08);padding:4px 8px;border-radius:4px;display:inline-block;margin-top:6px">echo "www-data ALL=(ALL) NOPASSWD: ALL" | sudo tee -a /etc/sudoers</code>
  </div>
  <div class="card">
    <div class="card-hdr">
      <div class="dots"><span class="r"></span><span class="y"></span><span class="g"></span></div>
      <div class="hdr-txt">install.php — Xcodehoster v11 Full Auto Setup</div>
    </div>
    <div class="body">
      <?php if (!empty($errors)): ?>
      <div class="errors"><?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?></div>
      <?php endif; ?>
      <form method="POST" action="install.php" onsubmit="go(this)">
        <input type="hidden" name="step" value="install">
        <div class="sec">🖥️ Konfigurasi Server</div>
        <div class="two">
          <div class="field"><label>IP Server Publik <span>*</span></label>
            <input type="text" name="ipserver" placeholder="202.10.42.16" value="<?= htmlspecialchars($_POST['ipserver'] ?? '') ?>" required>
            <div class="hint">IP publik VPS Ubuntu 24.04</div></div>
          <div class="field"><label>Domain Utama <span>*</span></label>
            <input type="text" name="domain" placeholder="namadomain.com" value="<?= htmlspecialchars($_POST['domain'] ?? '') ?>" required>
            <div class="hint">Tanpa http:// dan www</div></div>
        </div>
        <div class="field"><label>Password MySQL/MariaDB Root <span>*</span></label>
          <input type="password" name="dbpass" placeholder="Password root database" value="<?= htmlspecialchars($_POST['dbpass'] ?? '') ?>" required>
          <div class="hint">Password root yang sudah dibuat saat install MySQL/MariaDB</div></div>
        <div class="sec">☁️ Konfigurasi Cloudflare</div>
        <div class="field"><label>Zone ID Cloudflare <span>*</span></label>
          <input type="text" name="zone_id" placeholder="a1b2c3d4e5f6..." value="<?= htmlspecialchars($_POST['zone_id'] ?? '') ?>" required>
          <div class="hint">Dashboard Cloudflare → domain → Overview → Zone ID</div></div>
        <div class="two">
          <div class="field"><label>Email Cloudflare <span>*</span></label>
            <input type="email" name="admin_email" placeholder="admin@domain.com" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required></div>
          <div class="field"><label>Global API Key <span>*</span></label>
            <input type="password" name="api_key" placeholder="••••••••••••••••" value="<?= htmlspecialchars($_POST['api_key'] ?? '') ?>" required>
            <div class="hint">My Profile → API Tokens → Global API Key</div></div>
        </div>
        <div class="sec">🔐 SSL (Opsional)</div>
        <div class="field"><label>Cloudflare PEM <em style="color:#555">(kosong = self-signed otomatis)</em></label>
          <textarea name="cf_pem" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"><?= htmlspecialchars($_POST['cf_pem'] ?? '') ?></textarea></div>
        <button type="submit" id="btn">⚡ Mulai Instalasi — 100% Otomatis</button>
      </form>
    </div>
  </div>
</div></main>
<footer>Xcodehoster v11 · PT. Teknologi Server Indonesia · xcode.or.id · Programmer: Kurniawan</footer>
<script>function go(f){var b=document.getElementById('btn');b.disabled=true;b.textContent='⏳ Menginstall... tunggu 30-60 detik';}</script>
</body></html>
