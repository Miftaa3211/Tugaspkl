<?php
// index.php - Halaman Landing Xcodehoster
$domain = $_SERVER['HTTP_HOST'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Xcodehoster v11</title>
    <link rel="stylesheet" href="support/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; text-align: center; padding-top: 50px; }
        .box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); display: inline-block; }
    </style>
</head>
<body>
    <div class="box">
        <img src="support/xcodehoster21x.png" alt="Logo" width="200">
        <h2>Xcodehoster</h2>
        <p>2025 - Versi 11 - Support Domain dan Mode Bisnis</p>
        <p>Hosting ini menggunakan PHP 8.3</p>
        <hr>
        <h4><a href="login.php" style="color: blue; text-decoration: underline;">Klik di sini untuk pendaftaran akun hosting</a></h4>
        <footer style="margin-top: 20px; font-size: 12px;">
            Programmer: Kurniawan. Email: kurniawanajazenfone@gmail.com
        </footer>
    </div>
</body>
</html>