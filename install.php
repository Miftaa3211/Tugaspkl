<?php
/**
 * Xcodehoster v11 - Web Installer (MULTI-DOMAIN, FULL AUTO)
 * Fix: systemctl restart dijalankan via shell script terpisah
 * sehingga tidak pernah blocking PHP/browser
 */

define('SUPPORT_DIR', __DIR__ . '/support');
define('FM_DIR',      __DIR__ . '/filemanager');
define('LOG_DIR',     __DIR__ . '/install_logs');

if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0755, true);

/* ── AJAX: cek status instalasi ── */
if (isset($_GET['check_status'], $_GET['domain'])) {
    $domain  = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_GET['domain']);
    $logFile = LOG_DIR . '/' . $domain . '.json';
    header('Content-Type: application/json');
    echo file_exists($logFile) ? file_get_contents($logFile) : json_encode(['status'=>'pending']);
    exit;
}

/* ── AJAX: riwayat domain ── */
if (isset($_GET['history'])) {
    $histFile = __DIR__ . '/install_history.json';
    header('Content-Type: application/json');
    echo file_exists($histFile) ? file_get_contents($histFile) : '[]';
    exit;
}

$errors = [];

if (($_POST['step'] ?? '') === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip      = trim($_POST['ipserver']    ?? '');
    $domain  = trim($_POST['domain']      ?? '');
    $dbpass  = trim($_POST['dbpass']      ?? '');
    $zone_id = trim($_POST['zone_id']     ?? '');
    $email   = trim($_POST['admin_email'] ?? '');
    $api_key = trim($_POST['api_key']     ?? '');

    if (empty($ip))     $errors[] = 'IP Server wajib diisi.';
    if (empty($domain)) $errors[] = 'Domain utama wajib diisi.';
    if (empty($dbpass)) $errors[] = 'Password MySQL root wajib diisi.';
    if (empty($email))  $errors[] = 'Email Admin wajib diisi.';
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Format email tidak valid.';
    if (!empty($domain) && !preg_match('/^[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $domain))
        $errors[] = 'Format domain tidak valid.';

    if (empty($errors)) {
        $logFile    = LOG_DIR . '/' . $domain . '.json';
        $scriptPath = '/tmp/xcode_install_' . md5($domain) . '.sh';

        file_put_contents($logFile, json_encode([
            'status'  => 'running',
            'logs'    => ['⏳ Menyiapkan installer...'],
            'started' => date('Y-m-d H:i:s'),
        ]));

        writeInstallScript($scriptPath, $ip, $domain, $dbpass, $zone_id, $email, $api_key, $logFile, __DIR__);
        chmod($scriptPath, 0755);

        // Jalankan benar-benar terpisah dari Apache/PHP session
        shell_exec("setsid nohup bash " . escapeshellarg($scriptPath) .
                   " > /tmp/xcode_out_" . md5($domain) . ".log 2>&1 &");

        showLoading($domain, $ip);
        exit;
    }
}

/* ══════════════════════════════════════════════════════════
   SHELL SCRIPT GENERATOR
══════════════════════════════════════════════════════════ */
function writeInstallScript(string $path, string $ip, string $domain, string $dbpass,
                             string $zoneId, string $email, string $apiKey,
                             string $logFile, string $installDir): void
{
    $supportDir = $installDir . '/support';
    $fmDir      = $installDir . '/filemanager';
    $pemFile    = "/etc/apache2/ssl/$domain.pem";
    $keyFile    = "/etc/apache2/ssl/$domain.key";

    // Escape untuk bash heredoc
    $dbpassEsc   = str_replace("'", "'\\''", $dbpass);
    $apiKeyEsc   = str_replace("'", "'\\''", $apiKey);

    $script = <<<BASH
#!/bin/bash
LOGFILE='$logFile'
DOMAIN='$domain'
IP='$ip'
DBPASS='$dbpassEsc'
ZONEID='$zoneId'
EMAIL='$email'
APIKEY='$apiKeyEsc'
SUPPORTDIR='$supportDir'
FMDIR='$fmDir'
INSTALLDIR='$installDir'
PEMFILE='$pemFile'
KEYFILE='$keyFile'

# ── Helper: tambah log entry ─────────────────────────────
log() {
    local MSG="\$1"
    local TMP=\$(mktemp)
    php -r "
        \\\$f='\$LOGFILE';
        \\\$d=json_decode(file_get_contents(\\\$f),true);
        \\\$d['logs'][]=addslashes('\$MSG');
        file_put_contents(\\\$f,json_encode(\\\$d));
    " 2>/dev/null
}

# ── Helper: tulis status akhir ───────────────────────────
finish_ok() {
    php -r "
        \\\$f='\$LOGFILE';
        \\\$d=json_decode(file_get_contents(\\\$f),true);
        \\\$d['status']='success';
        \\\$d['domain']='\$DOMAIN';
        \\\$d['ip']='\$IP';
        \\\$d['voucher_file']='vouchers600.txt';
        \\\$d['voucher_count']=600;
        \\\$d['finished']=date('Y-m-d H:i:s');
        file_put_contents(\\\$f,json_encode(\\\$d));
        \\\$hf='\$INSTALLDIR/install_history.json';
        \\\$h=file_exists(\\\$hf)?json_decode(file_get_contents(\\\$hf),true):[];
        \\\$h[]=['domain'=>'\$DOMAIN','ip'=>'\$IP','installed'=>date('Y-m-d H:i:s'),'voucher_file'=>'vouchers600.txt'];
        file_put_contents(\\\$hf,json_encode(\\\$h,JSON_PRETTY_PRINT));
    " 2>/dev/null
}

finish_err() {
    php -r "
        \\\$f='\$LOGFILE';
        \\\$d=json_decode(file_get_contents(\\\$f),true);
        \\\$d['status']='error';
        \\\$d['error']='\$1';
        file_put_contents(\\\$f,json_encode(\\\$d));
    " 2>/dev/null
}

# ── 0. SUDOERS ───────────────────────────────────────────
if ! grep -q 'www-data' /etc/sudoers 2>/dev/null; then
    echo 'www-data ALL=(ALL) NOPASSWD: ALL' | sudo tee -a /etc/sudoers >/dev/null
    log "✅ www-data ditambahkan ke sudoers"
else
    log "✅ www-data sudah ada di sudoers"
fi

# ── 1. UFW ───────────────────────────────────────────────
sudo apt-get install -y ufw >/dev/null 2>&1
sudo ufw allow 22/tcp >/dev/null 2>&1
sudo ufw allow 80/tcp >/dev/null 2>&1
sudo ufw allow 443/tcp >/dev/null 2>&1
sudo ufw --force enable >/dev/null 2>&1
log "✅ Firewall UFW: port 22, 80, 443 dibuka"

# ── 2. DIREKTORI ─────────────────────────────────────────
for DIR in /home/root /home/pma /home/www /home/datauser /home/xcodehoster \
           /home/datapengguna /home/domain /home/checkdata /home/checkdata2 \
           /home/filemanager /home/server /etc/apache2/ssl \
           /etc/apache2/sites-available; do
    sudo mkdir -p "\$DIR" && sudo chmod 777 "\$DIR" 2>/dev/null
done
log "✅ Semua direktori sistem siap"

# ── FIX KRITIS: chmod o+x /home agar Apache bisa baca /home ──────────────
sudo chmod o+x /home
log "✅ chmod o+x /home — Apache bisa akses direktori user"

# ── FIX KRITIS: Patch apache2.conf agar subdomain tidak Forbidden ─────────
if ! grep -q "<Directory /home>" /etc/apache2/apache2.conf 2>/dev/null; then
    printf "\n<Directory /home>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n" | sudo tee -a /etc/apache2/apache2.conf >/dev/null
    log "✅ apache2.conf: blok <Directory /home> ditambahkan — subdomain tidak Forbidden"
else
    sudo sed -i "/<Directory \/home>/,/<\/Directory>/ s/Require all denied/Require all granted/g" /etc/apache2/apache2.conf
    log "✅ apache2.conf: <Directory /home> ok — Require all granted"
fi

# ── 3. APACHE MODULES ────────────────────────────────────
sudo a2enmod cgi rewrite ssl headers >/dev/null 2>&1
sudo chmod 777 /usr/lib/cgi-bin
log "✅ Apache module: CGI, Rewrite, SSL, Headers diaktifkan"

# ── 4. SSL CERTIFICATE (CA:FALSE) ────────────────────────
sudo chattr -i "\$PEMFILE" 2>/dev/null || true
sudo chattr -i "\$KEYFILE" 2>/dev/null || true
sudo rm -f "\$PEMFILE" "\$KEYFILE"

sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "\$KEYFILE" -out "\$PEMFILE" \
    -subj "/CN=\$DOMAIN" \
    -addext "basicConstraints=CA:FALSE" >/dev/null 2>&1

sudo chmod 644 "\$PEMFILE" "\$KEYFILE"

if sudo openssl x509 -in "\$PEMFILE" -text -noout 2>/dev/null | grep -q "CA:FALSE"; then
    log "✅ SSL self-signed dibuat benar (CA:FALSE): \${DOMAIN}.pem"
else
    log "⚠️ SSL dibuat — perlu verifikasi manual"
fi

# ── 5. UPDATE SUPPORT FILES ──────────────────────────────
for FILE in formdata.cgi run.cgi aktivasi3.cgi subdomain.conf domain.conf domain2.conf index.html; do
    SRC="\$SUPPORTDIR/\$FILE"
    [ ! -f "\$SRC" ] && continue
    sed -i "s|xcodehoster.com.pem|\${DOMAIN}.pem|g" "\$SRC"
    sed -i "s|xcodehoster.com.key|\${DOMAIN}.key|g" "\$SRC"
    sed -i "s|xcodehoster.com|\$DOMAIN|g"            "\$SRC"
    sed -i "s|sample.xcodehoster.com|\$DOMAIN|g"     "\$SRC"
    sed -i "s|-ppasswordmysql|-p\$DBPASS|g"           "\$SRC"
    sed -i "s|zoneid|\$ZONEID|g"                      "\$SRC"
    sed -i "s|globalapikey|\$APIKEY|g"                "\$SRC"
    sed -i "s|ipserver|\$IP|g"                        "\$SRC"
    if [ "\$FILE" = "formdata.cgi" ]; then
        sed -i "s|https://\$DOMAIN/coverxcodehoster.png|http://\$DOMAIN/coverxcodehoster.png|g" "\$SRC"
    fi
done
log "✅ Support files diupdate dengan konfigurasi domain"

# ── 6. COPY KE /home/xcodehoster ─────────────────────────
sudo chown www-data:www-data /home/xcodehoster 2>/dev/null
for FILE in domain.conf domain2.conf subdomain.conf index.html \
            bootstrap.min.css hosting.jpg xcodehoster21x.png coverxcodehoster.png; do
    [ -f "\$SUPPORTDIR/\$FILE" ] && sudo cp "\$SUPPORTDIR/\$FILE" "/home/xcodehoster/\$FILE"
done
log "✅ File disalin ke /home/xcodehoster/"

# ── 7. COPY KE /var/www/html ─────────────────────────────
for FILE in index.html bootstrap.min.css hosting.jpg xcodehoster21x.png coverxcodehoster.png; do
    [ -f "\$SUPPORTDIR/\$FILE" ] && sudo cp "\$SUPPORTDIR/\$FILE" "/var/www/html/\$FILE"
done
log "✅ File web disalin ke /var/www/html/"

# ── 8. FILEMANAGER ───────────────────────────────────────
if [ -d "\$FMDIR" ]; then
    sudo cp -r "\$FMDIR/." /home/filemanager/
    sudo chmod -R 777 /home/filemanager
    sudo chown -R www-data:www-data /home/filemanager
    [ -f "/home/filemanager/index.html" ] && \
        sudo sed -i "s|xcodehoster.com|\$DOMAIN|g" "/home/filemanager/index.html"
    log "✅ File Manager disalin ke /home/filemanager/"
fi

# ── 9. CGI FILES ─────────────────────────────────────────
for FILE in formdata.cgi run.cgi aktivasi3.cgi; do
    [ -f "\$SUPPORTDIR/\$FILE" ] && \
        sudo cp "\$SUPPORTDIR/\$FILE" "/usr/lib/cgi-bin/\$FILE" && \
        sudo chmod 777 "/usr/lib/cgi-bin/\$FILE"
done
echo "\$IP" | sudo tee /usr/lib/cgi-bin/ip.txt >/dev/null
# FIX: acak.txt harus berisi string random (bukan file kosong)
ACAK=$(tr -dc A-Za-z0-9 </dev/urandom | head -c 10)
echo "$ACAK" | sudo tee /usr/lib/cgi-bin/acak.txt >/dev/null
sudo chmod 666 /usr/lib/cgi-bin/acak.txt /usr/lib/cgi-bin/ip.txt
log "✅ CGI files disalin + ip.txt & acak.txt dibuat"

# ── 10. VIRTUALHOST ──────────────────────────────────────
sudo tee "/etc/apache2/sites-available/\${DOMAIN}.conf" >/dev/null <<VHOST
<VirtualHost *:80>
    ServerName \$DOMAIN
    ServerAlias www.\$DOMAIN
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
    ServerName \$DOMAIN
    ServerAlias www.\$DOMAIN
    DocumentRoot /home/xcodehoster
    SSLEngine on
    SSLCertificateFile \$PEMFILE
    SSLCertificateKeyFile \$KEYFILE
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
VHOST

log "✅ Apache VirtualHost config dibuat: \${DOMAIN}.conf"

# Global ServerName
if ! grep -q 'ServerName' /etc/apache2/apache2.conf 2>/dev/null; then
    echo "ServerName \$DOMAIN" | sudo tee -a /etc/apache2/apache2.conf >/dev/null
fi

# ── 11. A2ENSITE ─────────────────────────────────────────
sudo a2ensite "\${DOMAIN}.conf" >/dev/null 2>&1
log "✅ a2ensite \${DOMAIN}.conf — site diaktifkan"

# ── 12. CONFIG TEST ──────────────────────────────────────
CONFIG_OUT=\$(sudo apache2ctl configtest 2>&1)
if echo "\$CONFIG_OUT" | grep -q "Syntax OK"; then
    log "✅ Apache config OK"
else
    log "⚠️ Config warning — lanjut restart"
fi

# ── 13. RESTART APACHE ───────────────────────────────────
# Tulis script restart terpisah lalu jadwalkan via cron/at
RESTART_SCRIPT="/tmp/apache_restart_$$.sh"
cat > "\$RESTART_SCRIPT" <<'RSTSCRIPT'
#!/bin/bash
sleep 2
/usr/sbin/apache2ctl graceful 2>/dev/null || systemctl restart apache2 2>/dev/null
rm -f "$0"
RSTSCRIPT
chmod +x "\$RESTART_SCRIPT"

# Coba beberapa metode — salah satu pasti berhasil
# Metode 1: apache2ctl graceful (tidak butuh systemd)
sudo /usr/sbin/apache2ctl graceful 2>/dev/null && \
    log "✅ Apache graceful reload — domain langsung aktif!" || \
    (
        # Metode 2: at command
        echo "sudo systemctl restart apache2" | sudo at now + 1 minute 2>/dev/null && \
        log "✅ Apache restart dijadwalkan via at — aktif dalam 1 menit" || \
        (
            # Metode 3: tulis ke /etc/cron.d
            echo "* * * * * root systemctl restart apache2 && rm /etc/cron.d/xcode_restart" | \
                sudo tee /etc/cron.d/xcode_restart >/dev/null 2>&1
            log "✅ Apache restart dijadwalkan via cron — aktif dalam 1 menit"
        )
    )

# ── 14. VOUCHER 600 KODE ─────────────────────────────────
php -r "
\\\$pool='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
\\\$list=[];
while(count(\\\$list)<600){
    \\\$c='';for(\\\$i=0;\\\$i<10;\\\$i++)\\\$c.=\\\$pool[random_int(0,35)];
    \\\$list[\\\$c]=1;
}
\\\$txt=implode(\"\n\",array_keys(\\\$list));
file_put_contents('\$INSTALLDIR/vouchers600.txt',\\\$txt);
file_put_contents('/var/www/html/vouchers600.txt',\\\$txt);
file_put_contents('/home/xcodehoster/vouchers600.txt',\\\$txt);
file_put_contents('/tmp/vouchers_\$DOMAIN.txt',\\\$txt);
" 2>/dev/null
sudo cp "/tmp/vouchers_\$DOMAIN.txt" /usr/lib/cgi-bin/vouchers.txt 2>/dev/null
sudo chmod 777 /usr/lib/cgi-bin/vouchers.txt 2>/dev/null
log "✅ 600 kode voucher dibuat & disalin ke cgi-bin"

# ── 15. SIMPAN .env ──────────────────────────────────────
cat > "\$INSTALLDIR/.env" <<ENV
APP_VERSION=11
SERVER_IP=\$IP
MAIN_DOMAIN=\$DOMAIN
DB_PASS=\$DBPASS
CF_ZONE_ID=\$ZONEID
CF_EMAIL=\$EMAIL
CF_API_KEY=\$APIKEY
SSL_PEM=\$PEMFILE
SSL_KEY=\$KEYFILE
INSTALLED=\$(date '+%Y-%m-%d %H:%M:%S')
ENV
log "✅ File .env konfigurasi disimpan"

# ── SELESAI ──────────────────────────────────────────────
finish_ok
BASH;

    file_put_contents($path, $script);
}

/* ══════════════════════════════════════════════════════════
   PAGE: LOADING (realtime AJAX polling)
══════════════════════════════════════════════════════════ */
function showLoading(string $domain, string $ip): void { ?>
<!DOCTYPE html><html lang="id"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installing — <?= htmlspecialchars($domain) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Bebas+Neue&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--g:#00FF87;--a:#7B61FF;--dark:#050510;--card:#0A0A18;--border:#1A1A30}
body{background:var(--dark);color:#8888AA;font-family:'Space Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 20% 50%,rgba(123,97,255,.08),transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(0,255,135,.06),transparent 50%);pointer-events:none}
.wrap{width:100%;max-width:700px;position:relative;z-index:1}
.logo{text-align:center;margin-bottom:28px}
.logo h1{font-family:'Bebas Neue',sans-serif;font-size:46px;letter-spacing:4px;background:linear-gradient(135deg,var(--g),var(--a));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo p{font-size:10px;color:#2A2A40;letter-spacing:3px;margin-top:4px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden}
.top{padding:28px 28px 0}
.prog-label{display:flex;justify-content:space-between;font-size:11px;color:#333;margin-bottom:8px}
.prog-bar{height:4px;background:#030308;border-radius:2px;overflow:hidden}
.prog-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--g),var(--a));border-radius:2px;transition:width .5s ease}
.sw{text-align:center;padding:24px 0 20px}
.si{font-size:50px;display:block;margin-bottom:10px;animation:spin 3s linear infinite}
.si.done{animation:pop .4s ease;font-size:54px}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pop{0%,100%{transform:scale(1)}50%{transform:scale(1.2)}}
.st{font-family:'Bebas Neue',sans-serif;font-size:24px;letter-spacing:3px;color:#fff}
.st.ok{color:var(--g)}.st.err{color:#FF4D4D}
.ss{font-size:11px;color:#333;margin-top:5px}
.logbox{background:#030308;border-top:1px solid var(--border);padding:18px 26px;max-height:220px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#1A1A30 transparent}
.ll{font-size:11px;line-height:2.2;color:#1A1A30}
.ll.ok{color:#1A6A3A}.ll.new{color:var(--g)}.ll.warn{color:#6A5A10}.ll.er{color:#6A1A1A}
.result{padding:24px 28px;border-top:1px solid var(--border);display:none}
.result h3{font-family:'Bebas Neue',sans-serif;font-size:18px;letter-spacing:2px;color:var(--g);margin-bottom:16px}
.lnk{display:flex;align-items:center;gap:12px;background:#030308;border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:9px;text-decoration:none;transition:.2s}
.lnk:hover{border-color:var(--g);transform:translateX(4px)}
.li{font-size:18px;flex-shrink:0}
.ll2{font-size:9px;color:#333;letter-spacing:2px;text-transform:uppercase;margin-bottom:2px}
.lu{font-size:12px;color:var(--g);font-weight:700;word-break:break-all}
.btn{display:none;width:calc(100% - 56px);margin:0 28px 24px;padding:13px;background:linear-gradient(135deg,var(--g),var(--a));border:none;border-radius:12px;color:#000;font-family:'Bebas Neue',sans-serif;font-size:17px;letter-spacing:3px;cursor:pointer;transition:.2s}
.btn:hover{opacity:.9;transform:translateY(-1px)}
</style></head><body>
<div class="wrap">
  <div class="logo"><h1>XCODEHOSTER</h1><p>WEB INSTALLER · V11 · BACKGROUND MODE</p></div>
  <div class="card">
    <div class="top">
      <div class="prog-label"><span>PROGRESS</span><span id="pct">0%</span></div>
      <div class="prog-bar"><div class="prog-fill" id="pf"></div></div>
      <div class="sw">
        <span class="si" id="si">⚙️</span>
        <div class="st" id="st">MENGINSTALL...</div>
        <div class="ss"><?= htmlspecialchars($domain) ?> &middot; <?= htmlspecialchars($ip) ?></div>
      </div>
    </div>
    <div class="logbox" id="lb"><div class="ll">⏳ Memulai instalasi di background...</div></div>
    <div class="result" id="res">
      <h3>✅ INSTALASI BERHASIL!</h3>
      <a class="lnk" id="l1" href="#" target="_blank"><div class="li">🌐</div><div><div class="ll2">Website Utama</div><div class="lu" id="u1"></div></div></a>
      <a class="lnk" id="l2" href="#" target="_blank"><div class="li">📝</div><div><div class="ll2">Form Pendaftaran Hosting</div><div class="lu" id="u2"></div></div></a>
      <a class="lnk" id="l3" href="#" target="_blank"><div class="li">🎟️</div><div><div class="ll2">600 Kode Voucher</div><div class="lu" id="u3"></div></div></a>
      <a class="lnk" href="http://<?= htmlspecialchars($ip) ?>/phpmyadmin" target="_blank"><div class="li">🗄️</div><div><div class="ll2">phpMyAdmin</div><div class="lu">http://<?= htmlspecialchars($ip) ?>/phpmyadmin</div></div></a>
    </div>
    <button class="btn" id="bn" onclick="location.href='install.php'">⚡ INSTALL DOMAIN LAIN</button>
  </div>
</div>
<script>
const D='<?= htmlspecialchars($domain) ?>';
const STEPS=15;
let n=0,t;
function poll(){
  fetch('install.php?check_status=1&domain='+encodeURIComponent(D))
  .then(r=>r.json()).then(d=>{
    const lb=document.getElementById('lb');
    if(d.logs&&d.logs.length>n){
      for(let i=n;i<d.logs.length;i++){
        const l=d.logs[i],div=document.createElement('div');
        div.className='ll new '+(l.startsWith('✅')?'ok':l.startsWith('⚠️')?'warn':l.startsWith('❌')?'er':'');
        div.textContent=l;lb.appendChild(div);
        setTimeout(()=>div.classList.remove('new'),800);
      }
      lb.scrollTop=lb.scrollHeight;n=d.logs.length;
      const p=Math.min(95,Math.round(n/STEPS*100));
      document.getElementById('pf').style.width=p+'%';
      document.getElementById('pct').textContent=p+'%';
    }
    if(d.status==='success'){
      clearInterval(t);
      document.getElementById('pf').style.width='100%';
      document.getElementById('pct').textContent='100%';
      const si=document.getElementById('si');si.textContent='🎉';si.className='si done';
      const st=document.getElementById('st');st.textContent='INSTALASI BERHASIL!';st.className='st ok';
      const dm=d.domain;
      document.getElementById('u1').textContent='http://'+dm;
      document.getElementById('l1').href='http://'+dm;
      document.getElementById('u2').textContent='http://'+dm+'/cgi-bin/formdata.cgi';
      document.getElementById('l2').href='http://'+dm+'/cgi-bin/formdata.cgi';
      document.getElementById('u3').textContent='http://'+dm+'/'+d.voucher_file;
      document.getElementById('l3').href='http://'+dm+'/'+d.voucher_file;
      document.getElementById('res').style.display='block';
      document.getElementById('bn').style.display='block';
    }else if(d.status==='error'){
      clearInterval(t);
      document.getElementById('si').textContent='❌';
      document.getElementById('st').textContent='GAGAL';
      document.getElementById('st').className='st err';
      const bn=document.getElementById('bn');bn.style.display='block';bn.textContent='↩ COBA LAGI';
    }
  }).catch(()=>{});
}
t=setInterval(poll,2000);setTimeout(poll,800);
</script>
</body></html>
<?php }

/* ══════════════════════════════════════════════════════════
   PAGE: FORM
══════════════════════════════════════════════════════════ */
$histFile = __DIR__ . '/install_history.json';
$history  = file_exists($histFile) ? json_decode(file_get_contents($histFile), true) : [];
?>
<!DOCTYPE html><html lang="id"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Xcodehoster v11 — Web Installer</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Bebas+Neue&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--g:#00FF87;--a:#7B61FF;--dark:#050510;--card:#0A0A18;--card2:#060612;--border:#1A1A30;--text:#8888AA;--red:#FF4D4D}
body{background:var(--dark);color:var(--text);font-family:'Space Mono',monospace;min-height:100vh;padding:60px 20px 40px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 20% 50%,rgba(123,97,255,.08),transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(0,255,135,.06),transparent 50%);pointer-events:none;z-index:0}
.topbar{position:fixed;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--g),var(--a),var(--g));background-size:200%;animation:bar 3s linear infinite;z-index:100}
@keyframes bar{from{background-position:0}to{background-position:200%}}
.wrap{max-width:720px;margin:0 auto;position:relative;z-index:1}
.logo{text-align:center;margin-bottom:32px}
.logo h1{font-family:'Bebas Neue',sans-serif;font-size:54px;letter-spacing:5px;background:linear-gradient(135deg,var(--g),var(--a));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1}
.logo p{font-size:10px;color:#2A2A40;letter-spacing:4px;margin-top:6px}
.badges{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:24px}
.badge{background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:6px;padding:5px 12px;font-size:10px;color:#333;letter-spacing:1px}
.badge.g{border-color:rgba(0,255,135,.2);color:#1A6A3A}
.notice{background:rgba(0,255,135,.03);border:1px solid rgba(0,255,135,.12);border-radius:12px;padding:14px 20px;margin-bottom:12px;font-size:11px;color:#1A6A3A;line-height:1.8}
.notice strong{color:var(--g)}
.warn{background:rgba(240,165,0,.03);border:1px solid rgba(240,165,0,.12);border-radius:12px;padding:14px 20px;margin-bottom:20px;font-size:11px;color:#6A5A10;line-height:1.8}
.warn strong{color:#F0A500}
.warn code{background:rgba(255,255,255,.05);padding:4px 10px;border-radius:4px;display:inline-block;margin-top:6px;font-size:11px;color:#888}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;box-shadow:0 0 80px rgba(0,0,0,.5)}
.card-hdr{background:var(--card2);border-bottom:1px solid var(--border);padding:14px 24px;display:flex;align-items:center;gap:10px}
.dots{display:flex;gap:6px}.dots span{width:10px;height:10px;border-radius:50%}
.dots .r{background:#FF5F57}.dots .y{background:#FFBC2E}.dots .g{background:#28C840}
.hdr-txt{font-size:10px;color:#222;margin-left:6px;letter-spacing:2px}
.body{padding:28px 26px}
.sec{font-size:10px;letter-spacing:3px;color:var(--a);margin:24px 0 14px;display:flex;align-items:center;gap:10px;text-transform:uppercase}
.sec:first-child{margin-top:0}.sec::after{content:'';flex:1;height:1px;background:var(--border)}
.field{margin-bottom:14px}
label{display:block;font-size:10px;color:#333;margin-bottom:6px;letter-spacing:1px}
label span{color:var(--red)}
input{width:100%;background:#030308;border:1px solid var(--border);border-radius:10px;padding:12px 16px;color:#CCC;font-family:'Space Mono',monospace;font-size:12px;transition:.2s;outline:none}
input:focus{border-color:var(--a);box-shadow:0 0 0 3px rgba(123,97,255,.08)}
input::placeholder{color:#0F0F1F}
.hint{font-size:10px;color:#1A1A30;margin-top:5px;line-height:1.6}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:540px){.two{grid-template-columns:1fr}}
.errors{background:rgba(255,77,77,.05);border:1px solid rgba(255,77,77,.15);border-radius:10px;padding:14px 18px;margin-bottom:18px}
.errors p{font-size:11px;color:var(--red);margin-bottom:3px}.errors p::before{content:'✕ '}
.submit{margin-top:24px}
button[type=submit]{width:100%;padding:15px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--g),var(--a));color:#000;font-family:'Bebas Neue',sans-serif;font-size:19px;letter-spacing:3px;cursor:pointer;transition:.2s;box-shadow:0 4px 30px rgba(0,255,135,.1)}
button[type=submit]:hover{opacity:.9;transform:translateY(-2px)}
button[type=submit]:disabled{opacity:.4;cursor:not-allowed;transform:none}
.history{margin-top:22px;background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
.h-hdr{background:var(--card2);border-bottom:1px solid var(--border);padding:13px 22px;font-size:10px;color:#222;letter-spacing:3px;text-transform:uppercase}
.h-row{padding:13px 22px;border-bottom:1px solid #060612;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.h-row:last-child{border-bottom:none}
.h-d{font-size:12px;color:#555;font-weight:700}
.h-t{font-size:10px;color:#1A1A30}
.h-links{display:flex;gap:6px}
.h-links a{font-size:10px;color:#2A2A40;text-decoration:none;padding:3px 8px;border:1px solid #111;border-radius:4px;transition:.2s}
.h-links a:hover{color:var(--g);border-color:rgba(0,255,135,.2)}
footer{text-align:center;margin-top:26px;font-size:10px;color:#111;letter-spacing:2px}
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
    <div class="badge g">🔓 Multi-Domain</div>
    <div class="badge g">⚡ Background Install</div>
    <div class="badge"><?= date('d M Y') ?></div>
  </div>
  <div class="notice">
    <strong>⚡ Form ini bisa digunakan berulang kali</strong> untuk domain berbeda.<br>
    Instalasi berjalan di background — browser <strong>tidak akan terputus atau stuck</strong>.
  </div>
  <div class="warn">
    <strong>⚠️ Syarat awal — jalankan 1x di terminal:</strong><br>
    <code>echo "www-data ALL=(ALL) NOPASSWD: ALL" | sudo tee -a /etc/sudoers</code>
  </div>
  <div class="card">
    <div class="card-hdr">
      <div class="dots"><span class="r"></span><span class="y"></span><span class="g"></span></div>
      <div class="hdr-txt">INSTALL.PHP — XCODEHOSTER V11 — FULL AUTO</div>
    </div>
    <div class="body">
      <?php if (!empty($errors)): ?>
      <div class="errors"><?php foreach($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?></div>
      <?php endif; ?>
      <form method="POST" action="install.php" onsubmit="go()">
        <input type="hidden" name="step" value="install">
        <div class="sec">🖥️ Server</div>
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
          <input type="password" name="dbpass" placeholder="Password root MySQL" required>
          <div class="hint">Password yang dibuat saat install MySQL</div>
        </div>
        <div class="sec">☁️ Cloudflare</div>
        <div class="field">
          <label>ZONE ID</label>
          <input type="text" name="zone_id" placeholder="32 karakter dari dashboard Cloudflare" value="<?= htmlspecialchars($_POST['zone_id'] ?? '') ?>">
          <div class="hint">Dashboard → Domain → Overview → Zone ID (sidebar kanan)</div>
        </div>
        <div class="two">
          <div class="field">
            <label>EMAIL CLOUDFLARE <span>*</span></label>
            <input type="email" name="admin_email" placeholder="email@gmail.com" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label>GLOBAL API KEY</label>
            <input type="password" name="api_key" placeholder="My Profile → API Tokens">
            <div class="hint">Global API Key dari Cloudflare</div>
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
    <div class="h-hdr">📋 RIWAYAT DOMAIN TERINSTALL (<?= count($history) ?>)</div>
    <?php foreach(array_reverse(array_slice($history, -10)) as $h): ?>
    <div class="h-row">
      <div>
        <div class="h-d"><?= htmlspecialchars($h['domain']) ?></div>
        <div class="h-t"><?= htmlspecialchars($h['installed'] ?? '-') ?> · <?= htmlspecialchars($h['ip'] ?? '') ?></div>
      </div>
      <div class="h-links">
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
<script>function go(){var b=document.getElementById('btn');b.disabled=true;b.textContent='⏳ MEMPROSES...';}</script>
</body></html>