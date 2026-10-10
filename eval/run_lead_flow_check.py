"""Multi-turn check of the lead qualification flow against a live site.
Run: python eval/run_lead_flow_check.py   (creates a test lead named "Test Một"; delete it in the Leads tab after)
"""
import re
import sys
import uuid
import requests

WP_HOME_URL = "https://reachplane.s3-tastewp.com/"
WP_AJAX_URL = WP_HOME_URL + "wp-admin/admin-ajax.php"
ASK_PHONE = re.compile(r"số điện thoại|sđt", re.I)

http = requests.Session()
nonce = re.search(r'"nonce":"([^"]+)"', http.get(WP_HOME_URL, timeout=30).text).group(1)
failures = 0


def save(session_id, role, content):
    http.post(WP_AJAX_URL, data={'action': 'ai_chatbot_save_message', 'nonce': nonce, 'session_id': session_id,
                                 'role': role, 'content': content, 'chat_type': 'ai'}, timeout=30)


def turn(session_id, message):
    # Mirror the widget: save user message, query, save bot answer.
    save(session_id, 'user', message)
    r = http.post(WP_AJAX_URL, data={'action': 'ai_chatbot_chat_query', 'nonce': nonce, 'session_id': session_id,
                                     'message': message, 'eval_mode': '1'}, timeout=90).json()
    data = r.get('data') or {}
    save(session_id, 'bot', data.get('answer', ''))
    print(f"\n> {message}\n< {data.get('answer', r)}\n  lead={data.get('lead')}")
    return data.get('answer', ''), data.get('lead')


def check(cond, label):
    global failures
    print(("  PASS " if cond else "  FAIL ") + label)
    failures += 0 if cond else 1


# A: browsing only -> no phone request
a = str(uuid.uuid4())
ans, lead = turn(a, "Trường có những ngành nào vậy ạ?")
check(not ASK_PHONE.search(ans) and lead is None, "A: general question, no phone ask")

# B/C: clear need -> ask; give phone -> saved; afterwards never re-ask
b = str(uuid.uuid4())
ans, _ = turn(b, "Em đang học lớp 12, em muốn đăng ký xét tuyển ngành Kế toán thì cần hồ sơ gì ạ?")
check(bool(ASK_PHONE.search(ans)), "B: clear need, phone requested")
ans, lead = turn(b, "Em tên là Test Một, sđt 0912 345 678 ạ")
check(bool(lead) and lead['phone'] == '0912345678' and 'Test' in lead['name'], "C: lead saved with normalized phone + name")
check(bool(lead) and '12' in lead['grade'] and 'Kế toán' in lead['major'], "C: grade and major extracted")
check('0912345678' in ans.replace(' ', '').replace('.', ''), "C: confirmation repeats the phone")
ans, lead2 = turn(b, "Học phí ngành này một năm bao nhiêu ạ?")
check(not ASK_PHONE.search(ans), "C: no re-ask after capture")
check(bool(lead2) and str(lead2['id']) == str(lead['id']), "C: same lead row")  # DB row id is a string, save_lead id is an int

# D: clear need, user declines -> bot stops asking
d = str(uuid.uuid4())
turn(d, "Mẹ muốn đăng ký cho con học ngành Công nghệ thông tin, thủ tục thế nào?")
turn(d, "Thôi chưa cần để số đâu, cho mình hỏi thêm đã")
ans, _ = turn(d, "Trường có ký túc xá không?")
check(not ASK_PHONE.search(ans), "D: no nagging after decline")

print(f"\n{failures} failure(s)")
sys.exit(1 if failures else 0)
