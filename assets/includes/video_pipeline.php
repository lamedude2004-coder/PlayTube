<?php
/**
 * Vidara.so (storage) + Mux (processing / streaming) pipeline.
 *
 * Credentials are read from environment variables only so they never live in
 * the repository:
 *   VIDARA_API_KEY  - Vidara.so API key
 *   MUX_TOKEN_ID    - Mux access token id
 *   MUX_SECRET_KEY  - Mux access token secret
 *
 * Every method returns an array and never throws: a failure in the external
 * service must not break the PlayTube upload flow.
 */
class PT_VideoPipeline
{
    const VIDARA_API             = 'https://api.vidara.so/v1';
    const VIDARA_FALLBACK_UPLOAD = 'https://s1.vidara.so/api/upload';
    const MUX_API                = 'https://api.mux.com/video/v1';
    const MUX_STREAM             = 'https://stream.mux.com';
    const MUX_IMAGE              = 'https://image.mux.com';

    /* ------------------------------------------------------------ config */

    public static function env($name)
    {
        $value = getenv($name);
        return ($value === false) ? '' : trim($value);
    }

    public static function vidaraKey()
    {
        return self::env('VIDARA_API_KEY');
    }

    public static function muxTokenId()
    {
        return self::env('MUX_TOKEN_ID');
    }

    public static function muxSecret()
    {
        return self::env('MUX_SECRET_KEY');
    }

    public static function vidaraEnabled()
    {
        return self::vidaraKey() !== '';
    }

    public static function muxEnabled()
    {
        return self::muxTokenId() !== '' && self::muxSecret() !== '';
    }

    public static function enabled()
    {
        return self::vidaraEnabled() || self::muxEnabled();
    }

    /* -------------------------------------------------------------- http */

    private static function dig($data, $path)
    {
        $current = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    private static function http($method, $url, $options = array())
    {
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 120;
        $headers = isset($options['headers']) ? $options['headers'] : array();
        $handle  = null;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if (!empty($options['basic'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $options['basic'][0] . ':' . $options['basic'][1]);
        }

        if (!empty($options['file'])) {
            $handle = @fopen($options['file'], 'rb');
            if ($handle === false) {
                curl_close($ch);
                return array('code' => 0, 'body' => '', 'json' => null, 'error' => 'Cannot open file');
            }
            $headers[] = 'Content-Length: ' . filesize($options['file']);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $handle);
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($options['file']));
        } elseif (isset($options['post_fields'])) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['post_fields']);
        } elseif (isset($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if (is_resource($handle)) {
            fclose($handle);
        }
        curl_close($ch);

        if ($body === false || $body === null) {
            $body = '';
        }
        $json = ($body !== '') ? json_decode($body, true) : null;

        return array('code' => $code, 'body' => $body, 'json' => $json, 'error' => $err);
    }

    private static function log($message)
    {
        @error_log('[pt-pipeline] ' . $message);
    }

    /* ------------------------------------------------------------ Vidara */

    public static function vidaraUploadServer()
    {
        static $server = null;
        if ($server !== null) {
            return $server;
        }
        $server = self::VIDARA_FALLBACK_UPLOAD;
        if (!self::vidaraEnabled()) {
            return $server;
        }
        $res = self::http('GET', self::VIDARA_API . '/upload/server?api_key=' . urlencode(self::vidaraKey()), array('timeout' => 30));
        $found = self::dig($res['json'], 'result.upload_server');
        if (is_string($found) && preg_match('#^https?://#i', $found)) {
            $server = $found;
        }
        return $server;
    }

    /**
     * Upload a local file to Vidara.so. Returns filecode + public page link.
     */
    public static function vidaraUpload($filePath, $title = '')
    {
        if (!self::vidaraEnabled()) {
            return array('ok' => false, 'error' => 'Vidara API key is not configured');
        }
        if (!is_file($filePath)) {
            return array('ok' => false, 'error' => 'Local file not found');
        }

        $fields = array(
            // The upload server authenticates on api_key; the published docs
            // say "key", which it rejects with HTTP 401. Send both.
            'api_key' => self::vidaraKey(),
            'key'     => self::vidaraKey(),
            'file'    => new CURLFile($filePath, 'video/mp4', basename($filePath)),
        );
        if ($title !== '') {
            $fields['title'] = $title;
        }

        $res = self::http('POST', self::vidaraUploadServer(), array(
            'post_fields' => $fields,
            'timeout'     => 600,
        ));

        if ($res['code'] < 200 || $res['code'] >= 300) {
            self::log('vidara upload http ' . $res['code'] . ' ' . $res['error']);
            return array('ok' => false, 'error' => 'Vidara upload failed (HTTP ' . $res['code'] . ')');
        }

        // The response shape differs between endpoints: {"filecode": "..."}
        // at the top level, or nested under result. filecode may already be a
        // full share URL rather than a bare code.
        $raw = self::dig($res['json'], 'result.filecode');
        foreach (array('filecode', 'result.file_code', 'file_code', 'result.code', 'result.link', 'link') as $path) {
            if (!empty($raw)) {
                break;
            }
            $raw = self::dig($res['json'], $path);
        }
        if (empty($raw)) {
            self::log('vidara upload without filecode: ' . substr($res['body'], 0, 300));
            return array('ok' => false, 'error' => 'Vidara response did not include a filecode');
        }

        $filecode = (string) $raw;
        $link     = '';
        if (preg_match('#^https?://#i', $filecode)) {
            $link     = $filecode;
            $path     = parse_url($filecode, PHP_URL_PATH);
            $filecode = ($path !== null && $path !== false) ? basename($path) : '';
        }
        if ($link === '') {
            $link = 'https://vidara.to/e/' . $filecode;
        }

        return array(
            'ok'       => true,
            'filecode' => (string) $filecode,
            'url'      => $link,
            'video_id' => self::dig($res['json'], 'video_id'),
        );
    }

    /**
     * Best-effort: use the Mux thumbnail as the Vidara poster.
     */
    public static function vidaraSetThumb($filecode, $thumbUrl)
    {
        if (!self::vidaraEnabled() || $filecode === '' || $thumbUrl === '') {
            return false;
        }
        $res = self::http('GET', self::VIDARA_API . '/upload/thumb?api_key=' . urlencode(self::vidaraKey())
            . '&filecode=' . urlencode($filecode) . '&thumb_url=' . urlencode($thumbUrl), array('timeout' => 60));
        if ($res['code'] !== 200) {
            self::log('vidara thumb http ' . $res['code']);
            return false;
        }
        return true;
    }

    public static function vidaraInfo($filecode)
    {
        if (!self::vidaraEnabled() || $filecode === '') {
            return array('ok' => false, 'error' => 'Vidara is not configured');
        }
        $res = self::http('GET', self::VIDARA_API . '/video/info?api_key=' . urlencode(self::vidaraKey())
            . '&filecode=' . urlencode($filecode), array('timeout' => 30));
        if ($res['code'] !== 200 || !is_array($res['json'])) {
            return array('ok' => false, 'error' => 'Vidara info failed (HTTP ' . $res['code'] . ')');
        }
        return array('ok' => true, 'result' => self::dig($res['json'], 'result'));
    }

    /* --------------------------------------------------------------- Mux */

    private static function mux($method, $path, $payload = null)
    {
        $options = array(
            'basic'   => array(self::muxTokenId(), self::muxSecret()),
            'headers' => array('Content-Type: application/json'),
            'timeout' => 60,
        );
        if ($payload !== null) {
            $options['body'] = json_encode($payload);
        }
        return self::http($method, self::MUX_API . $path, $options);
    }

    /**
     * Create a Mux asset from a publicly reachable URL. Mux fetches and
     * processes the file asynchronously and returns a playback id right away.
     */
    public static function muxCreateAssetFromUrl($url, $passthrough = '')
    {
        if (!self::muxEnabled()) {
            return array('ok' => false, 'error' => 'Mux credentials are not configured');
        }
        if (!preg_match('#^https?://#i', (string) $url)) {
            return array('ok' => false, 'error' => 'A public URL is required for Mux ingestion');
        }

        $payload = array(
            'input'           => $url,
            'playback_policy' => array('public'),
            'mp4_support'     => 'capped-1080p',
        );
        if ($passthrough !== '') {
            $payload['passthrough'] = (string) $passthrough;
        }

        $res = self::mux('POST', '/assets', $payload);
        if ($res['code'] < 200 || $res['code'] >= 300 || !is_array($res['json'])) {
            self::log('mux asset http ' . $res['code'] . ' ' . substr($res['body'], 0, 300));
            return array('ok' => false, 'error' => 'Mux asset creation failed (HTTP ' . $res['code'] . ')');
        }

        $asset = self::dig($res['json'], 'data');
        if (!is_array($asset) || empty($asset['id'])) {
            return array('ok' => false, 'error' => 'Mux response did not include an asset id');
        }

        $playbackId = '';
        if (!empty($asset['playback_ids'][0]['id'])) {
            $playbackId = $asset['playback_ids'][0]['id'];
        }

        return self::assetResult($asset['id'], $playbackId, isset($asset['status']) ? $asset['status'] : 'preparing');
    }

    /**
     * Fallback path: upload the file straight to Mux with a direct upload.
     */
    public static function muxUploadFile($filePath, $passthrough = '')
    {
        if (!self::muxEnabled()) {
            return array('ok' => false, 'error' => 'Mux credentials are not configured');
        }
        if (!is_file($filePath)) {
            return array('ok' => false, 'error' => 'Local file not found');
        }

        $payload = array(
            'new_asset_settings' => array(
                'playback_policy' => array('public'),
                'mp4_support'     => 'capped-1080p',
            ),
            'cors_origin'        => '*',
        );
        if ($passthrough !== '') {
            $payload['new_asset_settings']['passthrough'] = (string) $passthrough;
        }

        $created = self::mux('POST', '/uploads', $payload);
        $uploadUrl = self::dig($created['json'], 'data.url');
        $uploadId  = self::dig($created['json'], 'data.id');
        if ($created['code'] < 200 || $created['code'] >= 300 || !$uploadUrl) {
            self::log('mux upload create http ' . $created['code'] . ' ' . substr($created['body'], 0, 300));
            return array('ok' => false, 'error' => 'Mux upload creation failed (HTTP ' . $created['code'] . ')');
        }

        $put = self::http('PUT', $uploadUrl, array(
            'file'    => $filePath,
            'headers' => array('Content-Type: application/octet-stream'),
            'timeout' => 900,
        ));
        if ($put['code'] < 200 || $put['code'] >= 300) {
            self::log('mux upload put http ' . $put['code'] . ' ' . $put['error']);
            return array('ok' => false, 'error' => 'Mux upload failed (HTTP ' . $put['code'] . ')');
        }

        // Mux attaches the asset once the upload finishes; poll briefly.
        $assetId = '';
        for ($i = 0; $i < 5; $i++) {
            $status = self::mux('GET', '/uploads/' . rawurlencode($uploadId));
            $assetId = self::dig($status['json'], 'data.asset_id');
            if (!empty($assetId)) {
                break;
            }
            $state = self::dig($status['json'], 'data.status');
            if ($state === 'errored' || $state === 'cancelled') {
                return array('ok' => false, 'error' => 'Mux upload ' . $state);
            }
            sleep(2);
        }
        if (empty($assetId)) {
            return array('ok' => false, 'error' => 'Mux upload did not produce an asset yet');
        }

        return self::muxAsset($assetId);
    }

    public static function muxAsset($assetId)
    {
        if (!self::muxEnabled() || $assetId === '') {
            return array('ok' => false, 'error' => 'Mux asset lookup is not available');
        }
        $res = self::mux('GET', '/assets/' . rawurlencode($assetId));
        if ($res['code'] !== 200 || !is_array($res['json'])) {
            return array('ok' => false, 'error' => 'Mux asset lookup failed (HTTP ' . $res['code'] . ')');
        }
        $asset = self::dig($res['json'], 'data');
        $playbackId = '';
        if (!empty($asset['playback_ids'][0]['id'])) {
            $playbackId = $asset['playback_ids'][0]['id'];
        }
        return self::assetResult($assetId, $playbackId, isset($asset['status']) ? $asset['status'] : 'preparing');
    }

    private static function assetResult($assetId, $playbackId, $status)
    {
        $result = array(
            'ok'          => true,
            'asset_id'    => (string) $assetId,
            'playback_id' => (string) $playbackId,
            'status'      => (string) $status,
            'hls_url'     => '',
            'mp4_url'     => '',
            'thumbnail'   => '',
        );
        if ($playbackId !== '') {
            $result['hls_url']   = self::hlsUrl($playbackId);
            $result['mp4_url']   = self::mp4Url($playbackId);
            $result['thumbnail'] = self::MUX_IMAGE . '/' . $playbackId . '/thumbnail.jpg?width=640';
        }
        return $result;
    }

    public static function hlsUrl($playbackId)
    {
        return $playbackId === '' ? '' : self::MUX_STREAM . '/' . $playbackId . '.m3u8';
    }

    public static function mp4Url($playbackId)
    {
        return $playbackId === '' ? '' : self::MUX_STREAM . '/' . $playbackId . '/capped-1080p.mp4';
    }

    /* ---------------------------------------------------- orchestration */

    /**
     * Store the file on Vidara and hand it to Mux for processing/streaming.
     *
     * @param string $filePath  absolute path of the uploaded video
     * @param string $publicUrl absolute public URL of that same file
     * @param string $title     video title used for the storage copy
     */
    public static function process($filePath, $publicUrl, $title = '')
    {
        $out = array(
            'vidara'   => null,
            'mux'      => null,
            'status'   => 'skipped',
            'error'    => '',
        );

        if (!self::enabled()) {
            return $out;
        }

        $errors = array();

        // Mux first: it returns a playback id quickly, so the video is
        // playable even if the storage copy is slow.
        if (self::muxEnabled()) {
            $mux = self::muxCreateAssetFromUrl($publicUrl, $title);
            if (!$mux['ok']) {
                // Mux could not pull the URL: push the file directly instead.
                $mux = self::muxUploadFile($filePath, $title);
            }
            $out['mux'] = $mux;
            if (!$mux['ok']) {
                $errors[] = $mux['error'];
            }
        }

        if (self::vidaraEnabled()) {
            $vidara = self::vidaraUpload($filePath, $title);
            $out['vidara'] = $vidara;
            if (!$vidara['ok']) {
                $errors[] = $vidara['error'];
            } elseif (!empty($out['mux']['ok']) && !empty($out['mux']['thumbnail'])) {
                // Reuse the Mux poster on the storage copy.
                self::vidaraSetThumb($vidara['filecode'], $out['mux']['thumbnail']);
            }
        }

        $muxOk    = !empty($out['mux']['ok']);
        $vidaraOk = !empty($out['vidara']['ok']);

        if ($muxOk && $vidaraOk) {
            $out['status'] = 'complete';
        } elseif ($muxOk || $vidaraOk) {
            $out['status'] = 'partial';
        } else {
            $out['status'] = 'failed';
        }
        $out['error'] = implode(' | ', $errors);

        return $out;
    }

    /**
     * Columns persisted on the videos table for a pipeline result.
     */
    public static function dbFields($result)
    {
        $fields = array(
            'pipeline_status' => isset($result['status']) ? substr($result['status'], 0, 24) : 'skipped',
            'pipeline_error'  => substr((string) (isset($result['error']) ? $result['error'] : ''), 0, 255),
            'mux_asset_id'    => '',
            'mux_playback_id' => '',
            'mux_status'      => '',
            'vidara_filecode' => '',
            'vidara_url'      => '',
        );

        if (!empty($result['mux']['ok'])) {
            $fields['mux_asset_id']    = substr((string) $result['mux']['asset_id'], 0, 64);
            $fields['mux_playback_id'] = substr((string) $result['mux']['playback_id'], 0, 64);
            $fields['mux_status']      = substr((string) $result['mux']['status'], 0, 24);
        }
        if (!empty($result['vidara']['ok'])) {
            $fields['vidara_filecode'] = substr((string) $result['vidara']['filecode'], 0, 64);
            $fields['vidara_url']      = substr((string) $result['vidara']['url'], 0, 255);
        }

        return $fields;
    }
}

/**
 * Run the storage/streaming pipeline for a video that was just inserted.
 * Called from the PlayTube upload handlers immediately after the insert; it
 * never throws so a provider outage cannot break the upload.
 *
 * @param int    $video_id      id of the new row in the videos table
 * @param string $relative_path uploaded file path relative to the app root
 * @param string $title         video title
 */
function pt_video_pipeline_apply($video_id, $relative_path, $title = '')
{
    global $db;

    if (empty($video_id) || !is_object($db) || !class_exists('PT_VideoPipeline')) {
        return null;
    }
    if (!PT_VideoPipeline::enabled()) {
        return null;
    }

    $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
    if ($relative_path === '') {
        return null;
    }

    $absolute = dirname(__DIR__, 2) . '/' . $relative_path;
    if (!is_file($absolute)) {
        return null;
    }

    $public_url = function_exists('PT_Link') ? PT_Link($relative_path) : '';
    if (!is_string($public_url) || $public_url === '') {
        $base = !empty($GLOBALS['pt']->site_url) ? rtrim($GLOBALS['pt']->site_url, '/') : '';
        $public_url = ($base !== '') ? $base . '/' . $relative_path : '';
    }
    if (!is_string($public_url) || $public_url === '') {
        return null;
    }

    $result = PT_VideoPipeline::process($absolute, $public_url, (string) $title);

    $fields = PT_VideoPipeline::dbFields($result);
    if (!empty($fields)) {
        $table = defined('T_VIDEOS') ? T_VIDEOS : 'videos';
        $db->where('id', $video_id)->update($table, $fields);
    }

    return $result;
}
