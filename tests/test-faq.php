<?php
// Run: php tests/test-faq.php
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-faq.php';

function check($cond, $label)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "ok - $label\n";
}

$F = 'AI_Chatbot_FAQ';
$faqs = array(
    array('question' => 'Cách đăng ký xét tuyển như thế nào?', 'answer' => 'A1'),
    array('question' => 'Trường có địa chỉ ở đâu?', 'answer' => 'A3'),
    array('question' => 'Những ngành nào được giảm 70% học phí?', 'answer' => 'A4'),
    array('question' => 'Trường có đào tạo hệ từ xa không?', 'answer' => 'A5'),
    array('question' => 'Sau khi tốt nghiệp có liên thông lên đại học được không?', 'answer' => 'A10'),
);

check($F::normalize('Đăng Ký, Xét-Tuyển!') === 'dang ky xet tuyen', 'normalize strips accents and punctuation');
check($F::best_match('cách đăng ký xét tuyển như thế nào?', $faqs)['answer'] === 'A1', 'exact question');
check($F::best_match('dang ky xet tuyen the nao', $faqs)['answer'] === 'A1', 'no diacritics, reworded');
check($F::best_match('Trường ở đâu vậy ạ', $faqs)['answer'] === 'A3', 'short paraphrase');
check($F::best_match('ngành nào giảm 70% học phí', $faqs)['answer'] === 'A4', 'keyword paraphrase');
check($F::best_match('học phí', $faqs) === null, 'one generic keyword does not match');
check($F::best_match('xin chào', $faqs) === null, 'greeting does not match');
check($F::best_match('học phí ngành kế toán bao nhiêu', $faqs) === null, 'unrelated question falls through to RAG');

// embedding matcher (toy 2-d vectors)
$vfaqs = array(
    array('question' => 'Q5', 'answer' => 'A5', 'embedding' => array(1.0, 0.0)),
    array('question' => 'Q6', 'answer' => 'A6', 'embedding' => array(0.0, 1.0)),
    array('question' => 'Q7', 'answer' => 'A7', 'embedding' => null),
);
check($F::best_by_vector(array(0.1, 0.99), $vfaqs)['answer'] === 'A6', 'embedding picks the nearest FAQ');
check($F::best_by_vector(array(1.0, 1.0), $vfaqs) === null && $F::$last_score > 0.7, 'below threshold: miss, score still reported');
check(abs($F::cosine(array(1, 2, 3), array(2, 4, 6)) - 1.0) < 1e-9, 'cosine of parallel vectors');

// parse_sheet: shared strings, inline strings, skipped columns, rich text
$shared = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Câu hỏi</t></si><si><r><t>Trả </t></r><r><t>lời</t></r></si></sst>';
$sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
    . '<row r="2"><c r="A2" t="inlineStr"><is><t>Q?</t></is></c><c r="C2"><v>5</v></c></row></sheetData></worksheet>';
$rows = $F::parse_sheet($shared, $sheet);
check($rows[0][0] === 'Câu hỏi' && $rows[0][1] === 'Trả lời', 'shared strings incl. rich text');
check($rows[1][0] === 'Q?' && $rows[1][2] === '5' && !isset($rows[1][1]), 'inline string and column gap kept');

echo "ALL PASSED\n";
