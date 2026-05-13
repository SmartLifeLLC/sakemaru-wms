# 本番DB SELECT確認 Knowledge

## 原則

- 本番DB確認は `SELECT` のみ実行する。
- `INSERT` / `UPDATE` / `DELETE` / `TRUNCATE` / DDL / artisan migration 系は実行しない。
- 接続情報、パスワード、APP_KEY、AWSキーは出力しない。

## 接続方法

本番Auroraへの接続方法は `HanaDBTransfer` 側の既存ユーティリティを使う。

- プロジェクト: `/Users/jungsinyu/PycharmProjects/HanaDBTransfer`
- 接続関数: `lib/mysql_utils.py` の `create_mysql_connection_prod(force_new=True)`
- 接続設定: `HanaDBTransfer/.env` の `MYSQL_*_PROD` と `MYSQL_CA_PROD`
- 確認日: 2026-05-13。`MYSQL_HOST_PROD` / `MYSQL_DATABASE_PROD` / `MYSQL_USER_PROD` / `MYSQL_CA_PROD` は `.env` に存在する。値は出力・保存しない。

確認用の最小パターン:

```python
import os, sys

os.chdir('/Users/jungsinyu/PycharmProjects/HanaDBTransfer')
sys.path.insert(0, os.getcwd())

from lib.mysql_utils import create_mysql_connection_prod

conn = create_mysql_connection_prod(force_new=True)
cur = conn.cursor(dictionary=True)
cur.execute("SELECT DATABASE() AS db, @@hostname AS host, NOW() AS db_now")
rows = cur.fetchall()
cur.close()
conn.close()
```

## 注意

- `master_main.py --prod` や migration 系スクリプトは投入処理を含むため、調査では使わない。
- `lib/mysql_utils.py` には書き込み用関数もあるため、調査時は直接 `cursor.execute()` で `SELECT` のみ実行する。
- 調査スクリプト内でも `conn.commit()` は呼ばない。
- 出力前に秘匿値が含まれていないか確認する。
