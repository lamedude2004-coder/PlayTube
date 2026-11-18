<?php
/**
 * End-to-end check for the Vidara.so + Mux video pipeline against a RUNNING
 * PlayTube deployment.
 *
 * It logs in as an admin, uploads a real clip through the app's own upload
 * endpoint, submits the video (which triggers the Vidara/Mux pipeline), then
 * loads the watch page and asserts that Mux HLS playback is rendered.
 *
 * Usage: php scripts/live_e2e_check.php [base-url]
 * Exit code 0 = all assertions passed.
 */

$base = isset($argv[1]) ? rtrim($argv[1], '/') : (getenv('E2E_BASE') ?: 'https://k8un98x30k.dev.onappjet.com');
$user = getenv('E2E_USER') ?: 'admin';
$pass = getenv('E2E_PASS') ?: 'admin123456';
$jar  = sys_get_temp_dir() . '/e2e_cookies_' . getmypid() . '.txt';
$clip = sys_get_temp_dir() . '/e2e_clip.mp4';
$thumb = sys_get_temp_dir() . '/e2e_thumb.jpg';

$failures = 0;
function step($ok, $label, $detail = '')
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . ($detail !== '' ? ' :: ' . $detail : '') . PHP_EOL;
}

function req($method, $url, $fields = null, $jar = null, $fileField = null, $filePath = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CUSTOMREQUEST  => $method,
    ));
    if ($jar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($fields !== null) {
        if ($fileField !== null) {
            $fields[$fileField] = new CURLFile($filePath);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return array($status, (string) $body, $err);
}

// Fixtures ---------------------------------------------------------------
if (!is_file($clip)) {
    exec('ffmpeg -y -f lavfi -i testsrc=size=640x360:rate=25 -f lavfi -i sine=frequency=440 '
        . '-t 4 -pix_fmt yuv420p -c:v libx264 -preset ultrafast -c:a aac -shortest '
        . escapeshellarg($clip) . ' 2>/dev/null', $o, $rc);
    if (!is_file($clip)) {
        step(false, 'create test clip with ffmpeg');
        exit(1);
    }
}
if (!is_file($thumb)) {
    exec('convert -size 640x360 xc:#2244aa ' . escapeshellarg($thumb) . ' 2>/dev/null');
    if (!is_file($thumb)) {
        file_put_contents($thumb, base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
          . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPDs0NDT/wAALCAAKAAoBAREA/8QAFQABAQAA'
          . 'AAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAA/AKp//9k='
        ));
    }
}

// 1. Log in ---------------------------------------------------------------
list($s, $b) = req('POST', "$base/login", array('username' => $user, 'password' => $pass), $jar);
step($s === 302 || $s === 200, 'POST /login authenticates', "http=$s");

list($s, $b) = req('GET', "$base/upload-video", null, $jar);
step($s === 200, 'GET /upload-video reachable while logged in', "http=$s");
if (!preg_match('/class="main_session"[^>]*value="([^"]+)"/', $b, $m)) {
    step(false, 'extract ajax session token from upload page');
    exit(1);
}
$hash = $m[1];

// 2. Upload the clip ------------------------------------------------------
list($s, $b) = req('POST', "$base/aj/upload-video?hash=$hash", array(), $jar, 'video', $clip);
$up = json_decode($b, true);
$path = isset($up['file_path']) ? $up['file_path'] : '';
step($s === 200 && $path !== '', 'POST /aj/upload-video stores the clip', "http=$s path=$path");

// 3. Submit the video (runs the Vidara + Mux pipeline) --------------------
$title = 'Mux Vidara E2E ' . date('His');
list($s, $b) = req('POST', "$base/aj/submit-video?hash=$hash", array(
    'title'          => $title,
    'description'    => 'Automated end-to-end verification of Vidara storage and Mux streaming.',
    'tags'           => 'mux,vidara,e2e',
    'video-location' => $path,
    'thumbnail'      => new CURLFile($thumb),
    'privacy'        => '1',
    'category_id'    => '1',
), $jar);
$sub = json_decode($b, true);
$videoId = isset($sub['video_id']) ? $sub['video_id'] : '';
step($s === 200 && $videoId !== '', 'POST /aj/submit-video creates the video',
    'http=' . $s . ' body=' . substr(preg_replace('/\s+/', ' ', strip_tags($b)), 0, 200));

if ($videoId === '') {
    echo PHP_EOL . 'RESULT: FAILED' . PHP_EOL;
    exit(1);
}

// 4. Watch page must render Mux HLS playback -----------------------------
list($s, $b) = req('GET', "$base/watch/$videoId", null, $jar);
step($s === 200, 'GET /watch/' . $videoId . ' renders', "http=$s");
step(strpos($b, 'stream.mux.com') !== false, 'watch page references Mux HLS stream');
step((bool) preg_match('/hls[^"\']{0,24}\.js/i', $b), 'watch page loads an HLS player');
step(strpos($b, $title) !== false, 'watch page shows the uploaded title');

echo PHP_EOL . ($failures === 0 ? 'RESULT: PASSED' : "RESULT: FAILED ($failures assertion(s))") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
