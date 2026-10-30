"""Chấm chatbot theo ground truth.
Chạy: python eval/eval_groundtruth.py eval/groundtruth.csv [--url https://site/] [--out eval/gt_report.csv]

CSV (UTF-8, có header): question,facts
  facts = các ý bắt buộc phải có, ngăn cách bằng "|" (vd: "15.000.000|học kỳ").
Khớp không phân biệt hoa/thường, dấu tiếng Việt và khoảng trắng.
Mỗi câu hỏi dùng một phiên mới (không dính lịch sử).
  - retrieval: mọi fact đều nằm trong contexts (và vị trí chunk đầu tiên chứa fact)
  - answer: mọi fact đều nằm trong câu trả lời
Câu trúng FAQ trả về nguyên văn, có thể không có contexts -> retrieval ghi "faq".
"""
import argparse
import csv
import re
import sys
import unicodedata
import uuid

import requests

ap = argparse.ArgumentParser()
ap.add_argument("csv_path")
ap.add_argument("--url", default="https://reachplane.s3-tastewp.com/")
ap.add_argument("--out", default="eval/gt_report.csv")
args = ap.parse_args()
sys.stdout.reconfigure(encoding="utf-8")


def norm(s):
    s = unicodedata.normalize("NFD", s.lower().replace("đ", "d"))
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return re.sub(r"\s+", " ", s).strip()


home = args.url.rstrip("/") + "/"
ajax = home + "wp-admin/admin-ajax.php"
m = re.search(r'"nonce":"([^"]+)"', requests.get(home, timeout=30).text)
nonce = m.group(1) if m else ""

rows, n_ret, n_ans = [], 0, 0
with open(args.csv_path, encoding="utf-8-sig", newline="") as f:
    cases = list(csv.DictReader(f))

for i, case in enumerate(cases, 1):
    q = case["question"].strip()
    facts = [norm(x) for x in case["facts"].split("|") if x.strip()]
    try:
        r = requests.post(ajax, data={
            "action": "ai_chatbot_chat_query", "message": q,
            "session_id": "gt_" + uuid.uuid4().hex[:8], "nonce": nonce, "eval_mode": "1",
        }, timeout=90).json()
        d = r["data"] if r.get("success") else {}
    except Exception as e:
        d = {}
        print(f"Lỗi câu {i}: {e}")
    answer, ctxs = d.get("answer", ""), [norm(c) for c in d.get("contexts", [])]
    faq = not ctxs and d.get("faq_score") is not None

    joined = " ".join(ctxs)
    ret_ok = all(x in joined for x in facts)
    ranks = [next((k for k, c in enumerate(ctxs, 1) if x in c), 0) for x in facts]
    ans_ok = all(x in norm(answer) for x in facts)
    n_ret += ret_ok
    n_ans += ans_ok

    ret_label = "faq" if faq else ("OK" if ret_ok else "MISS")
    print(f"[{i}/{len(cases)}] ret={ret_label:4} ans={'OK' if ans_ok else 'FAIL':4} rank={ranks} | {q}")
    rows.append({"question": q, "facts": case["facts"], "retrieval": ret_label,
                 "fact_ranks": ranks, "answer_ok": ans_ok, "answer": answer})

n = len(cases)
print(f"\nRetrieval hit: {n_ret}/{n} | Answer đúng: {n_ans}/{n}")
print("Retrieval MISS = sai ở truy xuất; ret OK nhưng ans FAIL = sai ở prompt/model.")
with open(args.out, "w", encoding="utf-8-sig", newline="") as f:
    w = csv.DictWriter(f, fieldnames=list(rows[0]) if rows else ["question"])
    w.writeheader()
    w.writerows(rows)
print(f"Báo cáo: {args.out}")
