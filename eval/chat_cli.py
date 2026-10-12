"""Chat thử với chatbot trên terminal (script tạm).
Chạy: python eval/chat_cli.py [--ctx] [--url https://site/]
Lệnh: /new = phiên mới, /ctx = bật/tắt xem ngữ cảnh, /q = thoát.
"""
import argparse
import re
import sys
import uuid

import requests

ap = argparse.ArgumentParser()
ap.add_argument("--url", default="https://reachplane.s3-tastewp.com/")
ap.add_argument("--ctx", action="store_true", help="in các đoạn ngữ cảnh truy xuất")
args = ap.parse_args()

home = args.url.rstrip("/") + "/"
ajax = home + "wp-admin/admin-ajax.php"
sys.stdout.reconfigure(encoding="utf-8")

m = re.search(r'"nonce":"([^"]+)"', requests.get(home, timeout=30).text)
nonce = m.group(1) if m else ""
if not nonce:
    print("(không lấy được nonce, vẫn thử gửi)")

sid = "cli_" + uuid.uuid4().hex[:8]
show_ctx = args.ctx
print(f"Kết nối {ajax} | session {sid}\n")

while True:
    try:
        q = input("Bạn: ").strip()
    except (EOFError, KeyboardInterrupt):
        break
    if not q:
        continue
    if q == "/q":
        break
    if q == "/new":
        sid = "cli_" + uuid.uuid4().hex[:8]
        print(f"(phiên mới {sid})")
        continue
    if q == "/ctx":
        show_ctx = not show_ctx
        print(f"(ctx = {show_ctx})")
        continue
    try:
        r = requests.post(ajax, data={
            "action": "ai_chatbot_chat_query", "message": q,
            "session_id": sid, "nonce": nonce, "eval_mode": "1",
        }, timeout=90).json()
    except Exception as e:
        print(f"Lỗi: {e}")
        continue
    if not r.get("success"):
        print(f"Lỗi: {r}")
        continue
    d = r["data"]
    print(f"\nBot: {d.get('answer', '')}\n")
    if show_ctx:
        for i, c in enumerate(d.get("contexts", []), 1):
            print(f"  [{i}] {c[:200].replace(chr(10), ' ')}...")
        print()
