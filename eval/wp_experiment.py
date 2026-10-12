"""Thí nghiệm trên WordPress cục bộ (Docker): FAQ có cần nằm trong RAG không?

Ground truth = câu trả lời trong data/clean/cau-hoi-thuong-gap.xlsx.
Bộ test = 10 câu hỏi gốc + 3 cách diễn đạt lại cho mỗi câu (40 câu). Chạy plugin thật qua admin-ajax.

  PP1: tra FAQ trước -> nếu không khớp thì RAG trên data/clean/*.txt (KHÔNG có FAQ)
  PP2: tra FAQ trước -> nếu không khớp thì RAG trên data/clean/*.txt + FAQ

Cần: Docker Desktop đang chạy; `cd eval/wp-local && docker compose up -d`; cài WP + kích hoạt plugin (xem wp-local/README).
Chạy: OPENROUTER_API_KEY=... python eval/wp_experiment.py
Kết quả: eval/faq_experiment_report.html (+ .json). Mô hình sinh (trong plugin) và mô hình chấm đều là MODEL.
"""
import html
import json
import os
import re
import sys
import subprocess
import time
import zipfile
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parent.parent
CLEAN = ROOT / "data" / "clean"
MODEL = "deepseek/deepseek-v4-flash-0731"  # vừa sinh câu trả lời vừa chấm điểm
API = "https://openrouter.ai/api/v1"
SITE = "http://localhost:8080"
COMPOSE = ["docker", "compose", "-f", str(ROOT / "eval" / "wp-local" / "docker-compose.yml")]

PARAPHRASES = {
    1: ["Em muốn nộp hồ sơ vào trường thì cần chuẩn bị những gì?", "dang ky xet tuyen online o dau, can giay to gi", "Thủ tục đăng ký học ở HATECO như thế nào ạ?"],
    2: ["Trường có những ngành gì vậy?", "Cho em xin danh sách các ngành học của trường", "Bên mình đang tuyển sinh ngành nào ạ?"],
    3: ["Trường nằm ở chỗ nào vậy ạ?", "Em muốn đến trực tiếp trường để hỏi thì đi đâu?", "Văn phòng tuyển sinh của trường ở đâu"],
    4: ["Ngành nào được giảm học phí vậy?", "Có ngành nào được hỗ trợ học phí 70% không ạ?", "Học ngành Thú y có được giảm tiền học không"],
    5: ["Trường có cho học online không ạ?", "Em đã đi làm rồi thì học được không, có lớp từ xa không", "Có chương trình học từ xa không ạ?"],
    6: ["Học từ xa được học những ngành nào?", "Ngành Kế toán có học từ xa được không ạ?", "Danh sách ngành đào tạo từ xa"],
    7: ["Học từ xa thì học như thế nào ạ?", "Hệ từ xa học bằng cách nào, có phải đến lớp không?", "Sinh viên từ xa học qua đâu"],
    8: ["Tốt nghiệp lớp 9 có xét tuyển được không?", "Điều kiện để được xét tuyển vào trường là gì?", "Đã có bằng đại học rồi thì có học tiếp ở trường được không"],
    9: ["Học cao đẳng ở trường mất mấy năm?", "Trung cấp học bao lâu vậy ạ", "Khóa học kéo dài bao lâu?"],
    10: ["Học xong cao đẳng có học lên đại học được không?", "Tốt nghiệp ở trường rồi có liên thông được không ạ", "Ra trường rồi em muốn học tiếp đại học thì sao?"],
}


def load_faqs():
    z = zipfile.ZipFile(CLEAN / "cau-hoi-thuong-gap.xlsx")
    xml = z.read("xl/worksheets/sheet1.xml").decode("utf8")
    rows = []
    for row in re.findall(r"<row .*?</row>", xml, re.S):
        cells = [html.unescape("".join(re.findall(r"<t[^>]*>(.*?)</t>", c, re.S))) for c in re.findall(r"<c .*?</c>", row, re.S)]
        rows.append(cells)
    return [{"question": r[0], "answer": r[1]} for r in rows[1:]]


def faq_doc_text(faqs):
    return "\n\n".join(f"Câu hỏi: {f['question']}\n{f['answer']}" for f in faqs)


def post(path, body, key, tries=4):
    for i in range(tries):
        try:
            r = requests.post(f"{API}/{path}", headers={"Authorization": f"Bearer {key}"}, json=body, timeout=120)
            if r.status_code == 200:
                return r.json()
            err = r.text[:200]
        except requests.RequestException as e:
            err = str(e)
        time.sleep(2 * (i + 1))
    raise RuntimeError(f"{path} failed: {err}")


def chat(system, user, key):
    body = {"model": MODEL, "temperature": 0, "reasoning": {"enabled": False}, "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}]}
    return (post("chat/completions", body, key)["choices"][0]["message"]["content"] or "").strip()


JUDGE = """Bạn là giám khảo. So sánh CÂU TRẢ LỜI CỦA AI với ĐÁP ÁN CHUẨN cho cùng một câu hỏi.
- "dung": AI nêu đủ các ý chính của đáp án chuẩn, không mâu thuẫn, không bịa thêm thông tin sai. Khác cách diễn đạt/văn phong vẫn tính là đúng.
- "mot_phan": đúng nhưng thiếu ý quan trọng, hoặc kèm thông tin không có trong đáp án chuẩn.
- "sai": mâu thuẫn đáp án chuẩn, hoặc không trả lời được (ví dụ nói chưa có thông tin) trong khi đáp án chuẩn có.
Chỉ trả về JSON một dòng: {"verdict": "dung|mot_phan|sai", "ly_do": "<một câu ngắn>"}"""


def judge(question, gt, ai, key):
    raw = chat(JUDGE, f"CÂU HỎI: {question}\n\nĐÁP ÁN CHUẨN:\n{gt}\n\nCÂU TRẢ LỜI CỦA AI:\n{ai}", key)
    m = re.search(r'"verdict"\s*:\s*"(dung|mot_phan|sai)"', raw)
    reason = re.search(r'"ly_do"\s*:\s*"(.*?)"\s*}', raw, re.S)
    return (m.group(1) if m else "sai"), (reason.group(1) if reason else raw[:150])


# ---------- chạy + báo cáo ----------
def build_tests(faqs):
    tests = []
    for i, f in enumerate(faqs, 1):
        tests.append({"type": "gốc", "q": f["question"], "gt": f["answer"], "faq_no": i})
        tests += [{"type": "diễn đạt lại", "q": p, "gt": f["answer"], "faq_no": i} for p in PARAPHRASES[i]]
    return tests


def report(results):
    label = {"dung": ("Đúng", "#16a34a"), "mot_phan": ("Một phần", "#d97706"), "sai": ("Sai", "#dc2626")}

    def cell(r):
        txt, color = label[r["verdict"]]
        return (f'<span class="tag">{r["path"]}</span> <b style="color:{color}">{txt}</b>'
                f'<div class="ans">{html.escape(r["answer"])}</div><div class="why">{html.escape(r["reason"])}</div>')

    def rate(method, pred=lambda t: True):
        rs = [t[method] for t in results if pred(t)]
        d = sum(r["verdict"] == "dung" for r in rs)
        p = sum(r["verdict"] == "mot_phan" for r in rs)
        return f"{d}/{len(rs)} đúng · {p} một phần"

    rows = "".join(
        f'<tr><td>{i}</td><td>{t["type"]}</td><td>{html.escape(t["q"])}</td><td class="gt">{html.escape(t["gt"])}</td>'
        f'<td>{cell(t["pp1"])}</td><td>{cell(t["pp2"])}</td></tr>'
        for i, t in enumerate(results, 1))
    groups = [("Tất cả (40)", lambda t: True), ("Câu hỏi gốc (10)", lambda t: t["type"] == "gốc"),
              ("Diễn đạt lại (30)", lambda t: t["type"] != "gốc"),
              ("Diễn đạt lại và KHÔNG trúng FAQ", lambda t: t["type"] != "gốc" and t["pp1"]["path"] == "RAG")]
    summary = "".join(f"<tr><td>{n}</td><td>{rate('pp1', p)}</td><td>{rate('pp2', p)}</td></tr>" for n, p in groups)
    page = f"""<!doctype html><html lang="vi"><meta charset="utf-8"><title>Thí nghiệm FAQ</title>
<style>body{{font:14px system-ui;margin:20px;color:#0f172a}}table{{border-collapse:collapse;width:100%}}
td,th{{border:1px solid #cbd5e1;padding:8px;vertical-align:top;text-align:left}}th{{background:#f1f5f9;position:sticky;top:0}}
.gt{{background:#f0fdf4;white-space:pre-wrap}}.ans{{white-space:pre-wrap;margin-top:6px}}.why{{color:#64748b;font-size:12px;margin-top:6px}}
.tag{{font-size:11px;background:#e2e8f0;border-radius:4px;padding:1px 6px}}.sum{{width:auto;margin-bottom:24px}}</style>
<h2>Thí nghiệm: FAQ có cần nằm trong RAG không? (mô hình sinh + chấm: {MODEL})</h2>
<p><b>PP1</b>: tra FAQ → RAG không có file FAQ. <b>PP2</b>: tra FAQ → RAG có thêm file FAQ. Nhãn FAQ/RAG = câu trả lời đến từ đâu.</p>
<table class="sum"><tr><th>Nhóm câu hỏi</th><th>PP1</th><th>PP2</th></tr>{summary}</table>
<table><tr><th>#</th><th>Loại</th><th>Câu hỏi test</th><th>Câu trả lời GT (FAQ)</th><th>AI trả lời - PP1</th><th>AI trả lời - PP2</th></tr>{rows}</table></html>"""
    (ROOT / "eval" / "faq_experiment_report.html").write_text(page, encoding="utf8")
    (ROOT / "eval" / "faq_experiment.json").write_text(json.dumps(results, ensure_ascii=False, indent=1), encoding="utf8")




# ---------- điều khiển WordPress cục bộ ----------
def wp(*args, env=None):
    """Chạy wp-cli trong container."""
    cmd = COMPOSE + ["run", "--rm", "-T"] + [x for k in (env or {}) for x in ("-e", k)] + ["wpcli", "wp", *args]
    r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf8", env={**os.environ, **(env or {})})
    if r.returncode:
        raise RuntimeError(f"wp {' '.join(args)}: {r.stderr[-400:]}")
    return r.stdout


def login():
    s = requests.Session()
    s.cookies.set("wordpress_test_cookie", "WP Cookie check")
    s.post(f"{SITE}/wp-login.php", data={"log": "admin", "pwd": "admin", "wp-submit": "Log In", "testcookie": "1"}, allow_redirects=False)
    if not any(c.name.startswith("wordpress_logged_in") for c in s.cookies):
        raise RuntimeError("Đăng nhập admin thất bại")
    return s


def upload_doc(s, name, content):
    page = s.get(f"{SITE}/wp-admin/admin.php?page=ai-chatbot&tab=kb").text
    nonce = re.search(r"formData\.append\('nonce', '([^']+)'\)", page).group(1)
    r = s.post(f"{SITE}/wp-admin/admin-ajax.php", data={"action": "ai_chatbot_upload_file", "nonce": nonce},
               files={"file": (name, content.encode("utf8"), "text/plain")}, timeout=600).json()
    if not r.get("success"):
        raise RuntimeError(f"upload {name}: {r}")


def import_faq(s, path):
    page = s.get(f"{SITE}/wp-admin/admin.php?page=ai-chatbot&tab=faq").text
    nonce = re.search(r'name="_wpnonce" value="([^"]+)"', page).group(1)
    with open(path, "rb") as f:
        r = s.post(f"{SITE}/wp-admin/admin-post.php", data={"action": "ai_chatbot_import_faq", "_wpnonce": nonce},
                   files={"faq_file": (path.name, f, "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")})
    m = re.search(r"faq_imported=(\d+)", r.url)
    if not m:
        raise RuntimeError(f"import FAQ lỗi: {r.url}")
    return int(m.group(1))


def ask(question, nonce, n):
    r = requests.post(f"{SITE}/wp-admin/admin-ajax.php", timeout=180, data={
        "action": "ai_chatbot_chat_query", "message": question, "session_id": f"exp_{n}_{int(time.time())}",
        "nonce": nonce, "eval_mode": "1"}).json()
    if not r.get("success"):
        raise RuntimeError(f"chat lỗi: {r}")
    d = r["data"]
    return d["answer"], ("FAQ" if d.get("faq") else "RAG")


def run_all(tests, nonce, key, tag):
    out = []
    for n, t in enumerate(tests, 1):
        ans, path = ask(t["q"], nonce, n)
        try:
            verdict, reason = judge(t["q"], t["gt"], ans, key)
        except Exception as e:  # một lần chấm lỗi không được làm mất cả lượt chạy
            verdict, reason = "sai", f"(lỗi chấm: {e})"
        out.append({"path": path, "answer": ans, "verdict": verdict, "reason": reason})
        print(f"[{tag} {n}/{len(tests)}] {path} {verdict:8} {t['q']}", flush=True)
    return out


def main():
    sys.stdout.reconfigure(encoding="utf8")
    key = os.environ.get("OPENROUTER_API_KEY", "")
    if not key:
        sys.exit("Thiếu OPENROUTER_API_KEY")
    faqs = load_faqs()
    tests = build_tests(faqs)

    # trạng thái sạch + cấu hình plugin giống site thật (key lấy từ biến môi trường)
    wp("eval", 'global $wpdb; foreach (array("chunks","documents","faqs","leads","conversations","messages") as $t) $wpdb->query("TRUNCATE {$wpdb->prefix}ai_chatbot_$t");')
    wp("option", "update", "ai_chatbot_openrouter_api_key", key, env=None)
    wp("option", "update", "ai_chatbot_openrouter_model", MODEL)
    s = login()
    for p in sorted(CLEAN.glob("*.txt")):
        upload_doc(s, p.name, p.read_text(encoding="utf8"))
        print("đã upload", p.name, flush=True)
    print("FAQ đã nhập:", import_faq(s, CLEAN / "cau-hoi-thuong-gap.xlsx"))
    home = requests.get(SITE + "/").text
    nonce = re.search(r'"nonce":"([^"]+)"', home).group(1)

    pp1 = run_all(tests, nonce, key, "PP1")
    (ROOT / "eval" / "faq_experiment_pp1.json").write_text(json.dumps(pp1, ensure_ascii=False, indent=1), encoding="utf8")
    upload_doc(s, "cau-hoi-thuong-gap.txt", faq_doc_text(faqs))
    print("đã upload cau-hoi-thuong-gap.txt (PP2)", flush=True)
    pp2 = run_all(tests, nonce, key, "PP2")

    report([{**t, "pp1": a, "pp2": b} for t, a, b in zip(tests, pp1, pp2)])
    print("Xong: eval/faq_experiment_report.html")


if __name__ == "__main__":
    main()
