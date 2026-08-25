<?php

declare(strict_types=1);

return [
    'db' => [
        // MySQLホスト名
        'host' => '',

        // データベース名
        'dbname' => '',

        // ユーザー名
        'user' => '',

        // パスワード
        'pass' => '',

        'charset' => 'utf8mb4',
    ],

    // 上流Overpass API
    // bbox が日本側の矩形に完全に収まる場合だけ local_japan を使い、
    // それ以外、または bbox を抽出できない raw query は official_global を使う。
    'default_overpass_route' => 'official_global',

    'overpass_routes' => [
        'local_japan' => [
            'url' => 'https://overpass-api.de/api/interpreter',

            // 判定は「クエリの bbox が下記のどれか1つに完全に収まる場合」。
            // 日本全国を一度に囲む巨大 bbox は official_global へ流れる。
            'bboxes' => [
                // 北海道
                [41.0, 139.0, 45.9, 146.3],

                // 本州・四国・九州の多く
                [32.0, 131.0, 41.8, 142.3],

                // 九州西部・南部
                [30.8, 128.8, 34.9, 132.4],

                // 南西諸島
                [24.0, 122.5, 31.6, 131.5],

                // 小笠原諸島
                [24.0, 139.0, 28.0, 143.5],

                // 沖ノ鳥島
                [20.0, 135.0, 21.5, 137.0],

                // 南鳥島
                [23.5, 153.0, 25.0, 154.5],
            ],
        ],

        'official_global' => [
            'url' => 'https://overpass-api.de/api/interpreter',
        ],
    ],

    // 識別しやすい User-Agent を推奨
    'user_agent' => 'overpass-proxy/1.0 (contact: xxxx@example.com )',

    // CORS を使う場合だけ設定。同一オリジンのみなら null でよい。
    'allow_origin' => '*',

    // 同一送信元IP（REMOTE_ADDR）から同時実行できるプロキシ要求数。
    // 0以下なら無効。超過時は Retry-After 付きの 429 を返す。
    'max_concurrent_requests_per_client' => 2,
    'concurrency_retry_after_sec' => 5,

    // 公開ディレクトリ外に置く。空文字ならシステム一時ディレクトリを使う。
    'concurrency_lock_dir' => __DIR__ . '/runtime/client-locks',

    // 入力サイズ制限（バイト）。
    'max_request_body_bytes' => 65536,
    'max_query_bytes' => 65536,

    // bbox の上限。0以下にした項目は無効。
    // 面積は緯度幅 x 経度幅の平方度であり、地表面積の割合ではない。
    'max_bbox_lat_span_degrees' => 10.0,
    'max_bbox_lon_span_degrees' => 10.0,
    'max_bbox_area_degrees2' => 25.0,

    // デフォルトTTL（秒）
    'default_ttl' => 604800, // 7日

    // 最大TTL（秒）
    'max_ttl' => 604800, // 7日

    // queryTemplate + bbox 形式で、bbox をどれだけ広げるか。
    'pad_ratio' => 0.1,

    // queryTemplate + bbox 形式での bbox 丸め単位。
    'round_step' => 0.002, //

    // bboxを抽出できるraw dataクエリは、境界を共通グリッドへ外向きに揃えてから上流へ送る。
    // 互換性よりキャッシュ共有を優先する設定。falseなら従来の完全passthroughへ戻る。
    'raw_bbox_grid_enabled' => true,
    'raw_bbox_grid_policy_version' => 'raw_grid_v1',
    'raw_bbox_grid_steps' => [
        ['max_span' => 0.02, 'step' => 0.002],
        ['max_span' => 0.05, 'step' => 0.005],
        ['max_span' => 0.10, 'step' => 0.01],
        ['max_span' => 0.25, 'step' => 0.02],
        ['max_span' => 0.50, 'step' => 0.05],
        ['max_span' => 1.00, 'step' => 0.10],
        ['max_span' => 2.50, 'step' => 0.25],
        ['max_span' => 5.00, 'step' => 0.50],
        ['max_span' => 10.0, 'step' => 1.00],
    ],

    // グリッド化後は完全一致キャッシュを優先し、さらに大きなbboxの本文は流用しない。
    'allow_containing_cache_match' => false,

    // raw_bbox_grid_enabled=false の場合に、data=... を完全passthroughするか。
    // bboxを抽出できないrawクエリは、この値にかかわらず完全passthroughする。
    'raw_data_passthrough_upstream' => true,

    // rawグリッドと完全passthroughを両方無効にした場合の互換設定。
    'raw_data_preserve_bbox' => true,

    // 上流Overpassへの投げ方。
    // GETを優先し、長すぎる場合のみPOSTへフォールバックする。
    'upstream_request_method' => 'GET',
    'upstream_get_max_query_bytes' => 16000,

    // さくらPHP/cURL → Cloudflare IPv4 経路で 0 bytes のまま詰まる事例があるため、IPv6を優先。
    'curl_ipresolve' => 'v6',
    'curl_http_version' => '1.1',

    // 上流Overpassへのタイムアウト秒
    'curl_timeout_sec' => 180,

    // 接続確立までのタイムアウト秒
    'curl_connect_timeout_sec' => 10,

    // 上流から一定時間ほぼ何も返らない場合の打ち切り秒数。
    // 0にすると無効。
    'curl_no_body_timeout_sec' => 180,

    // 何回に1回、期限切れキャッシュ削除を試すか。200なら約0.5%。
    'cleanup_probability_denominator' => 200,

    // DBへ保存する本文サイズ上限（バイト）。これを超える応答は返すがキャッシュしない。
    'max_cache_body_bytes' => 8 * 1024 * 1024,
    'php_memory_limit' => '512M',
    'php_max_execution_time_sec' => 240,

    // 上流レスポンスをPHPメモリに保持する上限（バイト）。
    // これを超える応答は一時ファイルへ退避し、キャッシュせずにそのまま返す。
    // 共有サーバーでは大きくしすぎない。
    'max_response_body_memory_bytes' => 8 * 1024 * 1024,

    // DB保存時にgzip圧縮するか
    'compress_cache' => true,

    'stream_raw_data_passthrough' => true,
    'stream_raw_data_passthrough_mode' => 'large_only',

    // 大きなbboxを直接返却する面積しきい値（緯度幅 x 経度幅の平方度）。
    'direct_stream_bbox_area_threshold' => 0.08,

    // 管理者用の認証情報
    'admin' => [
        'username' => 'admin',
        'password' => 'dynac7',
    ],
];
