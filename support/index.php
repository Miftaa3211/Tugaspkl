<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xcodehoster v11 - Web Installer</title>
    <link rel="stylesheet" href="support/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white text-center">
                        <h4 class="mb-0">Instalasi Xcodehoster v11 (PHP Edition)</h4>
                    </div>
                    <div class="card-body">
                        <form action="proses_install.php" method="POST">
                            <h5 class="text-secondary border-bottom pb-2">1. Konfigurasi Server Dasar</h5>
                            <div class="mb-3">
                                <label>IP Publik Server</label>
                                <input type="text" class="form-control" name="ipserver" value="202.10.42.16" required>
                            </div>
                            <div class="mb-3">
                                <label>Nama Domain</label>
                                <input type="text" class="form-control" name="domain" value="tugaspkl.my.id" required>
                            </div>
                            <div class="mb-3">
                                <label>Password MySQL Root (Baru)</label>
                                <input type="password" class="form-control" name="passwordmysql" required>
                            </div>

                            <h5 class="text-secondary border-bottom pb-2 mt-4">2. Integrasi Cloudflare API</h5>
                            <div class="mb-3">
                                <label>Zone ID Cloudflare</label>
                                <input type="text" class="form-control" name="zoneid" required>
                            </div>
                            <div class="mb-3">
                                <label>E-mail Akun Cloudflare</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="mb-3">
                                <label>Global API Key Cloudflare</label>
                                <input type="text" class="form-control" name="globalapikey" required>
                            </div>

                            <button type="submit" class="btn btn-success w-100 mt-4 py-2">Mulai Eksekusi Instalasi</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>