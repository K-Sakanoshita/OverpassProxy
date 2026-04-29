<?php

declare(strict_types=1);

return [
    'db' => [
        // さくらのMySQLホスト名に置き換える
        'host' => '',

        // データベース名
        'dbname' => '',

        // ユーザー名
        'user' => '',

        // パスワード
        'pass' => '',

        // 通常はこのままでよい
        'charset' => 'utf8mb4',
    ],

    // 上流Overpass API
    // bbox が日本側の矩形に完全に収まる場合だけ local_japan を使い、
    // それ以外、または bbox を抽出できない raw query は official_global を使う。
    // 既存コードとの互換用。新しい overpass_proxy.php は overpass_routes を優先する。
    'overpass_url' => 'sample',   // ここは実際のURLに置き換えること

    'default_overpass_route' => 'official_global',

    'overpass_routes' => [
        'local_japan' => [
            'url' => 'sample',   // ここは実際のURLに置き換えること

            // かなり粗い日本向け矩形。
            // 判定は「クエリの bbox が下記のどれか1つに完全に収まる場合」。
            // そのため、日本全国を一度に囲む巨大 bbox は公式側へ流れる。
            // 必要なら自分の日本抽出範囲に合わせて追加・調整する。
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
    'user_agent' => 'armd1-overpass-proxy/1.0 (contact: saka@netfort.gr.jp)',

    // CORS を使う場合だけ設定
    // 同一オリジンなら null でよい
    'allow_origin' => '*',
    // 例:
    // 'allow_origin' => 'https://example.com',

    // デフォルトTTL（秒）
    'default_ttl' => 604800,    // 7日

    // 最大TTL（秒）
    'max_ttl' => 604800,    // 7日     

    // bbox をどれだけ広げるか
    // 0.0 なら広げない
    // 0.2 なら縦横それぞれ元サイズの20%ぶん外へ広げる
    'pad_ratio' => 0.2,

    // bbox の丸め単位
    // 小さいほど正確、大きいほどキャッシュは効きやすいが広がりやすい
    // 近接 bbox をまとめたい用途なら 0.0005 ～ 0.001 あたりが無難
    'round_step' => 0.002,

    // 上流Overpassへのタイムアウト秒
    'curl_timeout_sec' => 120,

    // 何回に1回、期限切れキャッシュ削除を試すか
    // 200 なら約0.5%
    'cleanup_probability_denominator' => 200,

    // DBへ保存する本文サイズ上限（バイト）
    // 共有サーバーなら最初は 4MB くらいが無難
    'max_cache_body_bytes' => 4 * 1024 * 1024,

    // DB保存時にgzip圧縮するか
    'compress_cache' => true,

    // data=... 形式の生Overpass QLを受けたとき、
    // bbox をそのまま使うなら true
    // pad_ratio / round_step による正規化を有効にするなら false
    'raw_data_preserve_bbox' => false,

    // 管理者用の認証情報
    'admin' => [
        'username' => 'sample',
        'password' => 'sample',
    ],

];
