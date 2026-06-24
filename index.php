<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

/* =========================================================
   LOAD .ENV FILE
   Membaca file .env (jika ada) dan memuat variabel-variabel
   di dalamnya ke environment. File .env sudah di .gitignore
   sehingga aman untuk menyimpan token/kredensial.
   ========================================================= */
$envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            // Lewati baris komentar (diawali #)
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                // Hanya set jika belum ada di environment asli (agar env nyata lebih prioritas)
                if (getenv($key) === false || getenv($key) === '') {
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}

/* =========================================================
   KONFIGURASI DATABASE HOSTING
   SEMUA kredensial WAJIB diisi lewat file .env atau
   Environment Variables hosting. Tidak ada hardcoded default
   untuk alasan keamanan.
   ========================================================= */
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = (int) (getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASSWORD') ?: '';
$dbSslCa = __DIR__ . DIRECTORY_SEPARATOR . 'ca.pem';
$fonnteToken = getenv('FONNTE_TOKEN') ?: '';

$pdo = null;
$dbError = null;
$flash = null;
$reminder = null;

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatTanggal(?string $date): string
{
    if (!$date) {
        return '-';
    }

    return date('d M Y', strtotime($date));
}

function selisihHariText(string $tanggalKembali): array
{
    $today = new DateTimeImmutable('today');
    $target = new DateTimeImmutable($tanggalKembali);
    $days = (int) $today->diff($target)->format('%r%a');

    if ($days > 0) {
        return ["{$days} hari lagi", 'text-teal-700 bg-teal-50 border-teal-100'];
    }

    if ($days === 0) {
        return ['Hari ini', 'text-amber-700 bg-amber-50 border-amber-100'];
    }

    return ['Sudah lewat ' . abs($days) . ' hari', 'text-rose-700 bg-rose-50 border-rose-100'];
}

function normalizePhone(string $phone): string
{
    $phone = preg_replace('/\s+/', '', trim($phone));
    if (str_starts_with($phone, '08')) {
        return '+62' . substr($phone, 1);
    }

    return $phone;
}

function fonnteTargetNumber(string $phone): string
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    if (str_starts_with($phone, '+62')) {
        return '62' . substr($phone, 3);
    }

    if (str_starts_with($phone, '08')) {
        return '62' . substr($phone, 1);
    }

    return ltrim($phone, '+');
}

function sendFonnteMessage(string $token, string $target, string $message): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Ekstensi cURL PHP belum aktif, sehingga pesan Fonnte belum bisa dikirim.');
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'target' => $target,
            'message' => $message,
            'countryCode' => '0',
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $token,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $caFile = 'D:\\php\\extras\\ssl\\cacert.pem';
    if (file_exists($caFile)) {
        curl_setopt($curl, CURLOPT_CAINFO, $caFile);
    }

    $response = curl_exec($curl);
    $error = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Gagal menghubungi Fonnte: ' . $error);
    }

    $decoded = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("Fonnte mengembalikan HTTP {$httpCode}: {$response}");
    }

    if (is_array($decoded) && array_key_exists('status', $decoded) && !$decoded['status']) {
        $reason = $decoded['reason'] ?? $decoded['message'] ?? $response;
        throw new RuntimeException('Fonnte menolak pengiriman: ' . (is_array($reason) ? json_encode($reason) : $reason));
    }

    return is_array($decoded) ? $decoded : ['raw' => $response];
}

function connectDatabase(string $host, int $port, string $name, string $user, string $pass, string $sslCa): PDO
{
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if (file_exists($sslCa) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    }

    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = file_exists($sslCa);
    }

    return new PDO($dsn, $user, $pass, $options);
}

try {
    $pdo = connectDatabase($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $dbSslCa);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pasien_kb (
            id INT NOT NULL AUTO_INCREMENT,
            nama_pasien VARCHAR(150) NOT NULL,
            no_whatsapp VARCHAR(30) NOT NULL,
            tipe_kb ENUM('1 Bulan', '3 Bulan') NOT NULL,
            tanggal_suntik_terakhir DATE NOT NULL,
            tanggal_kembali DATE NOT NULL,
            status_alarm ENUM('Aktif', 'Terkirim') NOT NULL DEFAULT 'Aktif',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $nama = trim((string) ($_POST['nama_pasien'] ?? ''));
            $whatsapp = normalizePhone((string) ($_POST['no_whatsapp'] ?? ''));
            $tipeKb = (string) ($_POST['tipe_kb'] ?? '');
            $tanggalSuntik = (string) ($_POST['tanggal_suntik_terakhir'] ?? date('Y-m-d'));

            if ($nama === '' || $whatsapp === '' || !in_array($tipeKb, ['1 Bulan', '3 Bulan'], true)) {
                throw new RuntimeException('Mohon lengkapi nama, WhatsApp, dan tipe KB dengan benar.');
            }

            if (!preg_match('/^(\+62|08)[0-9]{8,15}$/', str_replace(' ', '', (string) ($_POST['no_whatsapp'] ?? '')))) {
                throw new RuntimeException('Nomor WhatsApp harus diawali +62 atau 08.');
            }

            $daysToAdd = $tipeKb === '1 Bulan' ? 30 : 90;
            $tanggalKembali = (new DateTimeImmutable($tanggalSuntik))
                ->modify("+{$daysToAdd} days")
                ->format('Y-m-d');

            $stmt = $pdo->prepare("
                INSERT INTO pasien_kb
                    (nama_pasien, no_whatsapp, tipe_kb, tanggal_suntik_terakhir, tanggal_kembali, status_alarm)
                VALUES
                    (:nama, :whatsapp, :tipe, :tanggal_suntik, :tanggal_kembali, 'Aktif')
            ");
            $stmt->execute([
                ':nama' => $nama,
                ':whatsapp' => $whatsapp,
                ':tipe' => $tipeKb,
                ':tanggal_suntik' => $tanggalSuntik,
                ':tanggal_kembali' => $tanggalKembali,
            ]);

            $flash = ['type' => 'success', 'message' => 'Jadwal pasien berhasil ditambahkan.'];
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM pasien_kb WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $flash = ['type' => 'success', 'message' => 'Data pasien berhasil dihapus.'];
        }

        if ($action === 'remind') {
            if ($fonnteToken === '') {
                throw new RuntimeException('Token Fonnte belum diatur. Buat file .env (copy dari .env.example) dan isi FONNTE_TOKEN, atau set Environment Variable FONNTE_TOKEN di hosting.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM pasien_kb WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $patient = $stmt->fetch();

            if (!$patient) {
                throw new RuntimeException('Data pasien tidak ditemukan.');
            }

            [$diffText] = selisihHariText($patient['tanggal_kembali']);
            $message = "Halo Ibu {$patient['nama_pasien']}, ini pengingat dari Klinik. Jadwal suntik KB {$patient['tipe_kb']} Anda berikutnya adalah tanggal " . formatTanggal($patient['tanggal_kembali']) . " ({$diffText}). Harap datang tepat waktu demi kesehatan keluarga.";
            $target = fonnteTargetNumber($patient['no_whatsapp']);
            $fonnteResponse = sendFonnteMessage($fonnteToken, $target, $message);

            $update = $pdo->prepare("UPDATE pasien_kb SET status_alarm = 'Terkirim' WHERE id = :id");
            $update->execute([':id' => $id]);

            $reminder = [
                'nama' => $patient['nama_pasien'],
                'whatsapp' => $patient['no_whatsapp'],
                'message' => $message,
                'target' => $target,
                'response' => $fonnteResponse,
            ];
            $flash = ['type' => 'success', 'message' => 'Pengingat WhatsApp berhasil dikirim melalui Fonnte.'];
        }
    } catch (Throwable $exception) {
        $flash = ['type' => 'error', 'message' => $exception->getMessage()];
    }
}

$patients = [];
if ($pdo) {
    try {
        $patients = $pdo->query('SELECT * FROM pasien_kb ORDER BY tanggal_kembali ASC, id DESC')->fetchAll();
    } catch (PDOException $exception) {
        if (($exception->errorInfo[1] ?? null) === 2006 || str_contains($exception->getMessage(), 'server has gone away')) {
            $pdo = connectDatabase($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $dbSslCa);
            $patients = $pdo->query('SELECT * FROM pasien_kb ORDER BY tanggal_kembali ASC, id DESC')->fetchAll();
        } else {
            throw $exception;
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alarm Pengingat Suntik KB Pintar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui'] },
                    boxShadow: {
                        soft: '0 18px 60px rgba(15, 118, 110, 0.12)',
                        card: '0 10px 35px rgba(15, 23, 42, 0.08)'
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <main class="mx-auto min-h-screen w-full max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
        <section class="mb-6 overflow-hidden rounded-2xl bg-white shadow-soft ring-1 ring-slate-100">
            <div class="bg-gradient-to-r from-teal-600 via-teal-500 to-cyan-500 px-5 py-6 text-white sm:px-8">
                <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div class="mb-3 inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-sm font-medium backdrop-blur">
                            <i class="fa-solid fa-shield-heart"></i>
                            Klinik KB Digital
                        </div>
                        <h1 class="text-2xl font-bold tracking-tight sm:text-4xl">Alarm Pengingat Suntik KB Pintar</h1>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-teal-50 sm:text-base">Kelola jadwal suntik ulang, pantau selisih hari, dan simulasikan pengingat WhatsApp dalam satu halaman.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 sm:min-w-72">
                        <div class="rounded-xl bg-white/15 p-4 backdrop-blur">
                            <p class="text-xs uppercase tracking-wide text-teal-50">Total Pasien</p>
                            <p class="mt-1 text-3xl font-bold"><?= count($patients) ?></p>
                        </div>
                        <div class="rounded-xl bg-white/15 p-4 backdrop-blur">
                            <p class="text-xs uppercase tracking-wide text-teal-50">Alarm Aktif</p>
                            <p class="mt-1 text-3xl font-bold"><?= count(array_filter($patients, fn ($p) => $p['status_alarm'] === 'Aktif')) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($dbError): ?>
            <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
                <div class="flex gap-3">
                    <i class="fa-solid fa-circle-exclamation mt-1"></i>
                    <div>
                        <p class="font-semibold">Koneksi database belum berhasil.</p>
                        <p class="mt-1 text-sm">Periksa nama database, username, password, host, dan port MySQL hosting pada konfigurasi di bagian atas file. Detail: <?= e($dbError) ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="mb-5 rounded-2xl border <?= $flash['type'] === 'success' ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?> p-4">
                <div class="flex items-center gap-3">
                    <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                    <p class="font-medium"><?= e($flash['message']) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($fonnteToken === '' && $pdo && !$dbError): ?>
            <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-800">
                <div class="flex gap-3">
                    <i class="fa-solid fa-triangle-exclamation mt-1"></i>
                    <div>
                        <p class="font-semibold">Token Fonnte belum diatur.</p>
                        <p class="mt-1 text-sm">
                            Fitur kirim pengingat WhatsApp tidak akan berfungsi sampai token diisi.
                            Buat file <code class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-mono">.env</code> di folder ini
                            (copy dari <code class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-mono">.env.example</code>)
                            dan isi <code class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-mono">FONNTE_TOKEN</code> dengan token dari
                            <a href="https://md.fonnte.com/" target="_blank" class="font-bold underline hover:text-amber-900">md.fonnte.com</a>.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid gap-6 lg:grid-cols-[390px_minmax(0,1fr)]">
            <aside class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-slate-100 sm:p-6">
                <div class="mb-5 flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-100 text-teal-700">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Input Pasien Baru</h2>
                        <p class="text-sm text-slate-500">Tanggal kembali dihitung otomatis.</p>
                    </div>
                </div>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="create">
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Nama Pasien</span>
                        <div class="relative">
                            <i class="fa-regular fa-user absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input name="nama_pasien" required class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 outline-none transition focus:border-teal-400 focus:bg-white focus:ring-4 focus:ring-teal-100" placeholder="Contoh: Siti Aminah">
                        </div>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Nomor WhatsApp</span>
                        <div class="relative">
                            <i class="fa-brands fa-whatsapp absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input name="no_whatsapp" required class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 outline-none transition focus:border-teal-400 focus:bg-white focus:ring-4 focus:ring-teal-100" placeholder="+62812xxxx atau 0812xxxx">
                        </div>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Tipe KB</span>
                        <select name="tipe_kb" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none transition focus:border-teal-400 focus:bg-white focus:ring-4 focus:ring-teal-100">
                            <option value="1 Bulan">1 Bulan (30 Hari)</option>
                            <option value="3 Bulan">3 Bulan (90 Hari)</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Tanggal Suntik Terakhir</span>
                        <input type="date" name="tanggal_suntik_terakhir" value="<?= date('Y-m-d') ?>" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none transition focus:border-teal-400 focus:bg-white focus:ring-4 focus:ring-teal-100">
                    </label>

                    <button class="group inline-flex w-full items-center justify-center gap-2 rounded-xl bg-teal-600 px-5 py-3 font-bold text-white shadow-lg shadow-teal-600/20 transition hover:-translate-y-0.5 hover:bg-teal-700 hover:shadow-xl hover:shadow-teal-600/25 active:translate-y-0">
                        <i class="fa-solid fa-calendar-plus transition group-hover:scale-110"></i>
                        Simpan Jadwal
                    </button>
                </form>
            </aside>

            <section class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-slate-100 sm:p-6">
                <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Daftar Jadwal Pasien</h2>
                        <p class="text-sm text-slate-500">Pantau pasien berdasarkan tanggal kembali terdekat.</p>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-600">
                        <i class="fa-regular fa-clock text-teal-600"></i>
                        <?= formatTanggal(date('Y-m-d')) ?>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-100">
                    <div class="overflow-x-auto">
                        <table class="min-w-[950px] w-full text-left text-sm">
                            <thead class="bg-slate-100 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-4">Nama Pasien</th>
                                    <th class="px-4 py-4">No. WhatsApp</th>
                                    <th class="px-4 py-4">Tipe KB</th>
                                    <th class="px-4 py-4">Tanggal Suntik</th>
                                    <th class="px-4 py-4">Tanggal Kembali</th>
                                    <th class="px-4 py-4">Selisih Hari</th>
                                    <th class="px-4 py-4">Status Alarm</th>
                                    <th class="px-4 py-4 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php if (!$patients): ?>
                                    <tr>
                                        <td colspan="8" class="px-4 py-12 text-center">
                                            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-teal-50 text-2xl text-teal-600">
                                                <i class="fa-regular fa-calendar"></i>
                                            </div>
                                            <p class="mt-3 font-semibold text-slate-700">Belum ada jadwal pasien.</p>
                                            <p class="mt-1 text-slate-500">Tambahkan pasien baru dari formulir di samping.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($patients as $patient): ?>
                                    <?php [$diffText, $diffClass] = selisihHariText($patient['tanggal_kembali']); ?>
                                    <tr class="transition hover:bg-teal-50/40">
                                        <td class="px-4 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-100 font-bold text-teal-700">
                                                    <?= e(strtoupper(substr($patient['nama_pasien'], 0, 1))) ?>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-900"><?= e($patient['nama_pasien']) ?></p>
                                                    <p class="text-xs text-slate-500">ID #<?= (int) $patient['id'] ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 font-medium text-slate-700"><?= e($patient['no_whatsapp']) ?></td>
                                        <td class="px-4 py-4">
                                            <span class="rounded-full bg-cyan-50 px-3 py-1 text-xs font-bold text-cyan-700 ring-1 ring-cyan-100"><?= e($patient['tipe_kb']) ?></span>
                                        </td>
                                        <td class="px-4 py-4 text-slate-600"><?= formatTanggal($patient['tanggal_suntik_terakhir']) ?></td>
                                        <td class="px-4 py-4 font-bold text-slate-900"><?= formatTanggal($patient['tanggal_kembali']) ?></td>
                                        <td class="px-4 py-4">
                                            <span class="inline-flex rounded-full border px-3 py-1 text-xs font-bold <?= $diffClass ?>"><?= e($diffText) ?></span>
                                        </td>
                                        <td class="px-4 py-4">
                                            <?php if ($patient['status_alarm'] === 'Terkirim'): ?>
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">
                                                    <i class="fa-solid fa-check"></i> Terkirim
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-100">
                                                    <i class="fa-regular fa-bell"></i> Menunggu
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="flex justify-end gap-2">
                                                <form method="post">
                                                    <input type="hidden" name="action" value="remind">
                                                    <input type="hidden" name="id" value="<?= (int) $patient['id'] ?>">
                                                    <button class="inline-flex h-10 items-center gap-2 rounded-xl bg-teal-600 px-3 text-xs font-bold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-teal-700">
                                                        <i class="fa-brands fa-whatsapp"></i>
                                                        Kirim
                                                    </button>
                                                </form>
                                                <form method="post" onsubmit="return confirm('Hapus data pasien ini?')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $patient['id'] ?>">
                                                    <button class="inline-flex h-10 items-center gap-2 rounded-xl bg-rose-50 px-3 text-xs font-bold text-rose-700 ring-1 ring-rose-100 transition hover:-translate-y-0.5 hover:bg-rose-100">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                        Hapus
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <?php if ($reminder): ?>
        <div id="reminderModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-slate-100">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-100 text-xl text-teal-700">
                            <i class="fa-brands fa-whatsapp"></i>
                        </div>
                        <div>
                        <h3 class="text-lg font-bold text-slate-900">Pengingat WA Terkirim</h3>
                        <p class="text-sm text-slate-500"><?= e($reminder['nama']) ?> · <?= e($reminder['whatsapp']) ?> · Target <?= e($reminder['target']) ?></p>
                        </div>
                    </div>
                    <button onclick="document.getElementById('reminderModal').remove()" class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-500 transition hover:bg-slate-200 hover:text-slate-800">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="rounded-2xl border border-teal-100 bg-teal-50 p-4 text-sm leading-6 text-slate-700">
                    <?= e($reminder['message']) ?>
                </div>
                <button onclick="document.getElementById('reminderModal').remove()" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-teal-600 px-5 py-3 font-bold text-white transition hover:-translate-y-0.5 hover:bg-teal-700">
                    <i class="fa-solid fa-check"></i>
                    Selesai
                </button>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>
