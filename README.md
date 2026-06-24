# Alarm Pengingat Suntik KB Pintar

Aplikasi PHP Native satu file untuk mengelola jadwal suntik KB dan mengirim pengingat WhatsApp via Fonnte.

## Setup Lingkungan

1. Copy file `.env.example` menjadi `.env`
2. Isi token Fonnte dan password database di `.env`
3. File `.env` sudah di `.gitignore` → aman dari GitHub

Contoh isi `.env`:
```
FONNTE_TOKEN=token_anda_disini
DB_PASSWORD=password_database_hosting
```

## Menjalankan Lokal

```bash
php -S localhost:8000
```
