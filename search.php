<?php
/**
 * search.php
 * 動画検索 API エンドポイント。
 * GET パラメータ: q（キーワード）, date_from（開始日）, date_to（終了日）
 */

// 同一オリジンのリクエストのみ許可（CORS）
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = $_SERVER['HTTP_HOST'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== $host) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
// クライアント側でキャッシュさせない
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

// -------------------------
// パラメータの取得とサニタイズ
// -------------------------
$keyword  = trim($_GET['q']        ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to']   ?? '');

// 日付フォーマット検証（YYYY-MM-DD）
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if ($dateFrom !== '' && !preg_match($datePattern, $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match($datePattern, $dateTo)) {
    $dateTo = '';
}

// -------------------------
// データファイルの読み込み
// -------------------------
if (!file_exists(DATA_FILE)) {
    echo json_encode(['results' => [], 'count' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

$json = file_get_contents(DATA_FILE);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['error' => 'データの読み込みに失敗しました。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$videos = json_decode($json, true);
if (!is_array($videos)) {
    http_response_code(500);
    echo json_encode(['error' => 'データの解析に失敗しました。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------
// 検索・フィルター処理
// -------------------------

// キーワードをスペース（全角・半角）で分割
$keywords = [];
if ($keyword !== '') {
    $keywords = preg_split('/[\s　]+/u', mb_strtolower($keyword, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
}

$results = [];
foreach ($videos as $video) {
    // 必須フィールドチェック
    if (!isset($video['id'], $video['title'], $video['published_at'])) {
        continue;
    }

    // ---- キーワード AND 検索 ----
    if (!empty($keywords)) {
        $titleLower = mb_strtolower($video['title'], 'UTF-8');
        foreach ($keywords as $kw) {
            if (mb_strpos($titleLower, $kw, 0, 'UTF-8') === false) {
                continue 2; // このキーワードにマッチしない → 動画をスキップ
            }
        }
    }

    // ---- 日付フィルター ----
    $pubDate = $video['published_at'];
    if ($dateFrom !== '' && $pubDate < $dateFrom) {
        continue;
    }
    if ($dateTo !== '' && $pubDate > $dateTo) {
        continue;
    }

    $results[] = [
        'id'           => $video['id'],
        'title'        => $video['title'],
        'published_at' => $video['published_at'],
        'thumbnail'    => $video['thumbnail'],
        'url'          => $video['url'],
    ];
}

// -------------------------
// レスポンス出力
// -------------------------
echo json_encode(
    ['results' => $results, 'count' => count($results)],
    JSON_UNESCAPED_UNICODE
);
