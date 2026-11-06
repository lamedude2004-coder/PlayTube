<?php
// First-boot initializer for container deployments. Idempotent: safe on every start.
//  1. Waits for MySQL to accept connections.
//  2. Loads playtube.sql once (same statement splitting as the web installer).
//  3. Marks the install as complete (config.is_ok = 1).
//  4. Creates the admin account from ADMIN_* env vars if it does not exist yet.
require __DIR__ . '/config.php';

$con = null;
for ($i = 0; $i < 90; $i++) {
    $con = @mysqli_connect($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);
    if ($con) {
        break;
    }
    sleep(2);
}
if (!$con) {
    fwrite(STDERR, "kuberns-init: cannot connect to MySQL at {$sql_db_host}\n");
    exit(1);
}
mysqli_set_charset($con, 'utf8mb4');

$has_config = mysqli_query($con, "SHOW TABLES LIKE 'config'");
if (!$has_config || mysqli_num_rows($has_config) == 0) {
    echo "kuberns-init: importing playtube.sql\n";
    $templine = '';
    foreach (file(__DIR__ . '/playtube.sql') as $line) {
        if (substr($line, 0, 2) == '--' || trim($line) == '') {
            continue;
        }
        $templine .= $line;
        if (substr(trim($line), -1, 1) == ';') {
            if (!mysqli_query($con, $templine)) {
                fwrite(STDERR, 'kuberns-init: SQL error: ' . mysqli_error($con) . "\n");
            }
            $templine = '';
        }
    }
}

mysqli_query($con, "UPDATE `config` SET `value` = '1' WHERE `name` = 'is_ok'");

$api_id = mysqli_query($con, "SELECT `value` FROM `config` WHERE `name` = 'apps_api_id'");
$row = $api_id ? mysqli_fetch_assoc($api_id) : null;
if (empty($row['value'])) {
    mysqli_query($con, "UPDATE `config` SET `value` = '" . md5(microtime()) . "' WHERE `name` = 'apps_api_id'");
    mysqli_query($con, "UPDATE `config` SET `value` = '" . md5(time()) . "' WHERE `name` = 'apps_api_key'");
}

$admin_user = getenv('ADMIN_USERNAME') ?: '';
$admin_pass = getenv('ADMIN_PASSWORD') ?: '';
$admin_mail = getenv('ADMIN_EMAIL') ?: '';
if ($admin_user !== '' && $admin_pass !== '' && $admin_mail !== '') {
    $u = mysqli_real_escape_string($con, $admin_user);
    $exists = mysqli_query($con, "SELECT `id` FROM `users` WHERE `username` = '{$u}' LIMIT 1");
    if ($exists && mysqli_num_rows($exists) == 0) {
        $p = mysqli_real_escape_string($con, sha1($admin_pass));
        $e = mysqli_real_escape_string($con, $admin_mail);
        mysqli_query($con, "INSERT INTO `users` (`username`, `password`, `email`, `admin`, `active`, `registered`) VALUES ('{$u}', '{$p}', '{$e}', '1', '1', '00/0000')");
        echo "kuberns-init: admin account created\n";
    }
}

mysqli_close($con);
echo "kuberns-init: database ready\n";
