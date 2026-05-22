"""pytest 共通セットアップ。

⚠️ このファイルは pytest がテスト収集を始める前に最初に読み込まれる。
   app.py は import 時に DATA_DIR 配下の JSON を読み・起動処理を走らせるため、
   ここで DATA_DIR を一時ディレクトリへ向けてから import させる。
   これで本番の data/ や Render の /data を一切触らずにテストできる。
"""
import os
import tempfile

# app.py を import する前に環境変数を確定させる
_TMP_DATA_DIR = tempfile.mkdtemp(prefix='wpmgr-test-')
os.environ['DATA_DIR'] = _TMP_DATA_DIR
os.environ.setdefault('APP_PASSWORD', 'testpass')
os.environ.setdefault('SECRET_KEY', 'test-secret-key')
# 本番デプロイ判定に使う RENDER 変数はテストでは未設定にしておく
os.environ.pop('RENDER', None)
