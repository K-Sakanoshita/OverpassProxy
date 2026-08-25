# OverpassProxy Sakura

Overpass API互換のPHPプロキシです。bboxに応じて上流を選択し、JSON応答をMySQLへキャッシュします。

## 公開ファイル

- `www/api/overpass_proxy.php`: プロキシ本体
- `www/api/lib/RequestGuard.php`: 入力・bbox・同時実行数の制限
- `www/api/lib/RawQueryBbox.php`: raw Overpass QLからのbbox抽出
- `www/api/lib/BboxGrid.php`: bboxの動的グリッド正規化
- `www/api/cache_admin.php`: Basic認証付きキャッシュ管理画面
- `www/api/cache_admin_api.php`: 管理画面用API

## raw BBOXグリッドと互換性

`data=...` で送られる完成済みOverpass QLを、このプロキシではrawクエリと呼びます。既定では、bboxを抽出できるrawクエリの境界を共通グリッドへ外向きに揃え、書き換え後のクエリを上流へ送ります。

```text
要求bbox: 34.6931,135.5011,34.7068,135.5189
選択刻み: 0.002度
上流bbox: 34.692,135.5,34.708,135.52
```

刻みは元bboxの緯度幅・経度幅の大きい方から選択します。

| bbox最大幅 | グリッド刻み |
|---:|---:|
| 0.02度以下 | 0.002度 |
| 0.05度以下 | 0.005度 |
| 0.10度以下 | 0.01度 |
| 0.25度以下 | 0.02度 |
| 0.50度以下 | 0.05度 |
| 1.00度以下 | 0.10度 |
| 2.50度以下 | 0.25度 |
| 5.00度以下 | 0.50度 |
| 10.0度以下 | 1.00度 |

この動作は標準Overpass APIとの完全互換ではありません。

- bboxが外側へ広がるため、要求範囲外の要素がレスポンスに含まれることがあります。
- グローバル指定 `[bbox:south,west,north,east]` がある場合は、その最初の1件をグリッド化します。
- グローバルbboxがなく複数のinline bboxがある場合は、それらの和集合をグリッド化し、すべてのinline bboxを同じ境界へ置換します。個別範囲の意味は保持されません。
- bboxを抽出できないrawクエリは変更せず公式上流へ転送し、DBキャッシュを使用しません。
- グリッド化後のbboxが設定上限を超えた場合はHTTP 400になります。
- グリッド化後のbboxで上流ルートを決定するため、境界付近では従来と異なる上流が選ばれることがあります。

応答ヘッダーで適用結果を確認できます。

- `X-BBox-Policy: raw_grid_v1`
- `X-BBox-Grid-Step`: 選択された刻み
- `X-Normalized-BBox`: 実際に上流へ送ったbbox
- `X-Upstream-Query-Mode: raw_grid`

`raw_bbox_grid_enabled=false` にすると、bboxを抽出できるrawクエリも従来の完全passthroughへ戻ります。ポリシーを変更するときは `raw_bbox_grid_policy_version` も変更すると、異なる正規化方式のキャッシュ混在を防げます。

キャッシュ検索は全モードで完全一致を優先します。さらに広いbboxのキャッシュを流用する包含検索は、`allow_containing_cache_match=false` により既定で無効です。

キャッシュキー形式はグリッドポリシーを含むv7へ更新されています。導入直後は新しいキーに対してコールドキャッシュになります。旧形式のキャッシュはヒットしませんが、通常の期限切れ削除で除去されます。すぐにDB容量を回収したい場合だけ、管理画面から旧キャッシュを削除してください。

## リクエスト制限

制限値は `config_overpass.php` で変更できます。

- `max_concurrent_requests_per_client`: `REMOTE_ADDR`ごとの同時実行数。既定値は2
- `concurrency_retry_after_sec`: 429応答の`Retry-After`
- `concurrency_lock_dir`: 同時実行数判定用ロックの保存先。既定では公開ディレクトリ外の`runtime/client-locks`
- `max_request_body_bytes`: POST本文の最大バイト数
- `max_query_bytes`: Overpass QLの最大バイト数
- `max_bbox_lat_span_degrees`: bboxの最大緯度幅
- `max_bbox_lon_span_degrees`: bboxの最大経度幅
- `max_bbox_area_degrees2`: 緯度幅と経度幅を掛けた最大平方度

bbox上限は利用者指定値と、グリッド化または`pad_ratio`・`round_step`適用後に実際に上流へ送る値の両方へ適用されます。

同時実行数判定は、偽装可能な`X-Forwarded-For`ではなく`REMOTE_ADDR`を使います。リバースプロキシ配下では、信頼できるWebサーバー設定で実クライアントIPを`REMOTE_ADDR`へ反映してください。

bboxを抽出できないraw Overpass QLはキャッシュ対象外となり、DBへ接続せず上流へ直接転送します。bboxを抽出できる要求は、形式と上限を検証した後でのみDBへ接続します。DB障害時はキャッシュを迂回し、上流転送を継続します。

## データベース

新規環境では `database/schema.sql` を適用してください。既存環境ではカラム型とインデックスを比較し、バックアップ取得後に不足分だけを反映してください。

## ローカル確認

```sh
php tests/bbox_grid_test.php
php tests/request_guard_test.php
find . -name '*.php' -type f -exec php -l {} \;
```

CLI環境で上流転送まで確認するにはPHP cURL拡張が必要です。DBキャッシュを含む結合確認は、`pdo_mysql`とテスト用MySQLを用意して実施します。

## 推奨アクセス権

- ディレクトリ: `755`
- 公開PHP・`.htaccess`・ドキュメント: `644`
- `config_overpass.php`: 所有者だけが読む場合は`600`、Webサーバーグループにも読ませる場合は`640`
