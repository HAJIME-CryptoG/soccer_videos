<?php
/**
 * batch.php
 * YouTube Data API v3 を使ってチャンネルの動画メタデータを取得し
 * data/videos.json を生成・更新するバッチスクリプト。
 *
 * 実行方法（CLIのみ）:
 *   php batch.php          # 差分更新（既存 videos.json に新着分を追記）
 *   php batch.php --full   # 全件取得（videos.json を全件で上書き再生成）
 *   php batch.php --import ファイル名  # CSVまたはテキストからインポート
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
$args       = isset($argv) ? $argv : array();
$fullMode   = in_array('--full', $args, true);
$importFile = null;
foreach ($args as $i => $arg) {
    if ($arg === '--import' && isset($args[$i + 1])) {
        $importFile = $args[$i + 1];
        break;
    }
}

echo '[batch.php] 開始: ' . date('Y-m-d H:i:s') . PHP_EOL;

// --import モード
if ($importFile !== null) {
    echo '[batch.php] モード: インポート（' . $importFile . '）' . PHP_EOL;
    runImportMode($importFile);
    exit(0);
}

echo '[batch.php] モード: ' . ($fullMode ? '全件取得' : '差分更新') . PHP_EOL;

// -------------------------
// ステップ①: アップロード済みプレイリストID を取得
// -------------------------
$uploadPlaylistId = getUploadPlaylistId(YOUTUBE_API_KEY, YOUTUBE_CHANNEL_ID);
if ($uploadPlaylistId) {
    echo '[batch.php] プレイリストID: ' . $uploadPlaylistId . PHP_EOL;
} else {
    echo '[batch.php] プレイリストIDの取得に失敗。search.list APIを使用します。' . PHP_EOL;
}

// -------------------------
// 既存データの読み込み
// -------------------------
$existingVideos = array();
$existingIds    = array();

if (!$fullMode && file_exists(DATA_FILE)) {
    $json = file_get_contents(DATA_FILE);
    if ($json !== false) {
        $existingVideos = json_decode($json, true);
        if (!$existingVideos) $existingVideos = array();
        foreach ($existingVideos as $v) {
            $existingIds[$v['id']] = true;
        }
        echo '[batch.php] 既存動画数: ' . count($existingVideos) . PHP_EOL;
    }
}

// -------------------------
// ステップ②: 動画を取得（playlistItems → search.list フォールバック）
// -------------------------
$newVideos  = array();
$pageToken  = null;
$pageCount  = 0;
$useSearch  = false;

do {
    $pageCount++;

    if (!$useSearch && $uploadPlaylistId) {
        $url = buildPlaylistItemsUrl(YOUTUBE_API_KEY, $uploadPlaylistId, $pageToken);
    } else {
        $url = buildSearchUrl(YOUTUBE_API_KEY, YOUTUBE_CHANNEL_ID, $pageToken);
        $useSearch = true;
    }

    echo '[batch.php] API呼び出し #' . $pageCount . ($useSearch ? ' (search.list)' : ' (playlistItems)') . PHP_EOL;

    $response = fetchUrl($url);
    if ($response === false) {
        echo '[ERROR] API呼び出しに失敗しました（ページ ' . $pageCount . '）。' . PHP_EOL;
        exit(1);
    }

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        $errCode = isset($data['error']['code']) ? $data['error']['code'] : 0;
        // playlistItems が 404 の場合は search.list にフォールバック
        if (!$useSearch && $errCode === 404) {
            echo '[batch.php] playlistItems 404エラー。search.list APIにフォールバックします。' . PHP_EOL;
            $useSearch = true;
            $pageToken = null;
            $pageCount--;
            continue;
        }
        echo '[ERROR] YouTube API エラー: ' . json_encode($data['error']) . PHP_EOL;
        exit(1);
    }

    $items = isset($data['items']) ? $data['items'] : array();
    foreach ($items as $item) {
        if ($useSearch) {
            $videoId   = isset($item['id']['videoId']) ? $item['id']['videoId'] : null;
            $snippet   = isset($item['snippet']) ? $item['snippet'] : array();
            $title     = isset($snippet['title']) ? $snippet['title'] : '';
            $published = isset($snippet['publishedAt']) ? $snippet['publishedAt'] : '';
        } else {
            $snippet   = isset($item['snippet']) ? $item['snippet'] : array();
            $videoId   = isset($snippet['resourceId']['videoId']) ? $snippet['resourceId']['videoId'] : null;
            $title     = isset($snippet['title']) ? $snippet['title'] : '';
            $published = isset($snippet['publishedAt']) ? $snippet['publishedAt'] : '';
        }

        // 削除済み動画などIDがないものはスキップ
        if (!$videoId || $title === 'Deleted video' || $title === 'Private video') {
            continue;
        }

        // 差分更新モードでは既存IDはスキップ
        if (!$fullMode && isset($existingIds[$videoId])) {
            echo '[batch.php] 既存動画IDに到達。差分取得を終了します。' . PHP_EOL;
            $pageToken = null;
            break 2;
        }

        $publishedDate = substr($published, 0, 10); // YYYY-MM-DD

        $newVideos[] = array(
            'id'           => $videoId,
            'title'        => $title,
            'published_at' => $publishedDate,
            'thumbnail'    => 'https://i.ytimg.com/vi/' . $videoId . '/mqdefault.jpg',
            'url'          => 'https://www.youtube.com/watch?v=' . $videoId,
        );
    }

    $pageToken = isset($data['nextPageToken']) ? $data['nextPageToken'] : null;

} while ($pageToken !== null);

echo '[batch.php] 新規取得動画数: ' . count($newVideos) . PHP_EOL;

// -------------------------
// データのマージと保存
// -------------------------
if ($fullMode) {
    $allVideos = $newVideos;
} else {
    $allVideos = array_merge($newVideos, $existingVideos);
}

$dataDir = dirname(DATA_FILE);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$jsonOutput = json_encode($allVideos);
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
function getUploadPlaylistId($apiKey, $channelId)
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
    return isset($data['items'][0]['contentDetails']['relatedPlaylists']['uploads'])
        ? $data['items'][0]['contentDetails']['relatedPlaylists']['uploads']
        : null;
}

/**
 * search.list API の URL を組み立てる。
 */
function buildSearchUrl($apiKey, $channelId, $pageToken)
{
    $url = API_BASE . '/search'
        . '?part=snippet'
        . '&channelId=' . urlencode($channelId)
        . '&type=video'
        . '&order=date'
        . '&maxResults=' . MAX_RESULTS
        . '&key=' . urlencode($apiKey);

    if ($pageToken !== null) {
        $url .= '&pageToken=' . urlencode($pageToken);
    }

    return $url;
}

/**
 * playlistItems.list API の URL を組み立てる。
 */
function buildPlaylistItemsUrl($apiKey, $playlistId, $pageToken)
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
 * --import モード: ファイルからビデオIDを読み込み、videos.list で情報取得して保存。
 */
function runImportMode($filePath)
{
    if (!file_exists($filePath)) {
        echo '[ERROR] ファイルが見つかりません: ' . $filePath . PHP_EOL;
        exit(1);
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        echo '[ERROR] ファイルの読み込みに失敗しました。' . PHP_EOL;
        exit(1);
    }

    $videoIds = parseVideoIds($content);
    if (empty($videoIds)) {
        echo '[ERROR] ビデオIDが見つかりませんでした。' . PHP_EOL;
        exit(1);
    }
    echo '[batch.php] 読み込んだビデオID数: ' . count($videoIds) . PHP_EOL;

    // 既存データを読み込み
    $existingVideos = array();
    $existingIds    = array();
    if (file_exists(DATA_FILE)) {
        $json = file_get_contents(DATA_FILE);
        if ($json !== false) {
            $existingVideos = json_decode($json, true);
            if (!$existingVideos) $existingVideos = array();
            foreach ($existingVideos as $v) {
                $existingIds[$v['id']] = true;
            }
            echo '[batch.php] 既存動画数: ' . count($existingVideos) . PHP_EOL;
        }
    }

    // 新規IDのみ取得
    $newIds = array();
    foreach ($videoIds as $id) {
        if (!isset($existingIds[$id])) {
            $newIds[] = $id;
        }
    }
    echo '[batch.php] 新規ビデオID数: ' . count($newIds) . PHP_EOL;

    if (empty($newIds)) {
        echo '[batch.php] 追加する新規動画はありません。' . PHP_EOL;
        saveVideos($existingVideos);
        return;
    }

    // videos.list API で50件ずつ取得
    $newVideos = array();
    foreach (array_chunk($newIds, 50) as $chunk) {
        $ids = implode(',', array_map('urlencode', $chunk));
        $url = API_BASE . '/videos'
            . '?part=snippet'
            . '&id=' . $ids
            . '&key=' . urlencode(YOUTUBE_API_KEY);

        echo '[batch.php] videos.list API呼び出し（' . count($chunk) . '件）' . PHP_EOL;

        $response = fetchUrl($url);
        if ($response === false) {
            echo '[ERROR] API呼び出しに失敗しました。' . PHP_EOL;
            exit(1);
        }

        $data = json_decode($response, true);
        if (isset($data['error'])) {
            echo '[ERROR] YouTube API エラー: ' . json_encode($data['error']) . PHP_EOL;
            exit(1);
        }

        $items = isset($data['items']) ? $data['items'] : array();
        foreach ($items as $item) {
            $videoId   = isset($item['id']) ? $item['id'] : null;
            $snippet   = isset($item['snippet']) ? $item['snippet'] : array();
            $title     = isset($snippet['title']) ? $snippet['title'] : '';
            $published = isset($snippet['publishedAt']) ? $snippet['publishedAt'] : '';

            if (!$videoId || $title === 'Deleted video' || $title === 'Private video') {
                continue;
            }

            $newVideos[] = array(
                'id'           => $videoId,
                'title'        => $title,
                'published_at' => substr($published, 0, 10),
                'thumbnail'    => 'https://i.ytimg.com/vi/' . $videoId . '/mqdefault.jpg',
                'url'          => 'https://www.youtube.com/watch?v=' . $videoId,
            );
        }
    }

    echo '[batch.php] 新規取得動画数: ' . count($newVideos) . PHP_EOL;

    // 日付降順でソート（新しい順）
    usort($newVideos, 'compareByDate');

    $allVideos = array_merge($newVideos, $existingVideos);
    saveVideos($allVideos);
}

function compareByDate($a, $b)
{
    return strcmp($b['published_at'], $a['published_at']);
}

/**
 * テキストまたはCSVからビデオIDを抽出する。
 */
function parseVideoIds($content)
{
    $lines = preg_split('/\r\n|\r|\n/', trim($content));
    if (empty($lines)) {
        return array();
    }

    // CSVかどうか判定（1行目にカンマが含まれる場合）
    $firstLine = $lines[0];
    if (strpos($firstLine, ',') !== false) {
        return parseVideoIdsFromCsv($lines);
    }

    // テキスト形式: 各行がビデオIDまたはYouTube URL
    $ids = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $id = extractVideoId($line);
        if ($id) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * YouTube Studio CSVからビデオIDを抽出する。
 */
function parseVideoIdsFromCsv($lines)
{
    $header = str_getcsv($lines[0]);
    // YouTube Studio CSVの列名候補
    $candidates = array('コンテンツ', '動画のURL', 'Video URL', 'ビデオID', 'Video ID', 'URL');
    $colIndex   = -1;
    foreach ($candidates as $name) {
        $idx = array_search($name, $header);
        if ($idx !== false) {
            $colIndex = $idx;
            break;
        }
    }

    if ($colIndex === -1) {
        echo '[batch.php] CSV列を自動検出できませんでした。URL/IDが含まれる列番号(0始まり)を探します。' . PHP_EOL;
        if (isset($lines[1])) {
            $row = str_getcsv($lines[1]);
            foreach ($row as $i => $val) {
                if (strpos($val, 'youtube.com') !== false || preg_match('/^[A-Za-z0-9_-]{11}$/', $val)) {
                    $colIndex = $i;
                    echo '[batch.php] 列 ' . $colIndex . ' を使用: ' . (isset($header[$colIndex]) ? $header[$colIndex] : '') . PHP_EOL;
                    break;
                }
            }
        }
    }

    if ($colIndex === -1) {
        echo '[ERROR] CSVからビデオID/URLの列を特定できませんでした。' . PHP_EOL;
        exit(1);
    }

    $ids = array();
    foreach (array_slice($lines, 1) as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        $val = trim(isset($row[$colIndex]) ? $row[$colIndex] : '');
        $id  = extractVideoId($val);
        if ($id) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * YouTube URLまたはビデオIDからビデオIDを抽出する。
 */
function extractVideoId($input)
{
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
        return $input;
    }
    if (preg_match('/(?:youtube\.com\/watch\?.*v=|youtu\.be\/)([A-Za-z0-9_-]{11})/', $input, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * videos.json に保存する。
 */
function saveVideos($videos)
{
    $dataDir = dirname(DATA_FILE);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    $json = json_encode($videos);
    if (file_put_contents(DATA_FILE, $json) === false) {
        echo '[ERROR] videos.json への書き込みに失敗しました。' . PHP_EOL;
        exit(1);
    }
    echo '[batch.php] videos.json を更新しました。合計動画数: ' . count($videos) . PHP_EOL;
    echo '[batch.php] 完了: ' . date('Y-m-d H:i:s') . PHP_EOL;
}

/**
 * cURL で URL を取得する。失敗時は false を返す。
 */
function fetchUrl($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'YouTubeSearchApp/1.0',
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error) {
        echo '[ERROR] cURL エラー: ' . $error . PHP_EOL;
        return false;
    }
    if ($httpCode !== 200) {
        return $response;
    }

    return $response;
}
