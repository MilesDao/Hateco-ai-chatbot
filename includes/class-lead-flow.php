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
        // 1. Remove phone number and any associated phone labels (e.g., "số điện thoại là", "sđt của mình là")
        $phone_escaped = preg_quote($raw_phone, '/');
        $phone_label_regex = '/(?:(?:[,\-\s:;]\s*)|^)(?:(?:s[\s\.\-_]*đ[\s\.\-_]*t|s[\s\.\-_]*d[\s\.\-_]*t|số\s*(?:đ|điện)?\s*(?:t|thoại)?|liên\s*hệ|cung\s*cấp)(?:\s+(?:của|qua|tại)?\s*(?:mình|em|anh|chị|tớ|bạn)?)?\s*(?:là|:|—|-|\s)*)*' . $phone_escaped . '(?:(?:\s+(?:ạ|nhá|nhé|nha|ah|đấy|nhỉ|nhắc)(?:\s+(?:ạ|ad|admin|anh|chị|em|bạn))?)+|(?=[,\-\.\s:;]|$))/iu';
        $clean_msg = preg_replace($phone_label_regex, ' ', $message);
        $clean_msg = str_replace($raw_phone, ' ', $clean_msg);

        // 2. Trim common leading polite words and symbols (Dạ, Vâng, Thưa, ạ, ơi, -, ,, :, ...)
        $leading_polite_regex = '/^\s*(?:dạ|vâng|thưa|xin\s+cháo|xin\s+chào|ơi|à|ờ|ừ|ạ|nhé|nha|nhá|[,\-:;—_\.\/]+)+\s*/iu';
        while (preg_match($leading_polite_regex, $clean_msg)) {
            $clean_msg = preg_replace($leading_polite_regex, '', $clean_msg);
        }

        // 3. Trim common trailing conversational particles & symbols
        $trailing_polite_regex = '/\s+(?:nhé\s*(?:ạ|ad|admin|anh|chị|em|bạn)?|nha\s*(?:ạ|ad|admin|anh|chị|em|bạn)?|nhá\s*(?:ạ|ad|admin|anh|chị|em|bạn)?|nhỉ|đây\s+ạ|nhé|nha|nhá|ạ|ah|a|nhở|với\s+ạ|với|luôn\s+nhé|luôn)[,\-\s:;—_\.\!]*$/iu';
        while (preg_match($trailing_polite_regex, $clean_msg)) {
            $clean_msg = preg_replace($trailing_polite_regex, '', $clean_msg);
        }

        // Trim lingering symbols at start and end
        $clean_msg = preg_replace('/^[,\-:;—_\.\/\s]+|[,\-:;—_\.\/\s]+$/u', '', $clean_msg);

        // 4. Check if what remains starts with an introduction pattern (mình tên là, em là, tên là, tên, họ tên...)
        $intro_regex = '/^(?:(?:tên\s+)?(?:của\s+)?(?:mình|em|tớ|anh|chị|bạn|học\s*sinh)(?:\s+tên)?\s*(?:là|:|\s)|(?:mình|em|tớ|anh|chị|bạn)\s+tên\s*(?:là|:)?|tên\s*(?:của\s*)?(?:mình|em|tớ|anh|chị|bạn)?\s*(?:là|:|\s)|họ\s*(?:và)?\s*tên\s*(?:là|:)?|là\s+)\s*/iu';
        $clean_msg = preg_replace($intro_regex, '', $clean_msg, 1);

        // One last pass to remove any residual trailing particles from the extracted name itself
        while (preg_match($trailing_polite_regex, $clean_msg)) {
            $clean_msg = preg_replace($trailing_polite_regex, '', $clean_msg);
        }

        $clean_msg = trim(preg_replace('/\s+/u', ' ', $clean_msg));
        $clean_msg = preg_replace('/^[,\-:;—_\.\/\s]+|[,\-:;—_\.\/\s]+$/u', '', $clean_msg);
        return mb_strlen($clean_msg, 'UTF-8') >= 2 ? $clean_msg : '';
    }
}
