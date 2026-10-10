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

check($F::fallback_name('em muốn đăng ký ngành kế toán, sđt 0985282393', '0985282393') === '', 'fallback rejects long sentence');
check($F::fallback_name('học phí ngành CNTT bao nhiêu ạ? 0912345678', '0912345678') === '', 'fallback rejects question');

echo "ALL PASSED\n";
