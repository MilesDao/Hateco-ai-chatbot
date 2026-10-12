<?php
/**
 * FAQ store: import from Excel/CSV and look up a ready-made answer before RAG/LLM.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Chatbot_FAQ
{
    // Dice score on keyword sets; 0.6 = about 2 of 3 keywords shared.
    const THRESHOLD = 0.6;
    const STOPWORDS = array('la', 'co', 'khong', 'nhu', 'the', 'nao', 'va', 'cua', 'o', 'a', 'nhe', 'vay', 've', 'cho', 'em', 'oi');
    const ACCENTS = array(
        'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ',
        'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
    );

    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'ai_chatbot_faqs';
    }

    /** lowercase, strip Vietnamese accents, keep only [a-z0-9] words. */
    public static function normalize($text)
    {
        $text = mb_strtolower($text, 'UTF-8');
        $map = array();
        foreach (self::ACCENTS as $plain => $chars) {
            foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                $map[$ch] = $plain;
            }
        }
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtr($text, $map)));
    }

    public static function keywords($text)
    {
        $words = explode(' ', self::normalize($text));
        return array_values(array_unique(array_diff(array_filter($words), self::STOPWORDS)));
    }

    public static function score($a, $b)
    {
        if (!$a || !$b) {
            return 0.0;
        }
        return 2 * count(array_intersect($a, $b)) / (count($a) + count($b));
    }

    /** Best row of $faqs (each with 'question','answer') for $message, or null below THRESHOLD. */
    public static function best_match($message, array $faqs)
    {
        $q = self::keywords($message);
        $best = null;
        $best_score = 0.0;
        foreach ($faqs as $faq) {
            $s = self::score($q, self::keywords($faq['question']));
            if ($s > $best_score) {
                $best_score = $s;
                $best = $faq;
            }
        }
        return $best_score >= self::THRESHOLD ? $best : null;
    }

    // ponytail: loads every FAQ per message (fine for a few hundred rows); cache or index if the list grows.
    public static function find($message)
    {
        global $wpdb;
        $table = self::table();
        $faqs = $wpdb->get_results("SELECT question, answer FROM $table", ARRAY_A);
        return $faqs ? self::best_match($message, $faqs) : null;
    }

    /** Replace all FAQs with the rows of an .xlsx/.csv file (col A = question, col B = answer). Returns count or WP_Error. */
    public static function import($path, $ext)
    {
        $rows = 'csv' === $ext ? self::read_csv($path) : self::read_xlsx($path);
        if (is_wp_error($rows)) {
            return $rows;
        }

        $items = array();
        foreach ($rows as $i => $r) {
            $q = isset($r[0]) ? trim($r[0]) : '';
            $a = isset($r[1]) ? trim($r[1]) : '';
            if ($q === '' || $a === '') {
                continue;
            }
            if (0 === count($items) && in_array(self::normalize($q), array('cau hoi', 'question'), true)) {
                continue; // header row
            }
            $items[] = array('question' => $q, 'answer' => $a);
        }
        if (!$items) {
            return new WP_Error('faq_empty', 'Không tìm thấy dòng nào có đủ cột A (Câu hỏi) và cột B (Câu trả lời).');
        }

        global $wpdb;
        $table = self::table();
        $wpdb->query("DELETE FROM $table");
        foreach ($items as $item) {
            $wpdb->insert($table, $item, array('%s', '%s'));
        }
        return count($items);
    }

    private static function read_csv($path)
    {
        $rows = array();
        $fh = fopen($path, 'r');
        if (!$fh) {
            return new WP_Error('faq_read', 'Không đọc được tệp.');
        }
        while (($r = fgetcsv($fh)) !== false) {
            $rows[] = $r;
        }
        fclose($fh);
        if ($rows && isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]); // Excel's UTF-8 BOM
        }
        return $rows;
    }

    private static function read_xlsx($path)
    {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('faq_zip', 'Máy chủ chưa bật PHP ZipArchive nên không đọc được .xlsx. Hãy lưu tệp dạng .csv (UTF-8) rồi tải lại.');
        }
        $zip = new ZipArchive();
        if (true !== $zip->open($path)) {
            return new WP_Error('faq_read', 'Tệp .xlsx không hợp lệ.');
        }
        $shared = $zip->getFromName('xl/sharedStrings.xml');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml'); // ponytail: first sheet only
        $zip->close();
        if (false === $sheet) {
            return new WP_Error('faq_read', 'Không tìm thấy trang tính đầu tiên trong tệp .xlsx.');
        }
        return self::parse_sheet($shared ? $shared : '', $sheet);
    }

    /** Pure XML -> rows (list of list of strings, column order kept). */
    public static function parse_sheet($shared_xml, $sheet_xml)
    {
        $text = function ($node) {
            $t = '';
            foreach ($node->xpath('.//*[local-name()="t"]') as $n) {
                $t .= (string) $n;
            }
            return $t;
        };

        $shared = array();
        if ($shared_xml !== '') {
            $x = simplexml_load_string($shared_xml);
            foreach ($x->si as $si) {
                $shared[] = $text($si);
            }
        }

        $s = simplexml_load_string($sheet_xml);
        $rows = array();
        foreach ($s->sheetData->row as $row) {
            $cells = array();
            foreach ($row->c as $c) {
                $type = (string) $c['t'];
                if ('s' === $type) {
                    $v = isset($shared[(int) $c->v]) ? $shared[(int) $c->v] : '';
                } elseif ('inlineStr' === $type) {
                    $v = $text($c->is);
                } else {
                    $v = (string) $c->v;
                }
                $letters = preg_replace('/[^A-Z]/', '', strtoupper((string) $c['r']));
                $col = 0;
                foreach (str_split($letters) as $ch) {
                    $col = $col * 26 + ord($ch) - 64;
                }
                $cells[$col - 1] = $v;
            }
            $rows[] = $cells;
        }
        return $rows;
    }
}
