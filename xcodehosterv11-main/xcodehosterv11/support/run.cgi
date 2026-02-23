#!/bin/bash
echo "Content-type: text/html"
echo ""
read -n "$CONTENT_LENGTH" POST_DATA
namedomain=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[1]}' | awk '{split($0,array,"=")} END{print array[2]}' | tr '[:upper:]' '[:lower:]')
name=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[2]}' | awk '{split($0,array,"=")} END{print array[2]}' | tr '[:upper:]' '[:lower:]')
password=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[3]}' | awk '{split($0,array,"=")} END{print array[2]}')
email=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[4]}' | awk '{split($0,array,"=")} END{print array[2]}')
wa=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[5]}' | awk '{split($0,array,"=")} END{print array[2]}')
cek=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[6]}' | awk '{split($0,array,"=")} END{print array[2]}')
line=$(head -n 1 acak.txt)
ip=$(head -n 1 ip.txt)
tanggal=$(date +%d-%m-%Y)
random=$(tr -dc a-z0-9 </dev/urandom | head -c 13 ; echo '')
function urldecode() { : "${*//+/ }"; echo -e "${_//%/\x}"; }
email=$(printf '%b' "${email//%/\\x}")
if [[ "${namedomain}" =~ [^a-z0-9.-] ]]; then
echo "Domain hanya boleh huruf kecil, strip dan angka, domain harus ada titik"
else
if [[ "${name}" =~ [^a-z0-9-] ]]; then
echo "subdomain hanya boleh huruf kecil, strip dan angka"
else
if [[ "${password}" =~ [^a-z0-9] ]]; then
echo "Password hanya boleh huruf kecil dan angka"
else
if [[ "${email}" =~ [^a-z0-9.@-] ]]; then
echo "Hanya mendukung format e-mail"
else
if [[ $email =~ "@peykesabz.com" ]]; then
echo "Registrasi hanya mendukung peykesabz.com"
else
if [ -n "$(ls /home/checkdata/$email | xargs -n 1 basename)" ]; then
echo "E-mail ini sudah digunakan, silahkan gunakan e-mail yang lain"
else
if [ -n "$(ls /home/domain/$namedomain | xargs -n 1 basename)" ]; then
echo "Domain ini sudah digunakan, silahkan gunakan domain yang lain"
else
if ! grep -Fxq "$cek" vouchers.txt; then
echo "Kode voucher tersebut sudah digunakan, silahkan menggunakan voucher lain"
else
sed -i "/^$cek$/d" vouchers.txt
echo $namedomain > /home/domain/$namedomain
echo $email > /home/checkdata/$email
echo $name, $password, $email, $wa, $tanggal. > /home/checkdata2/$email
if [[ "${wa}" =~ [^a-z0-9] ]]; then
echo "Nomor WA hanya boleh angka"
else
if [ -z "$(ls -A /home/$name)" ]; then
cp /usr/lib/cgi-bin/image.png /var/www/html/
echo $name, $password, $email, $wa, $tanggal. > /home/datapengguna/$name.$tanggal
echo $name, $password, $email, $wa, $tanggal. > /home/recovery/$name.$random
sudo mcrypt /home/recovery/$name.$random -k $wa
rm /home/recovery/$name.$random
sudo chmod 777 /home/recovery/*
sudo mkdir /home/$name
sudo mkdir /home/$name/recovery
sudo cp /home/filemanager/* /home/$name
sudo cp /home/filemanager/* /home/$name/recovery
sudo chmod 777 /home/$name
sudo chmod 777 /home/$name/*
sudo chmod 777 /home/$name/recovery
sudo chmod 777 /home/$name/recovery/*
sed -i "s/unik/$password/g" /home/$name/config.php
sed -i "s/unik/$password/g" /home/$name/recovery/config.php
cp /home/xcodehoster/domain2.conf /etc/apache2/sites-available/$namedomain.conf
cp /home/xcodehoster/subdomain.conf /etc/apache2/sites-available/$name.conf
sed -i "s/contoh.com/$namedomain/g" /etc/apache2/sites-available/$namedomain.conf
sed -i "s/sample/$name/g" /etc/apache2/sites-available/$name.conf
sed -i "s/sample/$name/g" /etc/apache2/sites-available/$namedomain.conf
sudo a2ensite $namedomain.conf
sudo a2ensite $name.conf
sudo systemctl reload apache2
sudo cp /usr/lib/cgi-bin/aktivasi3.cgi /usr/lib/cgi-bin/aktivasi4.cgi
sed -i "s/unik/$name/g" /usr/lib/cgi-bin/aktivasi4.cgi
chmod 777 aktivasi4.cgi
./aktivasi4.cgi
rm aktivasi4.cgi
mysql -uroot -ppasswordmysql -e "CREATE USER '$name'@'localhost' IDENTIFIED WITH mysql_native_password BY '$password';"
mysql -uroot -ppasswordmysql -e "GRANT ALL PRIVILEGES ON $name.* TO '$name'@'localhost';"
mysql -uroot -ppasswordmysql -e "CREATE DATABASE $name;"
cat <<EOT
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Xcodehoster</title>
<style>
  /* Reset dan font */
  * {
    box-sizing: border-box;
  }
  body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f4f7fa;
    color: #333;
    line-height: 1.6;
    padding: 20px;
  }
  .container {
    max-width: 720px;
    margin: 0 auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 14px rgb(0 0 0 / 0.1);
    padding: 30px 40px;
  }
  h2 {
    margin-top: 0;
    color: #2a7ae2;
    font-weight: 700;
    font-size: 1.8rem;
    border-bottom: 2px solid #2a7ae2;
    padding-bottom: 10px;
  }
  p, a, span {
    font-size: 1rem;
  }
  a {
    color: #2a7ae2;
    text-decoration: none;
    word-break: break-all;
  }
  a:hover {
    text-decoration: underline;
  }
  .section {
    margin-top: 20px;
  }
  .label {
    font-weight: 600;
    color: #555;
  }
  .info {
    background: #eef4ff;
    border-left: 4px solid #2a7ae2;
    padding: 12px 16px;
    margin: 12px 0;
    border-radius: 4px;
    word-wrap: break-word;
  }
  .note {
    font-size: 0.9rem;
    color: #777;
    margin-top: 25px;
    border-top: 1px solid #ddd;
    padding-top: 12px;
  }
  @media (max-width: 480px) {
    .container {
      padding: 20px;
    }
    h2 {
      font-size: 1.5rem;
    }
  }
</style>
</head>
<body>
  <div class="container">
    <h2>Welcome <span style="color:#2a7ae2;">$name</span></h2>

    <div class="section">
      <div class="label">Alamat website anda untuk domain:</div>
      <div class="info">
        <a href="https://$namedomain" target="_blank" rel="noopener noreferrer">https://$namedomain</a><br />
        Arahkan domain ke IP address (DNS Cloudflare, SSL Flexible)<strong>$ip</strong>
      </div>
    </div>

    <div class="section">
      <div class="label">Alamat website anda untuk subdomain:</div>
      <div class="info">
        <a href="https://$name.xcodehoster.com" target="_blank" rel="noopener noreferrer">https://$name.xcodehoster.com</a><br />
        Login : <a href="https://$name.xcodehoster.com/login.php" target="_blank" rel="noopener noreferrer">https://$name.xcodehoster.com/login.php</a><br />
        Username : <strong>admin</strong><br />
        Password : <strong>$password</strong><br />
        Cara ganti password edit pada bagian <code>config.php</code>, cari password anda dan ganti dengan password yang baru
      </div>
    </div>

    <div class="section">
      <div class="label">Login phpMyAdmin</div>
      <div class="info">
        Login : <a href="https://$name.xcodehoster.com/phpmyadmin" target="_blank" rel="noopener noreferrer">https://$name.xcodehoster.com/phpmyadmin</a><br />
        Username : <strong>$name</strong><br />
        Password : <strong>$password</strong>
      </div>
    </div>

    <div class="section">
      <div class="label">Jika file <code>config.php</code> atau <code>login.php</code> terhapus</div>
      <div class="info">
        Login : <a href="https://$name.xcodehoster.com/recovery/login.php" target="_blank" rel="noopener noreferrer">https://$name.xcodehoster.com/recovery/login.php</a><br />
        Username : <strong>admin</strong><br />
        Password : <strong>$password</strong>
<br>
<br>
  <a href="https://wa.me/62$wa?text=Login%20website%3A%20https%3A%2F%2F$name.xcodehoster.com%2Flogin.php%0AUsername%3A%20admin%0APassword%3A%20$password%0A%0AphpMyAdmin%3A%20https%3A%2F%2F$name.xcodehoster.com%2Fphpmyadmin%0AUsername%3A%20$name%0APassword%3A%20$password"
     target="_blank"
     style="display: inline-block; background-color: #25D366; color: white; padding: 10px 20px;
            text-decoration: none; border-radius: 5px; font-weight: bold;">
    Kirim Info Login ke WhatsApp
  </a>
<br>
      </div>
    </div>

    <div class="note">
      Saat ini subdomain anda sudah aktif.<br />
      File <code>login.php</code> dan <code>config.php</code> tidak boleh dihapus.
    </div>
  </div>
</body>
</html>
EOT
else
echo "Subdomain yang anda masukkan sudah ada pemiliknya"
fi
fi
fi
fi
fi
fi
fi
fi
fi
fi
