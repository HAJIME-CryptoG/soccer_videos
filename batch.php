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
$fullMode   = in_array('--full', $argv ?? [], true);
$importFile = null;
foreach ($argv ?? [] as $i => $arg) {
    if ($arg === '--import' && isset($argv[$i + 1])) {
        $importFile = $argv[$i + 1];
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
// ステップ②: 動画を取得（playlistItems → search.list フォールバック）
// -------------------------
$newVideos  = [];
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
        $errCode = $data['error']['code'] ?? 0;
        // playlistItems が 404 の場合は search.list にフォールバック
        if (!$useSearch && $errCode === 404) {
            echo '[batch.php] playlistItems 404エラー。search.list APIにフォールバックします。' . PHP_EOL;
            $useSearch = true;
            $pageToken = null;
            $pageCount--;
            continue;
        }
        echo '[ERROR] YouTube API エラー: ' . json_encode($data['error'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }

    $items = $data['items'] ?? [];
    foreach ($items as $item) {
        if ($useSearch) {
            $videoId   = $item['id']['videoId'] ?? null;
            $snippet   = $item['snippet'] ?? [];
            $title     = $snippet['title'] ?? '';
            $published = $snippet['publishedAt'] ?? '';
        } else {
            $snippet   = $item['snippet'] ?? [];
            $videoId   = $snippet['resourceId']['videoId'] ?? null;
            $title     = $snippet['title'] ?? '';
            $published = $snippet['publishedAt'] ?? '';
        }

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
 * search.list API の URL を組み立てる（playlistItems が使えない場合のフォールバック）。
 */
function buildSearchUrl(string $apiKey, string $channelId, ?string $pageToken): string
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
 * --import モード: ファイルからビデオIDを読み込み、videos.list で情報取得して保存。
 *
 * 対応フォーマット:
 *   - テキスト（1行1ビデオID）
 *   - YouTube Studio エクスポートCSV（"動画のURL"列または"Video ID"列を自動検出）
 */
function runImportMode(string $filePath): void
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
    $existingVideos = [];
    $existingIds    = [];
    if (file_exists(DATA_FILE)) {
        $json = file_get_contents(DATA_FILE);
        if ($json !== false) {
            $existingVideos = json_decode($json, true) ?: [];
            foreach ($existingVideos as $v) {
                $existingIds[$v['id']] = true;
            }
            echo '[batch.php] 既存動画数: ' . count($existingVideos) . PHP_EOL;
        }
    }

    // 新規IDのみ取得
    $newIds = array_values(array_filter($videoIds, fn($id) => !isset($existingIds[$id])));
    echo '[batch.php] 新規ビデオID数: ' . count($newIds) . PHP_EOL;

    if (empty($newIds)) {
        echo '[batch.php] 追加する新規動画はありません。' . PHP_EOL;
        saveVideos($existingVideos);
        return;
    }

    // videos.list API で50件ずつ取得
    $newVideos = [];
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
            echo '[ERROR] YouTube API エラー: ' . json_encode($data['error'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(1);
        }

        foreach ($data['items'] ?? [] as $item) {
            $videoId  = $item['id'] ?? null;
            $snippet  = $item['snippet'] ?? [];
            $title    = $snippet['title'] ?? '';
            $published = $snippet['publishedAt'] ?? '';

            if (!$videoId || $title === 'Deleted video' || $title === 'Private video') {
                continue;
            }

            $newVideos[] = [
                'id'           => $videoId,
                'title'        => $title,
                'published_at' => substr($published, 0, 10),
                'thumbnail'    => 'https://i.ytimg.com/vi/' . $videoId . '/mqdefault.jpg',
                'url'          => 'https://www.youtube.com/watch?v=' . $videoId,
            ];
        }
    }

    echo '[batch.php] 新規取得動画数: ' . count($newVideos) . PHP_EOL;

    // 日付降順でソート（新しい順）
    usort($newVideos, fn($a, $b) => strcmp($b['published_at'], $a['published_at']));

    $allVideos = array_merge($newVideos, $existingVideos);
    saveVideos($allVideos);
}

/**
 * テキストまたはCSVからビデオIDを抽出する。
 */
function parseVideoIds(string $content): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($content));
    if (empty($lines)) {
        return [];
    }

    // CSVかどうか判定（1行目にカンマが含まれる場合）
    $firstLine = $lines[0];
    if (str_contains($firstLine, ',')) {
        return parseVideoIdsFromCsv($lines);
    }

    // テキスト形式: 各行がビデオIDまたはYouTube URL
    $ids = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
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
function parseVideoIdsFromCsv(array $lines): array
{
    $header = str_getcsv($lines[0]);
    // YouTube Studio CSVの列名候補
    $candidates = ['コンテンツ', '動画のURL', 'Video URL', 'ビデオID', 'Video ID', 'URL'];
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
        // youtube.com を含む列を探す
        if (isset($lines[1])) {
            $row = str_getcsv($lines[1]);
            foreach ($row as $i => $val) {
                if (str_contains($val, 'youtube.com') || preg_match('/^[A-Za-z0-9_-]{11}$/', $val)) {
                    $colIndex = $i;
                    echo '[batch.php] 列 ' . $colIndex . ' を使用: ' . ($header[$colIndex] ?? '') . PHP_EOL;
                    break;
                }
            }
        }
    }

    if ($colIndex === -1) {
        echo '[ERROR] CSVからビデオID/URLの列を特定できませんでした。' . PHP_EOL;
        exit(1);
    }

    $ids = [];
    foreach (array_slice($lines, 1) as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        $val = trim($row[$colIndex] ?? '');
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
function extractVideoId(string $input): ?string
{
    // 既にビデオID形式（11文字の英数字とハイフン・アンダーバー）
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
        return $input;
    }
    // YouTube URL から抽出
    if (preg_match('/(?:youtube\.com\/watch\?.*v=|youtu\.be\/)([A-Za-z0-9_-]{11})/', $input, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * videos.json に保存する。
 */
function saveVideos(array $videos): void
{
    $dataDir = dirname(DATA_FILE);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    $json = json_encode($videos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
        // エラーレスポンスのJSONを呼び出し元で解析できるよう返す
        return $response;
    }

    return $response;
}
