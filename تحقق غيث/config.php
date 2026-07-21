<?php
declare(strict_types=1);

// أدخل بيانات قاعدة البيانات كما تظهر كاملة في cPanel.
const DB_HOST = 'localhost';
const DB_NAME = 'CPANEL_USERNAME_DATABASE';
const DB_USER = 'CPANEL_USERNAME_DBUSER';
const DB_PASS = 'PUT_DATABASE_PASSWORD_HERE';

// ارفع ملف حساب الخدمة خارج public_html في هذا المسار.
const GOOGLE_SERVICE_ACCOUNT_FILE = '/home/printlysa/google-service-account.json';
const GOOGLE_SPREADSHEET_ID = '1wKxd_TB4xDhBGhpB5llFa-R90SfcIaRhlu-ApUl4wnE';

// إعدادات بوت Telegram — ضع القيم الحقيقية على السيرفر فقط.
const TELEGRAM_BOT_TOKEN = 'PUT_TELEGRAM_BOT_TOKEN_HERE';
const TELEGRAM_WEBHOOK_SECRET = 'PUT_A_LONG_RANDOM_SECRET_HERE';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
