<?php
/**
 * Idempotent PlayTube bootstrap.
 *
 * The bundled installer requires an Envato purchase-code check against an
 * external server, so a self-hosted deployment instead imports playtube.sql
 * and seeds the site settings and first admin account here.
 */

$host = getenv('DB_HOST') ?: 'localhost';
$port = (int) (getenv('DB_PORT') ?: 3306);
$name = getenv('DB_NAME') ?: 'playtube';
$user = getenv('DB_USER') ?: 'playtube';
$pass = getenv('DB_PASS') ?: '';

$db = @new mysqli($host, $user, $pass, $name, $port);
if ($db->connect_errno) {
    fwrite(STDERR, '[seed] cannot connect to database: ' . $db->connect_error . PHP_EOL);
    exit(1);
}
$db->set_charset('utf8mb4');

function set_config(mysqli $db, string $key, string $value): void
{
    $del = $db->prepare('DELETE FROM `config` WHERE `name` = ?');
    $del->bind_param('s', $key);
    $del->execute();
    $del->close();

    $ins = $db->prepare('INSERT INTO `config` (`name`, `value`) VALUES (?, ?)');
    $ins->bind_param('ss', $key, $value);
    $ins->execute();
    $ins->close();
}

$siteEmail = getenv('SITE_EMAIL') ?: 'admin@example.com';

$settings = [
    'is_ok'        => '1',
    'name'         => getenv('SITE_NAME') ?: 'PlayTube',
    'title'        => getenv('SITE_TITLE') ?: 'PlayTube - Video Sharing Platform',
    'email'        => $siteEmail,
    'validation'   => 'off',
    'apps_api_id'  => md5('app-id' . microtime(true)),
    'apps_api_key' => md5('app-key' . microtime(true)),
];

foreach ($settings as $key => $value) {
    set_config($db, $key, $value);
}

$adminUser = getenv('ADMIN_USERNAME') ?: 'admin';
$adminPass = getenv('ADMIN_PASSWORD') ?: 'admin123456';

$check = $db->prepare('SELECT `id` FROM `users` WHERE `username` = ? LIMIT 1');
$check->bind_param('s', $adminUser);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    $check->close();
    $hash = sha1($adminPass);
    $ins = $db->prepare(
        "INSERT INTO `users` (`username`, `password`, `email`, `admin`, `active`, `registered`)
         VALUES (?, ?, ?, '1', '1', '00/0000')"
    );
    $ins->bind_param('sss', $adminUser, $hash, $siteEmail);
    if (!$ins->execute()) {
        fwrite(STDERR, '[seed] could not create admin user: ' . $ins->error . PHP_EOL);
        exit(1);
    }
    $ins->close();
    echo '[seed] created admin account ' . $adminUser . PHP_EOL;
} else {
    $check->close();
    echo '[seed] admin account already present' . PHP_EOL;
}

echo '[seed] site settings applied' . PHP_EOL;
