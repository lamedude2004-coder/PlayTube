<?php
/**
 * Real-provider check for the Vidara.so storage + Mux streaming pipeline.
 *
 * Usage (credentials come from the environment, never from this file):
 *   VIDARA_API_KEY=... MUX_TOKEN_ID=... MUX_SECRET_KEY=... php scripts/video_pipeline_check.php
 *
 * It generates a short test clip, uploads it to Vidara.so, sends it to Mux,
 * then confirms the returned HLS manifest is actually served. Exits non-zero
 * when a provider fails so the result can be used as a check.
 */

require_once __DIR__ . '/../assets/includes/video_pipeline.php';

$failures = array();
$report   = array();

function vp_check($ok, $message, &$failures)
{
    if (!$ok) {
        $failures[] = $message;
    }
    return $ok;
}

/* --------------------------------------------------------- configuration */

$report['configured'] = array(
    'vidara' => PT_VideoPipeline::vidaraEnabled(),
    'mux'    => PT_VideoPipeline::muxEnabled(),
);
vp_check(PT_VideoPipeline::vidaraEnabled(), 'VIDARA_API_KEY is not set', $failures);
vp_check(PT_VideoPipeline::muxEnabled(), 'MUX_TOKEN_ID / MUX_SECRET_KEY are not set', $failures);

/* -------------------------------------------------------------- test clip */

$clip = sys_get_temp_dir() . '/pt-pipeline-check-' . getmypid() . '.mp4';
$cmd  = 'ffmpeg -hide_banner -loglevel error -y -f lavfi -i testsrc=size=320x240:rate=15:duration=3'
      . ' -f lavfi -i sine=frequency=440:duration=3 -c:v libx264 -pix_fmt yuv420p -preset ultrafast'
      . ' -c:a aac -shortest ' . escapeshellarg($clip) . ' 2>&1';
exec($cmd, $out, $status);
vp_check($status === 0 && is_file($clip) && filesize($clip) > 0, 'could not generate the test clip: ' . implode(' ', $out), $failures);
$report['clip_bytes'] = is_file($clip) ? filesize($clip) : 0;

/* ----------------------------------------------------------------- Vidara */

if (PT_VideoPipeline::vidaraEnabled() && is_file($clip)) {
    $vidara = PT_VideoPipeline::vidaraUpload($clip, 'PlayTube pipeline check');
    $report['vidara'] = $vidara;
    vp_check(!empty($vidara['ok']), 'Vidara upload failed: ' . (isset($vidara['error']) ? $vidara['error'] : 'unknown error'), $failures);
}

/* -------------------------------------------------------------------- Mux */

$playbackId = '';
if (PT_VideoPipeline::muxEnabled() && is_file($clip)) {
    $mux = PT_VideoPipeline::muxUploadFile($clip, 'PlayTube pipeline check');
    $report['mux'] = $mux;
    vp_check(!empty($mux['ok']), 'Mux upload failed: ' . (isset($mux['error']) ? $mux['error'] : 'unknown error'), $failures);
    if (!empty($mux['playback_id'])) {
        $playbackId = $mux['playback_id'];
    }
}

/* ------------------------------------------------- streaming reachability */

if ($playbackId !== '') {
    $manifest = PT_VideoPipeline::hlsUrl($playbackId);
    $report['hls_url'] = $manifest;

    $code = 0;
    for ($i = 0; $i < 10; $i++) {
        $ch = curl_init($manifest);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && is_string($body) && strpos($body, '#EXTM3U') !== false) {
            $report['hls_manifest_ok'] = true;
            break;
        }
        sleep(3);
    }
    vp_check($code === 200, 'HLS manifest was not served (HTTP ' . $code . ')', $failures);
}

if (is_file($clip)) {
    @unlink($clip);
}

$report['ok']       = empty($failures);
$report['failures'] = $failures;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(empty($failures) ? 0 : 1);
