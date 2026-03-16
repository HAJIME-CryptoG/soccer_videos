<?php
/**
 * batch.php
 * YouTube Data API v3 を使ってチャンネルの動画メタデータを取得し
 * data/videos.json を生成・更新するバッチスクリプト。
 *
 * 実行方法（CLIのみ）:
 *   php batch.php          # 差分更新（既存 videos.json に新着分を追記）
 *   php batch.php --full   # 全件取得（videos.json を全件で上書き再生成）
 */

// Webからのアクセスを拒否（CLI実行時のみ許可）
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

// -------------------------
// 定数・設定
// -------------------------
define('API_BASE', 'https://www.googleapis.com/youtube/v3');
define('MAX_RESULTS', 50);

// -------------------------
// 引数の解析
// -------------------------
$fullMode = in_array('--full', $argv ?? [], true);

echo '[batch.php] 開始: ' . date('Y-m-d H:i:s') . PHP_EOL;
echo '[batch.php] モード: ' . ($fullMode ? '全件取得' : '差分更新') . PHP_EOL;

// -------------------------
// ステップ①: アップロード済みプレイリストID を取得
// -------------------------
$uploadPlaylistId = getUploadPlaylistId(YOUTUBE_API_KEY, YOUTUBE_CHANNEL_ID);
if (!$uploadPlaylistId) {
    echo '[ERROR] アップロード済みプレイリストIDの取得に失敗しました。' . PHP_EOL;
    exit(1);
}
echo '[batch.php] プレイリストID: ' . $uploadPlaylistId . PHP_EOL;

// -------------------------
// 既存データの読み込み
// -------------------------
$existingVideos = [];
$existingIds    = [];

if (!$fullMode && file_exists(DATA_FILE)) {
    $json = file_get_contents(DATA_FILE);
    if ($json !== false) {
        $existingVideos = json_decode($json, true) ?: [];
        foreach ($existingVideos as $v) {
            $existingIds[$v['id']] = true;
        }
        echo '[batch.php] 既存動画数: ' . count($existingVideos) . PHP_EOL;
    }
}

// -------------------------
// ステップ②: プレイリストから動画を取得
// -------------------------
$newVideos  = [];
$pageToken  = null;
$pageCount  = 0;

do {
    $pageCount++;
    $url = buildPlaylistItemsUrl(YOUTUBE_API_KEY, $uploadPlaylistId, $pageToken);
    echo '[batch.php] API呼び出し #' . $pageCount . PHP_EOL;

    $response = fetchUrl($url);
    if ($response === false) {
        echo '[ERROR] API呼び出しに失敗しました（ページ ' . $pageCount . '）。' . PHP_EOL;
        exit(1);
    }

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        echo '[ERROR] YouTube API エラー: ' . json_encode($data['error'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }

    $items = $data['items'] ?? [];
    foreach ($items as $item) {
        $snippet   = $item['snippet'] ?? [];
        $videoId   = $snippet['resourceId']['videoId'] ?? null;
        $title     = $snippet['title'] ?? '';
        $published = $snippet['publishedAt'] ?? '';

        // 削除済み動画などIDがないものはスキップ
        if (!$videoId || $title === 'Deleted video' || $title === 'Private video') {
            continue;
        }

        // 差分更新モードでは既存IDはスキップ
        if (!$fullMode && isset($existingIds[$videoId])) {
            // 既存IDに達したら以降の取得を打ち切る（新着は先頭にあるため）
            echo '[batch.php] 既存動画IDに到達。差分取得を終了します。' . PHP_EOL;
            $pageToken = null; // ループ終了
            break 2;
        }

        $publishedDate = substr($published, 0, 10); // YYYY-MM-DD

        $newVideos[] = [
            'id'           => $videoId,
            'title'        => $title,
            'published_at' => $publishedDate,
            'thumbnail'    => 'https://i.ytimg.com/vi/' . $videoId . '/mqdefault.jpg',
            'url'          => 'https://www.youtube.com/watch?v=' . $videoId,
        ];
    }

    $pageToken = $data['nextPageToken'] ?? null;

} while ($pageToken !== null);

echo '[batch.php] 新規取得動画数: ' . count($newVideos) . PHP_EOL;

// -------------------------
// データのマージと保存
// -------------------------
if ($fullMode) {
    $allVideos = $newVideos;
} else {
    // 新着分を先頭に追加
    $allVideos = array_merge($newVideos, $existingVideos);
}

// data/ ディレクトリが存在しない場合は作成
$dataDir = dirname(DATA_FILE);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$jsonOutput = json_encode($allVideos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (file_put_contents(DATA_FILE, $jsonOutput) === false) {
    echo '[ERROR] videos.json への書き込みに失敗しました。' . PHP_EOL;
    exit(1);
}

echo '[batch.php] videos.json を更新しました。合計動画数: ' . count($allVideos) . PHP_EOL;
echo '[batch.php] 完了: ' . date('Y-m-d H:i:s') . PHP_EOL;

// =========================================================
// 関数定義
// =========================================================

/**
 * channels.list API でアップロード済みプレイリストIDを取得する。
 */
function getUploadPlaylistId(string $apiKey, string $channelId): ?string
{
    $url = API_BASE . '/channels'
        . '?part=contentDetails'
        . '&id=' . urlencode($channelId)
        . '&key=' . urlencode($apiKey);

    $response = fetchUrl($url);
    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
}

/**
 * playlistItems.list API の URL を組み立てる。
 */
function buildPlaylistItemsUrl(string $apiKey, string $playlistId, ?string $pageToken): string
{
    $url = API_BASE . '/playlistItems'
        . '?part=snippet'
        . '&playlistId=' . urlencode($playlistId)
        . '&maxResults=' . MAX_RESULTS
        . '&key=' . urlencode($apiKey);

    if ($pageToken !== null) {
        $url .= '&pageToken=' . urlencode($pageToken);
    }

    return $url;
}

/**
 * cURL で URL を取得する。失敗時は false を返す。
 */
function fetchUrl(string $url): string|false
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'YouTubeSearchApp/1.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error) {
        echo '[ERROR] cURL エラー: ' . $error . PHP_EOL;
        return false;
    }
    if ($httpCode !== 200) {
        echo '[ERROR] HTTP ' . $httpCode . ': ' . $response . PHP_EOL;
        return false;
    }

    return $response;
}
