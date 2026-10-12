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
        if (strpos($clean_msg, '?') !== false || count(preg_split('/\s+/u', $clean_msg)) > 4) {
            return '';
        }
        return mb_strlen($clean_msg, 'UTF-8') >= 2 ? $clean_msg : '';
    }

    public static function flow_rules($lead)
    {
        if ($lead) {
            return "\n\nTHÔNG TIN LIÊN HỆ:\n8. Hệ thống ĐÃ ghi nhận số điện thoại của người dùng này, tổ tư vấn tuyển sinh sẽ gọi lại. TUYỆT ĐỐI KHÔNG xin lại số điện thoại; chỉ hỏi họ tên khi hệ thống yêu cầu bên dưới. Với câu hỏi không có trong [KHO TRI THỨC], trả lời: \"Phần này thầy cô tư vấn sẽ giải đáp chi tiết khi gọi cho em nhé.\" (thay cho quy tắc 7).";
        }

        return "\n\nQUY TRÌNH TƯ VẤN & THU THẬP THÔNG TIN:
8. Luôn trả lời đầy đủ câu hỏi của người dùng TRƯỚC, sau đó mới tính đến việc xin thông tin.
9. Tự đánh giá nhu cầu của người dùng qua toàn bộ cuộc trò chuyện:
   - TÌM HIỂU CHUNG (hỏi chung về trường, danh sách ngành, chưa nói rõ mong muốn của bản thân): tiếp tục tư vấn bình thường, KHÔNG xin số điện thoại.
   - CÓ NHU CẦU RÕ RÀNG (muốn đăng ký/xét tuyển/nộp hồ sơ; hỏi học phí, thủ tục hoặc hạn nộp của một ngành cụ thể; cho biết đang học lớp 12 hoặc đã tốt nghiệp và hỏi về nhập học; phụ huynh hỏi cho con một ngành cụ thể; muốn được gọi tư vấn hoặc gặp tư vấn viên): sau khi trả lời, mời khéo léo để lại HỌ TÊN và SỐ ĐIỆN THOẠI để thầy cô tổ tư vấn tuyển sinh gọi điện tư vấn kỹ hơn và hỗ trợ hồ sơ.
10. Lời mời chỉ 1 câu ngắn, tự nhiên, nêu lợi ích. Nếu người dùng từ chối hoặc lờ đi lời mời, KHÔNG nhắc lại ở các lượt sau, trừ khi họ thể hiện nhu cầu rõ ràng hơn trước.
11. Nếu người dùng muốn nói chuyện với người thật, mời họ để lại họ tên và số điện thoại để tư vấn viên gọi lại.";
    }

    public static function capture_note($lead)
    {
        if ($lead['name'] === '') {
            $extra = 'hỏi xin họ tên để thầy cô tiện xưng hô khi gọi';
        } elseif ($lead['grade'] === '' || $lead['major'] === '') {
            $extra = 'hỏi nhẹ nhàng em đang học lớp mấy và quan tâm ngành nào (chỉ hỏi phần chưa biết) để thầy cô chuẩn bị tư vấn';
        } else {
            $extra = 'không hỏi thêm thông tin cá nhân';
        }
        $who = $lead['name'] !== '' ? ' của em ' . $lead['name'] : '';

        return "\n\n[HỆ THỐNG ĐÃ LƯU THÔNG TIN LIÊN HỆ{$who}: số điện thoại {$lead['phone']}]. Nếu tin nhắn có kèm câu hỏi thì trả lời câu hỏi đó trước. Sau đó: (1) cảm ơn chân thành, (2) xác nhận đã ghi nhận thông tin và thầy cô tổ tư vấn tuyển sinh Trường Cao đẳng Kinh tế Công nghệ Hà Nội (Hateco) sẽ sớm gọi điện qua số {$lead['phone']}, (3) {$extra}, (4) hỏi em còn thắc mắc gì không.";
    }

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
}
