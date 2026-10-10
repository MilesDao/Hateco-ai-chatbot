# Lead Qualification Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The chatbot answers every admissions question first, recognizes when a visitor has a concrete admissions need, then invites them to leave name + phone; captured leads (with grade, major of interest, admissions need) are stored per session, synced to the Google Sheets webhook, and listed for admissions staff.

**Architecture:** Intent detection and the "ask for phone" decision are done by the chat LLM itself via new flow rules appended to the existing hardcoded system prompt — no extra classifier call. A server-side guard tells the LLM when the session already has a lead so it never re-asks. When a phone number is detected (or a captured lead still lacks a name), one synchronous LLM extraction call pulls `name / grade / major / need` from the conversation as JSON; the phone itself always comes from a regex. All new logic lives in one class, `AI_Chatbot_Lead_Flow`, whose pure helpers are covered by a plain-PHP assert script.

**Tech Stack:** WordPress plugin, PHP 7.4+, `$wpdb`/`dbDelta`, OpenRouter (existing `OpenRouter_API_Client::generate_chat_answer`), jQuery admin page, Python `requests` for the live check.

**Spec:** No separate spec doc — the user's request (2026-10-10) is the spec:
1. Visitor (student/parent) sends a question through the chatbot.
2. AI answers using the Knowledge Base (school, majors, tuition, admission methods…).
3. AI determines whether the visitor is just browsing or has a clear admissions need.
4. Clear need → AI asks for phone number so admissions can call; otherwise keep advising normally.
5. When a phone is given, save it; also save major of interest, current grade, and admissions need when available.
6. Bot thanks the user, confirms the info was recorded, and hands the conversation to admissions staff when needed.

Interpretation decisions (flag to the user at review):
- "Custom Field/User Field" (a Botcake concept) maps to new columns on `{prefix}ai_chatbot_leads`.
- "Hand off to staff" = lead row linked to the conversation (`conversations.lead_id`) + `LEAD` webhook to Google Sheets with full history + new admin **Leads** tab. There is no live human-chat UI in the widget today and this plan does not build one.

## Global Constraints

- PHP 7.4 compatible: no `str_contains`, `match`, named args, nullsafe `?->`, union types.
- All user-facing text, prompts and admin labels in Vietnamese; bot addresses itself "mình", user "bạn" (existing persona).
- No new dependencies (PHP or JS). New PHP file must start with the `if (!defined('ABSPATH')) { exit; }` guard like the other includes.
- Escape all admin output with `esc_html` / `esc_attr`; all SQL with values goes through `$wpdb->prepare` or `$wpdb->insert/update`.
- Schema changes go into `ai_chatbot_activate()`; bump `AI_CHATBOT_VERSION` **and** the `Version:` plugin header to `2.3.0` (the existing `ai_chatbot_db_version` check at `ai-chatbot.php:154` re-runs activation on upgrade).
- Phone format accepted: Vietnamese mobile (`03/05/07/08/09` + 8 digits, or `+84`/`0084` prefix), stored normalized as `0xxxxxxxxx`.

## Review Focus

1. **Phone typed with spaces, dots, dashes or `+84`** (`0985 282 393`, `+84 985.282.393`) → captured and stored as `0985282393`. Pinned in Task 1 `detect_phone` tests.
2. **Numbers that are not phones** (scores "25 điểm", year "2026", 11-digit strings, landline `024…`, "lớp 12" next to a phone) → not captured / phone still extracted correctly. Pinned in Task 1.
3. **Extraction LLM fails, times out, or returns fenced/garbage JSON** → lead is still saved with the regex phone and regex-fallback name. Pinned in Task 1 `parse_extraction` tests + Task 4 code path (`is_wp_error` → `''`).
4. **Same session sends a phone twice / corrects it** → one lead row updated, earlier non-empty fields kept. Pinned in Task 1 `merge_lead` test + Task 4 manual step.
5. **Visitor declines to give a phone, or already gave one** → bot stops asking. Pinned in Task 2 `flow_rules` tests (prompt content) + Task 6 live scenarios D and C.

---

## File Structure

- Create `includes/class-lead-flow.php` — `AI_Chatbot_Lead_Flow`: phone detection, extraction prompt/parsing, merge, prompt rules, DB read/write of leads. One responsibility: the lead lifecycle.
- Create `tests/test-lead-flow.php` — plain PHP assert script with minimal WP stubs.
- Modify `ai-chatbot.php` — schema, version, `require_once`, wire lead flow into `handle_chat_query()`.
- Modify `includes/class-admin-settings.php` — new "Khách hàng (Leads)" tab, fix empty-row colspan.
- Modify `package_plugin.py` — exclude `tests` and `docs` from the zip.
- Create `eval/run_lead_flow_check.py` — multi-turn live check against the staging site.

---

### Task 1: Lead_Flow pure helpers + test harness

**Files:**
- Create: `includes/class-lead-flow.php`
- Create: `tests/test-lead-flow.php`
- Modify: `package_plugin.py:6`

**Interfaces:**
- Produces:
  - `AI_Chatbot_Lead_Flow::detect_phone(string $message): array|null` → `array('raw' => <as typed>, 'phone' => '0xxxxxxxxx')` or `null`
  - `AI_Chatbot_Lead_Flow::parse_extraction(mixed $raw): array` → always keys `name, grade, major, need` (strings, `''` when unknown)
  - `AI_Chatbot_Lead_Flow::merge_lead(array $existing, array $new): array` → keys `name, phone, grade, major, need` (+ `id` if existing had it); non-empty new values win
  - `AI_Chatbot_Lead_Flow::build_transcript(array $history, string $message): string`
  - `AI_Chatbot_Lead_Flow::fallback_name(string $message, string $raw_phone): string` (`''` if none)
  - `AI_Chatbot_Lead_Flow::EXTRACTION_PROMPT` (string const)

- [ ] **Step 1: Install PHP CLI (one-time, if `php -v` fails)**

Run (PowerShell): `winget install --id PHP.PHP.8.3 -e`
Then open a new shell and run `php -m`. If `mbstring` is not listed: in the PHP install dir copy `php.ini-development` to `php.ini`, uncomment `extension_dir = "ext"` and `extension=mbstring`. Re-run `php -m` and confirm `mbstring` appears.

- [ ] **Step 2: Write the failing test**

Create `tests/test-lead-flow.php`:

```php
<?php
// Run: php tests/test-lead-flow.php
define('ABSPATH', __DIR__ . '/');
function wp_strip_all_tags($s) { return trim(strip_tags($s)); }
require __DIR__ . '/../includes/class-lead-flow.php';

function check($cond, $label)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "ok - $label\n";
}

$F = 'AI_Chatbot_Lead_Flow';

// detect_phone
$p = $F::detect_phone('sđt em 0985282393 ạ');
check($p['phone'] === '0985282393' && $p['raw'] === '0985282393', 'plain phone');
check($F::detect_phone('0985 282 393')['phone'] === '0985282393', 'spaces');
check($F::detect_phone('số: 0985.282.393')['phone'] === '0985282393', 'dots');
check($F::detect_phone('+84 985-282-393')['phone'] === '0985282393', '+84 with dashes');
check($F::detect_phone('0084985282393')['phone'] === '0985282393', '0084 prefix');
check($F::detect_phone('em lớp 12 0912345678')['phone'] === '0912345678', 'grade number before phone');
check($F::detect_phone('em được 25 điểm năm 2026') === null, 'scores and years are not phones');
check($F::detect_phone('09852823931') === null, '11 digits rejected');
check($F::detect_phone('0243 123 4567') === null, 'landline rejected');
check($F::detect_phone('0123456789') === null, 'invalid prefix rejected');

// parse_extraction
$e = $F::parse_extraction('{"name":"Nguyễn Lan","grade":"Lớp 12","major":"Kế toán","need":"Muốn xét tuyển"}');
check($e === array('name' => 'Nguyễn Lan', 'grade' => 'Lớp 12', 'major' => 'Kế toán', 'need' => 'Muốn xét tuyển'), 'clean json');
$e = $F::parse_extraction("```json\n{\"name\": \"Lan\", \"major\": null}\n```");
check($e['name'] === 'Lan' && $e['major'] === '' && $e['grade'] === '', 'fenced json, null field');
$e = $F::parse_extraction('{"name":"không rõ","need":"<b>Đăng ký</b>"}');
check($e['name'] === '' && $e['need'] === 'Đăng ký', 'placeholder words dropped, tags stripped');
check($F::parse_extraction('xin lỗi tôi không hiểu') === array('name' => '', 'grade' => '', 'major' => '', 'need' => ''), 'garbage');
check($F::parse_extraction(null)['name'] === '', 'non-string input');
check(mb_strlen($F::parse_extraction('{"need":"' . str_repeat('a', 500) . '"}')['need'], 'UTF-8') === 120, 'long values truncated');

// merge_lead
$m = $F::merge_lead(
    array('id' => 5, 'name' => 'Lan', 'phone' => '0912345678', 'grade' => '', 'major' => 'Kế toán', 'need' => ''),
    array('name' => '', 'phone' => '0987654321', 'grade' => 'Lớp 12', 'major' => '', 'need' => 'Xét tuyển')
);
check($m['id'] === 5 && $m['name'] === 'Lan' && $m['phone'] === '0987654321' && $m['major'] === 'Kế toán' && $m['grade'] === 'Lớp 12' && $m['need'] === 'Xét tuyển', 'merge keeps old non-empty, new non-empty wins');
check($F::merge_lead(array(), array('phone' => '0912345678'))['name'] === '', 'merge fills missing keys');

// build_transcript
$t = $F::build_transcript(
    array(array('role' => 'user', 'content' => 'Ngành Kế toán?'), array('role' => 'assistant', 'content' => 'Có ạ'), array('role' => 'user', 'content' => 'sđt 0912345678')),
    'sđt 0912345678'
);
check($t === "Người dùng: Ngành Kế toán?\nTrợ lý: Có ạ\nNgười dùng: sđt 0912345678", 'transcript, current message not duplicated');
check($F::build_transcript(array(), 'hi') === 'Người dùng: hi', 'transcript without history');

// fallback_name (regex moved verbatim from ai-chatbot.php)
check($F::fallback_name('Mình tên là Trung, số điện thoại là 0985282393', '0985282393') === 'Trung', 'fallback name');
check($F::fallback_name('0985282393', '0985282393') === '', 'fallback name empty');

echo "ALL PASSED\n";
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php tests/test-lead-flow.php`
Expected: fatal error "Failed opening required '.../includes/class-lead-flow.php'".

- [ ] **Step 4: Write minimal implementation**

Create `includes/class-lead-flow.php`:

```php
<?php
/**
 * Lead qualification & capture: phone detection, LLM extraction parsing,
 * prompt rules for the chat flow, and lead persistence per chat session.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Chatbot_Lead_Flow
{
    const FIELDS = array('name', 'grade', 'major', 'need');

    // VN mobile, optional +84/0084 prefix, digits may be split by space/dot/dash.
    const PHONE_REGEX = '/(?<!\d)(?:\+84|0084|0)[\s.\-]?([35789](?:[\s.\-]?\d){8})(?!\d)/';

    const EXTRACTION_PROMPT = 'Bạn là bộ trích xuất dữ liệu. Đọc đoạn hội thoại tư vấn tuyển sinh và trả về DUY NHẤT một đối tượng JSON, không giải thích, theo mẫu:
{"name": "", "grade": "", "major": "", "need": ""}
- name: họ tên người dùng tự giới thiệu (không phải tên trợ lý, không phải tên trường). Không rõ thì "".
- grade: lớp/trình độ hiện tại, ví dụ "Lớp 12", "Đã tốt nghiệp THPT", "Lớp 9". Không rõ thì "".
- major: ngành học người dùng quan tâm. Không rõ thì "".
- need: tóm tắt nhu cầu tuyển sinh trong tối đa 15 từ, ví dụ "Phụ huynh muốn đăng ký xét tuyển ngành Kế toán cho con". Không rõ thì "".
Chỉ dùng thông tin trong các dòng "Người dùng:". Không suy đoán.';

    /**
     * @return array|null array('raw' => as typed, 'phone' => '0xxxxxxxxx') or null.
     */
    public static function detect_phone($message)
    {
        if (!preg_match(self::PHONE_REGEX, $message, $m)) {
            return null;
        }
        return array('raw' => $m[0], 'phone' => '0' . preg_replace('/\D/', '', $m[1]));
    }

    public static function parse_extraction($raw)
    {
        $out = array_fill_keys(self::FIELDS, '');
        $data = null;
        if (is_string($raw) && preg_match('/\{.*\}/s', $raw, $m)) {
            $data = json_decode($m[0], true);
        }
        if (!is_array($data)) {
            return $out;
        }
        foreach (self::FIELDS as $field) {
            if (!isset($data[$field]) || !is_scalar($data[$field])) {
                continue;
            }
            $value = trim(wp_strip_all_tags((string) $data[$field]));
            if (in_array(mb_strtolower($value, 'UTF-8'), array('null', 'không rõ', 'không có', 'n/a', 'unknown'), true)) {
                $value = '';
            }
            $out[$field] = mb_substr($value, 0, 120, 'UTF-8');
        }
        return $out;
    }

    public static function merge_lead($existing, $new)
    {
        $out = array_merge(array_fill_keys(array('name', 'phone', 'grade', 'major', 'need'), ''), $existing);
        foreach ($new as $key => $value) {
            if ($value !== '' && $value !== null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public static function build_transcript($history, $message)
    {
        $lines = array();
        foreach ($history as $msg) {
            $lines[] = ($msg['role'] === 'user' ? 'Người dùng: ' : 'Trợ lý: ') . $msg['content'];
        }
        // The widget saves the user message before querying, so it may already be the last history entry.
        $current = 'Người dùng: ' . $message;
        if (end($lines) !== $current) {
            $lines[] = $current;
        }
        return implode("\n", $lines);
    }

    public static function fallback_name($message, $raw_phone)
    {
        // MOVE HERE VERBATIM from ai-chatbot.php (current lines 302-333): the block that starts at
        // "// 1. Remove phone number and any associated phone labels" and ends at the second
        // "$clean_msg = preg_replace('/^[,\-:;—_\.\/\s]+|[,\-:;—_\.\/\s]+$/u', '', $clean_msg);".
        // In the moved code rename every $captured_phone to $raw_phone. Nothing else changes.

        return mb_strlen($clean_msg, 'UTF-8') >= 2 ? $clean_msg : '';
    }
}
```

Then do the move described in `fallback_name`: **copy** lines 302–333 of `ai-chatbot.php` (from `// 1. Remove phone number…` through the second `$clean_msg = preg_replace('/^[,\-:;—_\.\/\s]+…`), paste them in place of the MOVE comment, replace `$captured_phone` → `$raw_phone` inside the pasted block, and delete the MOVE comment. Do not touch `ai-chatbot.php` in this task — the original lines are deleted in Task 4 Step 2, so the plugin stays runnable between commits.

Also edit `package_plugin.py` line 6:

```python
    exclude_dirs = {'.venv', 'eval', '.git', '.codegraph', '.vscode', '__pycache__', 'tests', 'docs'}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/test-lead-flow.php`
Expected: every line `ok - …`, last line `ALL PASSED`, exit code 0.

- [ ] **Step 6: Commit**

```bash
git add includes/class-lead-flow.php tests/test-lead-flow.php package_plugin.py
git commit -m "feat: add lead flow helpers (phone detection, extraction parsing, merge)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Prompt rules for qualification and capture confirmation

**Files:**
- Modify: `includes/class-lead-flow.php` (add two methods)
- Test: `tests/test-lead-flow.php` (append before `echo "ALL PASSED\n";`)

**Interfaces:**
- Consumes: lead array shape from Task 1 (`name, phone, grade, major, need`).
- Produces:
  - `AI_Chatbot_Lead_Flow::flow_rules(array|null $lead): string` — appended to the system prompt every non-eval and eval turn
  - `AI_Chatbot_Lead_Flow::capture_note(array $lead): string` — appended only on the turn a phone was captured

- [ ] **Step 1: Write the failing test**

Append to `tests/test-lead-flow.php` before `echo "ALL PASSED\n";`:

```php
// flow_rules
$r = $F::flow_rules(null);
check(strpos($r, 'TÌM HIỂU CHUNG') !== false && strpos($r, 'CÓ NHU CẦU RÕ RÀNG') !== false, 'rules describe both intents');
check(strpos($r, 'SỐ ĐIỆN THOẠI') !== false, 'rules invite phone when no lead');
check(strpos($r, 'KHÔNG nhắc lại') !== false, 'rules forbid nagging after a decline');
$r = $F::flow_rules(array('name' => 'Lan', 'phone' => '0912345678'));
check(strpos($r, 'KHÔNG xin lại') !== false && strpos($r, 'CÓ NHU CẦU RÕ RÀNG') === false, 'lead present: never re-ask');

// capture_note
$n = $F::capture_note(array('name' => '', 'phone' => '0912345678', 'grade' => '', 'major' => '', 'need' => ''));
check(strpos($n, '0912345678') !== false && strpos($n, 'họ tên') !== false, 'asks for name when missing');
$n = $F::capture_note(array('name' => 'Lan', 'phone' => '0912345678', 'grade' => '', 'major' => '', 'need' => ''));
check(strpos($n, 'Lan') !== false && strpos($n, 'họ tên') === false && strpos($n, 'lớp mấy') !== false, 'name known: asks grade/major instead');
$n = $F::capture_note(array('name' => 'Lan', 'phone' => '0912345678', 'grade' => 'Lớp 12', 'major' => 'Kế toán', 'need' => ''));
check(strpos($n, 'lớp mấy') === false, 'all known: asks nothing extra');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-lead-flow.php`
Expected: fatal "Call to undefined method AI_Chatbot_Lead_Flow::flow_rules()".

- [ ] **Step 3: Write minimal implementation**

Add to `AI_Chatbot_Lead_Flow`:

```php
    public static function flow_rules($lead)
    {
        if ($lead) {
            return "\n\nTHÔNG TIN LIÊN HỆ:\n6. Hệ thống ĐÃ ghi nhận số điện thoại của người dùng này, tổ tư vấn tuyển sinh sẽ gọi lại. TUYỆT ĐỐI KHÔNG xin lại số điện thoại hay họ tên. Với câu hỏi không có trong [KHO TRI THỨC], trả lời: \"Dạ, phần này thầy cô tư vấn sẽ giải đáp chi tiết khi gọi cho bạn nhé.\" (thay cho quy tắc 5).";
        }

        return "\n\nQUY TRÌNH TƯ VẤN & THU THẬP THÔNG TIN:
6. Luôn trả lời đầy đủ câu hỏi của người dùng TRƯỚC, sau đó mới tính đến việc xin thông tin.
7. Tự đánh giá nhu cầu của người dùng qua toàn bộ cuộc trò chuyện:
   - TÌM HIỂU CHUNG (hỏi chung về trường, danh sách ngành, chưa nói rõ mong muốn của bản thân): tiếp tục tư vấn bình thường, KHÔNG xin số điện thoại.
   - CÓ NHU CẦU RÕ RÀNG (muốn đăng ký/xét tuyển/nộp hồ sơ; hỏi học phí, thủ tục hoặc hạn nộp của một ngành cụ thể; cho biết đang học lớp 12 hoặc đã tốt nghiệp và hỏi về nhập học; phụ huynh hỏi cho con một ngành cụ thể; muốn được gọi tư vấn hoặc gặp tư vấn viên): sau khi trả lời, mời khéo léo để lại HỌ TÊN và SỐ ĐIỆN THOẠI để thầy cô tổ tư vấn tuyển sinh gọi điện tư vấn kỹ hơn và hỗ trợ hồ sơ.
8. Lời mời chỉ 1 câu ngắn, tự nhiên, nêu lợi ích. Nếu người dùng từ chối hoặc lờ đi lời mời, KHÔNG nhắc lại ở các lượt sau, trừ khi họ thể hiện nhu cầu rõ ràng hơn trước.
9. Nếu người dùng muốn nói chuyện với người thật, mời họ để lại họ tên và số điện thoại để tư vấn viên gọi lại.";
    }

    public static function capture_note($lead)
    {
        if ($lead['name'] === '') {
            $extra = 'hỏi xin họ tên để thầy cô tiện xưng hô khi gọi';
        } elseif ($lead['grade'] === '' || $lead['major'] === '') {
            $extra = 'hỏi nhẹ nhàng bạn đang học lớp mấy và quan tâm ngành nào (chỉ hỏi phần chưa biết) để thầy cô chuẩn bị tư vấn';
        } else {
            $extra = 'không hỏi thêm thông tin cá nhân';
        }
        $who = $lead['name'] !== '' ? ' của bạn ' . $lead['name'] : '';

        return "\n\n[HỆ THỐNG ĐÃ LƯU THÔNG TIN LIÊN HỆ{$who}: số điện thoại {$lead['phone']}]. Nếu tin nhắn có kèm câu hỏi thì trả lời câu hỏi đó trước. Sau đó: (1) cảm ơn chân thành, (2) xác nhận đã ghi nhận thông tin và thầy cô tổ tư vấn tuyển sinh Trường Cao đẳng Kinh tế Công nghệ Hà Nội (Hateco) sẽ sớm gọi điện qua số {$lead['phone']}, (3) {$extra}, (4) hỏi bạn còn thắc mắc gì không.";
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-lead-flow.php`
Expected: `ALL PASSED`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-lead-flow.php tests/test-lead-flow.php
git commit -m "feat: add lead qualification prompt rules and capture confirmation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Schema + lead persistence

**Files:**
- Modify: `ai-chatbot.php:5` (header `Version:`), `ai-chatbot.php:20` (constant), `ai-chatbot.php:75-86` (leads table), `ai-chatbot.php:131-133` (require)
- Modify: `includes/class-lead-flow.php` (add DB methods)

**Interfaces:**
- Consumes: `merge_lead` output shape (Task 1).
- Produces:
  - `AI_Chatbot_Lead_Flow::get_lead(string $session_id): array|null` → `id, name, phone, grade, major, need` or `null` (always `null` for `''`)
  - `AI_Chatbot_Lead_Flow::save_lead(string $session_id, array $lead): array` → `$lead` with `id` set; upserts by `id`, links `conversations.lead_id` + `lead_name`
  - `AI_Chatbot_Lead_Flow::webhook_data(array $lead): array` → payload `data` for `send_to_google_sheets_webhook('LEAD', …)`

DB code needs WordPress; it is verified on the staging site in Task 4, not in the PHP script.

- [ ] **Step 1: Extend the leads table**

In `ai_chatbot_activate()` replace the `$sql_leads` definition with (dbDelta adds the missing columns on existing installs):

```php
    $sql_leads = "CREATE TABLE $table_leads (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id varchar(64) DEFAULT '',
        name varchar(255) NOT NULL,
        email varchar(255) DEFAULT '',
        phone varchar(50) NOT NULL,
        grade varchar(100) DEFAULT '',
        major varchar(255) DEFAULT '',
        need varchar(255) DEFAULT '',
        note text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY session_id (session_id)
    ) $charset_collate;";
```

- [ ] **Step 2: Bump version and load the class**

`ai-chatbot.php` header: ` * Version: 2.3.0`; constant: `define('AI_CHATBOT_VERSION', '2.3.0');`. After line 131 add:

```php
require_once AI_CHATBOT_PATH . 'includes/class-lead-flow.php';
```

- [ ] **Step 3: Add DB methods to `AI_Chatbot_Lead_Flow`**

```php
    public static function get_lead($session_id)
    {
        if ($session_id === '') {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, phone, grade, major, need FROM {$wpdb->prefix}ai_chatbot_leads WHERE session_id = %s ORDER BY id DESC LIMIT 1",
            $session_id
        ), ARRAY_A);
        return $row ? $row : null;
    }

    public static function save_lead($session_id, $lead)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_chatbot_leads';
        $row = array(
            'session_id' => $session_id,
            'name' => $lead['name'],
            'phone' => $lead['phone'],
            'grade' => $lead['grade'],
            'major' => $lead['major'],
            'need' => $lead['need'],
        );

        if (!empty($lead['id'])) {
            $id = (int) $lead['id'];
            $wpdb->update($table, $row, array('id' => $id));
        } else {
            $row['email'] = 'Chưa cung cấp (Từ Chat)';
            $wpdb->insert($table, $row);
            $id = (int) $wpdb->insert_id;
        }

        $wpdb->update(
            $wpdb->prefix . 'ai_chatbot_conversations',
            array('lead_id' => $id, 'lead_name' => self::label($lead)),
            array('session_id' => $session_id)
        );

        $lead['id'] = $id;
        return $lead;
    }

    public static function webhook_data($lead)
    {
        return array(
            'lead_name' => self::label($lead), // kept for the existing Apps Script
            'name' => $lead['name'],
            'phone' => $lead['phone'],
            'grade' => $lead['grade'],
            'major' => $lead['major'],
            'need' => $lead['need'],
        );
    }

    private static function label($lead)
    {
        return ($lead['name'] !== '' ? $lead['name'] : 'Học sinh (Qua Chat)') . ' - ' . $lead['phone'];
    }
```

- [ ] **Step 4: Run unit tests (class must still load without WP)**

Run: `php tests/test-lead-flow.php`
Expected: `ALL PASSED`.

- [ ] **Step 5: Commit**

```bash
git add ai-chatbot.php includes/class-lead-flow.php
git commit -m "feat: store lead grade/major/need per session, bump to 2.3.0

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Wire the flow into `handle_chat_query()`

**Files:**
- Modify: `ai-chatbot.php` — `handle_chat_query()` (PHASE 9.5 block ~291-375, cache key ~380, client setup ~425-432, prompt assembly ~486-498, eval JSON ~515)

**Interfaces:**
- Consumes: everything from Tasks 1–3; `OpenRouter_API_Client::generate_chat_answer($message, $system_instruction, $history = array())` (`includes/class-openrouter-client.php:153`, returns `string|WP_Error`); `$this->send_to_google_sheets_webhook($type, $session_id, $data)`.
- Produces: eval-mode JSON gains `lead` (`array|null`), consumed by Task 6.

- [ ] **Step 1: Move client construction above lead capture**

Cut these lines from the "1. Reformulate Query" section and paste them immediately before the `// PHASE 9.5` comment banner:

```php
        $chat_model = get_option('ai_chatbot_openrouter_model');
        if (empty($chat_model))
            $chat_model = 'google/gemini-2.5-flash';
        $embed_model = get_option('ai_chatbot_openrouter_embed_model');
        if (empty($embed_model))
            $embed_model = 'qwen/qwen3-embedding-8b';
        $client = new OpenRouter_API_Client($api_key, $chat_model, $embed_model);
```

- [ ] **Step 2: Replace the PHASE 9.5 block**

Replace everything from `$is_lead_capture = false;` through the closing `}` of `if (preg_match($phone_regex, …)) { … }` (the block ending just before `// PHASE 10: Cache Check`) with:

```php
        $lead = AI_Chatbot_Lead_Flow::get_lead($session_id);
        $phone_hit = AI_Chatbot_Lead_Flow::detect_phone($message);
        $is_lead_capture = false;

        // Extract only when a phone arrives, or when a short reply may carry the name an earlier capture missed.
        $may_fill_name = $lead && $lead['name'] === '' && mb_strlen($message, 'UTF-8') <= 60;
        if ($session_id !== '' && ($phone_hit || $may_fill_name)) {
            $raw = $client->generate_chat_answer(
                AI_Chatbot_Lead_Flow::build_transcript($history, $message),
                AI_Chatbot_Lead_Flow::EXTRACTION_PROMPT
            );
            $extracted = AI_Chatbot_Lead_Flow::parse_extraction(is_wp_error($raw) ? '' : $raw);

            if ($phone_hit) {
                $extracted['phone'] = $phone_hit['phone'];
                if ($extracted['name'] === '') {
                    $extracted['name'] = AI_Chatbot_Lead_Flow::fallback_name($message, $phone_hit['raw']);
                }
            }

            $merged = AI_Chatbot_Lead_Flow::merge_lead($lead ? $lead : array(), $extracted);
            // Loose != on purpose: ignores key order and "5" vs 5 between DB row and merged array.
            if ($merged['phone'] !== '' && $merged != $lead) {
                $lead = AI_Chatbot_Lead_Flow::save_lead($session_id, $merged);
                $this->send_to_google_sheets_webhook('LEAD', $session_id, AI_Chatbot_Lead_Flow::webhook_data($lead));
            }
            $is_lead_capture = (bool) $phone_hit;
        }
```

This removes the old name-regex lines (now living in `fallback_name()`), the old `$wpdb->insert` into leads and the old webhook call. Also delete the now-unused `$captured_name`, `$captured_phone`, `$phone_regex` declarations above it.

- [ ] **Step 3: Include lead state in the cache key**

```php
        $cache_key = 'ai_chat_' . md5(strtolower(trim($message)) . serialize($history) . ($lead ? 'lead' : ''));
```

- [ ] **Step 4: Append flow rules and capture note to the prompt**

Replace:

```php
        $context_text = $system_prompt;

        if ($is_lead_capture) {
            $context_text .= "\n\n[HỆ THỐNG GHI NHẬN THÔNG TIN LIÊN HỆ BẮT BUỘC]: …";
        }
```

with:

```php
        $context_text = $system_prompt . AI_Chatbot_Lead_Flow::flow_rules($lead);

        if ($is_lead_capture && $lead) {
            $context_text .= AI_Chatbot_Lead_Flow::capture_note($lead);
        }
```

- [ ] **Step 5: Return the lead in eval mode**

In the eval-mode `wp_send_json_success(array(…))`, add `'lead' => $lead,` after `'contexts' => $raw_contexts,`.

- [ ] **Step 6: Lint and unit-test**

Run: `php -l ai-chatbot.php && php -l includes/class-lead-flow.php && php tests/test-lead-flow.php`
Expected: `No syntax errors detected` twice, then `ALL PASSED`.

- [ ] **Step 7: Deploy to staging and verify by hand**

Run `python package_plugin.py`, upload `hateco-chatbot-updated.zip` to the staging site (Plugins → Add New → Upload, replace current). In a private browser window on the site, chat:
1. `Trường có những ngành nào?` → answer lists majors, no phone request.
2. `Em lớp 12, muốn đăng ký ngành Kế toán thì cần hồ sơ gì ạ?` → answers documents, then invites name + phone.
3. `Em là Test Một, sđt 0912 345 678` → thanks, confirms staff will call 0912345678, asks grade/major only if unknown.
4. `À nhầm, số đúng là 0987654321` → still one row; in phpMyAdmin/Adminer `SELECT * FROM wp_ai_chatbot_leads WHERE session_id <> '' ORDER BY id DESC LIMIT 3;` shows a single row with phone `0987654321`, name `Test Một`, grade `Lớp 12`, major containing `Kế toán`.
5. `Học phí ngành này bao nhiêu?` → answers, does NOT ask for phone again.
Delete the test lead afterwards (Task 5 adds the UI; until then via SQL).

- [ ] **Step 8: Commit**

```bash
git add ai-chatbot.php
git commit -m "feat: qualify intent and capture lead details in chat flow

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Admin "Khách hàng (Leads)" tab

**Files:**
- Modify: `includes/class-admin-settings.php:416-418` (tab links), `:666` (before `<?php endif; ?>` of the tab switch), `:843` (colspan)

**Interfaces:**
- Consumes: leads table columns from Task 3; existing `.btn-delete-lead` JS handler and `handle_lead_deletion()` (unchanged).

- [ ] **Step 1: Add the tab link** after the `tab=kb` link:

```php
                <a href="?page=ai-chatbot&tab=leads"
                    class="ai_chatbot-tab-link <?php echo 'leads' === $active_tab ? 'active' : ''; ?>">Khách hàng (Leads)</a>
```

- [ ] **Step 2: Render the table** — insert immediately before the `<?php endif; ?>` that closes the `settings`/`kb` switch (line ~666):

```php
            <?php elseif ('leads' === $active_tab): ?>
                <div class="ai_chatbot-card">
                    <h3 style="margin-top: 0; color: #0f172a; font-size: 18px;">Khách hàng để lại thông tin tư vấn</h3>
                    <div style="overflow-x: auto; margin-top: 15px;">
                        <table class="ai_chatbot-table" id="ai_chatbot-leads-table">
                            <thead>
                                <tr>
                                    <th>Họ và tên</th>
                                    <th>Số điện thoại</th>
                                    <th>Lớp hiện tại</th>
                                    <th>Ngành quan tâm</th>
                                    <th>Nhu cầu tuyển sinh</th>
                                    <th>Ghi chú</th>
                                    <th>Thời gian ghi nhận</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                global $wpdb;
                                $table_leads = $wpdb->prefix . 'ai_chatbot_leads';
                                // ponytail: newest 500 only, add pagination when staff need older leads
                                $leads = $wpdb->get_results("SELECT * FROM $table_leads ORDER BY id DESC LIMIT 500", ARRAY_A);

                                if (empty($leads)): ?>
                                    <tr class="no-leads-row">
                                        <td colspan="8" style="text-align: center; color: #94a3b8; padding: 30px;">Chưa có khách hàng nào đăng ký thông tin tư vấn.</td>
                                    </tr>
                                <?php else:
                                    foreach ($leads as $lead): ?>
                                        <tr>
                                            <td style="font-weight: 500;"><?php echo esc_html($lead['name'] !== '' ? $lead['name'] : '(chưa rõ)'); ?></td>
                                            <td><a href="tel:<?php echo esc_attr($lead['phone']); ?>"><?php echo esc_html($lead['phone']); ?></a></td>
                                            <td><?php echo esc_html($lead['grade']); ?></td>
                                            <td><?php echo esc_html($lead['major']); ?></td>
                                            <td><?php echo esc_html($lead['need']); ?></td>
                                            <td style="color: #64748b; font-size: 13px;"><?php echo esc_html($lead['note']); ?></td>
                                            <td style="color: #64748b; font-size: 13px;"><?php echo esc_html(date('d-m-Y H:i', strtotime($lead['created_at']))); ?></td>
                                            <td>
                                                <button class="btn-delete btn-delete-lead" data-lead-id="<?php echo esc_attr($lead['id']); ?>">
                                                    <span class="dashicons dashicons-trash" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px;"></span> Xóa
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
```

- [ ] **Step 3: Fix the empty-state colspan** in the delete JS (line ~843): `colspan="5"` → `colspan="8"`.

- [ ] **Step 4: Lint**

Run: `php -l includes/class-admin-settings.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Verify on staging**

Repackage, upload, open WP Admin → Hateco AI Chatbot → **Khách hàng (Leads)**. Expected: the Task 4 test lead shows name, phone (clickable `tel:`), grade, major, need. Click **Xóa** → confirm → row fades out; when the last row goes the empty-state row spans the full table width. Old leads (pre-2.3.0) still display with blank grade/major/need.

- [ ] **Step 6: Commit**

```bash
git add includes/class-admin-settings.php
git commit -m "feat: add admin leads tab with grade, major and admissions need

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Live multi-turn lead-flow check

**Files:**
- Create: `eval/run_lead_flow_check.py`

**Interfaces:**
- Consumes: eval-mode JSON `{answer, contexts, lead}` from Task 4; AJAX actions `ai_chatbot_save_message`, `ai_chatbot_chat_query`.

Answers are LLM output, so checks are keyword heuristics; a FAIL means "read the printed answer", not necessarily a bug.

- [ ] **Step 1: Write the script**

```python
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
```

- [ ] **Step 2: Run against staging**

Run: `.venv/Scripts/python eval/run_lead_flow_check.py`
Expected: all `PASS`, `0 failure(s)`. For any FAIL read the printed answer; if the bot behavior is wrong, tune the wording in `flow_rules()` / `capture_note()` (Task 2), re-run `php tests/test-lead-flow.php`, redeploy, re-run this script.

- [ ] **Step 3: Clean up and commit**

Delete the "Test Một" lead in the admin Leads tab.

```bash
git add eval/run_lead_flow_check.py
git commit -m "test: add live multi-turn lead flow check

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Google Sheets webhook (Apps Script) for admissions staff

Added 2026-10-10 at the user's request: staff work from the Google Sheet, so the repo ships the Apps Script that receives the plugin's webhook.

**Files:**
- Create: `google-sheets/hateco-webhook.gs`
- Create: `tests/test-sheets-webhook.js`
- Modify: `package_plugin.py:6` (also exclude `google-sheets`)

**Interfaces:**
- Consumes: the plugin's webhook POST body (from `send_to_google_sheets_webhook`, `ai-chatbot.php`): JSON `{"type": "LEAD"|"MESSAGE", "session_id": "...", "data": {...}}`.
  - `LEAD` data (Task 3 `webhook_data` + `history` added by the plugin): `lead_name, name, phone, grade, major, need, history`. Older plugin versions send only `lead_name` (`"Tên - 0912345678"`) and `history`.
  - `MESSAGE` data: `role` (`user`|`bot`), `content` (may contain HTML), `chat_type` (`ai`|`human`), `lead_name`.
- Produces (global functions, Apps Script V8 runtime, plain JS so Node can load it):
  - `doPost(e)` → JSON `{ok: true}` / `{ok: false, error}`
  - `leadFields(data)` → `{name, phone, grade, major, need, history}`
  - `upsertLead(sheet, sessionId, data, now)` → `'inserted'|'updated'`
  - `messageRow(sessionId, data, now)` → array matching `MESSAGE_HEADERS`

Sheet behavior: one row per chat session in sheet **Leads** (upsert on Session ID; a later LEAD for the same session updates it in place, never wipes a filled cell with an empty value, and never touches the 3 staff columns). Every chat message is appended to sheet **Tin nhắn**. Phone numbers are stored as text so the leading 0 survives.

- [ ] **Step 1: Write the failing test**

Create `tests/test-sheets-webhook.js`:

```js
// Run: node tests/test-sheets-webhook.js
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const ctx = {};
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(__dirname + '/../google-sheets/hateco-webhook.gs', 'utf8'), ctx);

// Minimal fake of the Apps Script Sheet API used by upsertLead.
function fakeSheet(rows) {
  return {
    rows,
    getDataRange() { return { getValues: () => rows.map(r => r.slice()) }; },
    getRange(row, col, numRows, numCols) {
      return { setValues(values) { for (let c = 0; c < numCols; c++) rows[row - 1][col - 1 + c] = values[0][c]; } };
    },
    // Array.from: rows built inside the vm realm have a different Array prototype, which deepStrictEqual rejects.
    appendRow(r) { rows.push(Array.from(r)); },
  };
}

const t1 = new Date('2026-10-10T08:00:00Z');
const t2 = new Date('2026-10-10T09:00:00Z');
const sheet = fakeSheet([ctx.LEAD_HEADERS.slice()]);

// New lead -> appended with staff defaults, phone kept as text
assert.strictEqual(ctx.upsertLead(sheet, 's1', { name: 'Lan', phone: '0912345678', grade: 'Lớp 12', major: 'Kế toán', need: 'Muốn xét tuyển', history: '[Khách hàng] hi' }, t1), 'inserted');
let row = sheet.rows[1];
assert.strictEqual(row.length, ctx.LEAD_HEADERS.length);
assert.deepStrictEqual(row.slice(0, 9), [t1, t1, 's1', 'Lan', "'0912345678", 'Lớp 12', 'Kế toán', 'Muốn xét tuyển', '[Khách hàng] hi']);
assert.deepStrictEqual(row.slice(9), ['Chưa gọi', '', '']);
console.log('ok - new lead inserted');

// Staff edits status, then same session sends an update -> same row, created time + staff columns kept, empty values don't wipe
row[9] = 'Đã gọi';
row[11] = 'Hẹn thứ 2';
assert.strictEqual(ctx.upsertLead(sheet, 's1', { name: '', phone: '0987654321', grade: '', major: 'Kế toán', need: '', history: 'longer' }, t2), 'updated');
assert.strictEqual(sheet.rows.length, 2);
row = sheet.rows[1];
assert.deepStrictEqual(row, [t1, t2, 's1', 'Lan', "'0987654321", 'Lớp 12', 'Kế toán', 'Muốn xét tuyển', 'longer', 'Đã gọi', '', 'Hẹn thứ 2']);
console.log('ok - same session updated in place');

// Different session -> new row
assert.strictEqual(ctx.upsertLead(sheet, 's2', { phone: '0911111111' }, t2), 'inserted');
assert.strictEqual(sheet.rows.length, 3);
console.log('ok - other session inserted');

// Legacy payload (only lead_name "Tên - SĐT")
assert.deepStrictEqual(JSON.parse(JSON.stringify(ctx.leadFields({ lead_name: 'Trung - 0985282393', history: 'h' }))),
  { name: 'Trung', phone: '0985282393', grade: '', major: '', need: '', history: 'h' });
assert.strictEqual(ctx.leadFields({ lead_name: 'Học sinh (Qua Chat) - 0985282393' }).name, '');
console.log('ok - legacy lead_name parsed');

// Huge history is truncated under the 50,000-char cell limit
assert.ok(ctx.leadFields({ phone: '0912345678', history: 'x'.repeat(60000) }).history.length <= 49000);
console.log('ok - history truncated');

// messageRow
assert.deepStrictEqual(Array.from(ctx.messageRow('s1', { role: 'bot', content: '<p>Chào <b>bạn</b></p>', chat_type: 'ai', lead_name: 'Lan - 0912345678' }, t1)),
  [t1, 's1', 'Lan - 0912345678', 'Chatbot', 'ai', 'Chào bạn']);
assert.strictEqual(ctx.messageRow('s1', { role: 'user', content: 'hi', chat_type: 'ai' }, t1)[3], 'Khách hàng');
assert.strictEqual(ctx.messageRow('s1', { role: 'bot', content: 'hi', chat_type: 'human' }, t1)[3], 'Nhân viên');
console.log('ok - message rows');

console.log('ALL PASSED');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/test-sheets-webhook.js`
Expected: `ENOENT: no such file or directory` for `google-sheets/hateco-webhook.gs`.

- [ ] **Step 3: Write the script**

Create `google-sheets/hateco-webhook.gs`:

```js
/**
 * Hateco AI Chatbot -> Google Sheets webhook.
 *
 * Cài đặt:
 * 1. Mở Google Sheet của tổ tư vấn > Tiện ích mở rộng > Apps Script, dán toàn bộ file này, Lưu.
 * 2. Triển khai > Tùy chọn triển khai mới > Ứng dụng web. Thực thi với tư cách: Tôi. Ai có quyền truy cập: Bất kỳ ai.
 * 3. Copy URL kết thúc bằng /exec, dán vào ô "Google Sheets Webhook" trong trang cài đặt plugin.
 * Sửa script sau này: Triển khai > Quản lý bản triển khai > sửa > Phiên bản mới (URL giữ nguyên).
 *
 * Sheet "Leads": mỗi phiên chat 1 dòng. 3 cột cuối dành cho nhân viên, script không bao giờ ghi đè.
 * Sheet "Tin nhắn": nhật ký toàn bộ tin nhắn.
 */

var LEAD_SHEET = 'Leads';
var MESSAGE_SHEET = 'Tin nhắn';
var LEAD_HEADERS = ['Thời gian ghi nhận', 'Cập nhật lần cuối', 'Session ID', 'Họ và tên', 'Số điện thoại', 'Lớp hiện tại',
  'Ngành quan tâm', 'Nhu cầu tuyển sinh', 'Lịch sử trò chuyện', 'Trạng thái', 'Nhân viên phụ trách', 'Ghi chú tư vấn'];
var STAFF_COLUMNS = 3;
var STATUS_OPTIONS = ['Chưa gọi', 'Đã gọi', 'Không liên lạc được', 'Hẹn gọi lại', 'Đã nộp hồ sơ'];
var MESSAGE_HEADERS = ['Thời gian', 'Session ID', 'Khách hàng', 'Người gửi', 'Chế độ', 'Nội dung'];
var MAX_CELL = 49000; // Sheets cell limit is 50,000 chars

function doPost(e) {
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var payload = JSON.parse(e.postData.contents);
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var now = new Date();
    if (payload.type === 'LEAD') {
      upsertLead(getSheet(ss, LEAD_SHEET, LEAD_HEADERS), payload.session_id || '', payload.data || {}, now);
    } else if (payload.type === 'MESSAGE') {
      getSheet(ss, MESSAGE_SHEET, MESSAGE_HEADERS).appendRow(messageRow(payload.session_id || '', payload.data || {}, now));
    }
    return json({ ok: true });
  } catch (err) {
    return json({ ok: false, error: String(err) });
  } finally {
    lock.releaseLock();
  }
}

function leadFields(data) {
  var name = data.name || '';
  var phone = data.phone || '';
  // Older plugin versions only send lead_name = "Tên - SĐT"
  if (!phone && data.lead_name) {
    var m = String(data.lead_name).match(/^(.*?)\s*-\s*(\+?\d[\d\s.]*)$/);
    if (m) {
      name = name || m[1];
      phone = m[2].replace(/[^\d+]/g, '');
    }
  }
  if (name === 'Học sinh (Qua Chat)') name = '';
  return {
    name: String(name).trim(),
    phone: String(phone).trim(),
    grade: String(data.grade || '').trim(),
    major: String(data.major || '').trim(),
    need: String(data.need || '').trim(),
    history: String(data.history || '').slice(-MAX_CELL),
  };
}

function upsertLead(sheet, sessionId, data, now) {
  var f = leadFields(data);
  // Leading ' keeps the phone as text so the leading 0 is not dropped.
  var fresh = [f.name, f.phone ? "'" + f.phone : '', f.grade, f.major, f.need, f.history];
  var values = sheet.getDataRange().getValues();

  for (var i = 1; i < values.length; i++) {
    if (sessionId && values[i][2] === sessionId) {
      var old = values[i].slice(3, 9);
      var merged = fresh.map(function (v, k) { return v !== '' ? v : old[k]; });
      sheet.getRange(i + 1, 2, 1, 8).setValues([[now, sessionId].concat(merged)]);
      return 'updated';
    }
  }

  sheet.appendRow([now, now, sessionId].concat(fresh, [STATUS_OPTIONS[0], '', '']));
  return 'inserted';
}

function messageRow(sessionId, data, now) {
  var sender = data.role === 'user' ? 'Khách hàng' : (data.chat_type === 'human' ? 'Nhân viên' : 'Chatbot');
  var text = String(data.content || '').replace(/<[^>]+>/g, '').trim().slice(0, MAX_CELL);
  return [now, sessionId, data.lead_name || '', sender, data.chat_type || 'ai', text];
}

function getSheet(ss, name, headers) {
  var sheet = ss.getSheetByName(name);
  if (sheet) return sheet;
  sheet = ss.insertSheet(name);
  sheet.appendRow(headers);
  sheet.setFrozenRows(1);
  sheet.getRange(1, 1, 1, headers.length).setFontWeight('bold');
  if (name === LEAD_SHEET) {
    var statusCol = headers.length - STAFF_COLUMNS + 1;
    sheet.getRange(2, statusCol, sheet.getMaxRows() - 1, 1).setDataValidation(
      SpreadsheetApp.newDataValidation().requireValueInList(STATUS_OPTIONS, true).build()
    );
  }
  return sheet;
}

function json(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
```

Also edit `package_plugin.py` line 6 so the script is not shipped inside the WordPress zip:

```python
    exclude_dirs = {'.venv', 'eval', '.git', '.codegraph', '.vscode', '__pycache__', 'tests', 'docs', 'google-sheets'}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node tests/test-sheets-webhook.js`
Expected: five `ok - …` lines, then `ALL PASSED`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add google-sheets/hateco-webhook.gs tests/test-sheets-webhook.js package_plugin.py
git commit -m "feat: add Google Sheets Apps Script webhook for admissions staff

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Deployment (by the user, not automatable here): follow the header comment of the `.gs` file, paste the `/exec` URL into the plugin setting, send a test chat with a phone number, confirm a row appears in **Leads**.

---

## Out of scope (say so at review)

- Live human-agent chat inside the widget (no UI exists; `ai_chatbot_switch_mode` is unused by the frontend). Staff work from the Google Sheet (Task 7).
- The webhook URL has no shared secret: anyone who learns the `/exec` URL can append rows. Add a token check if that becomes a problem.
- Moving the hardcoded system prompt back into admin settings.
