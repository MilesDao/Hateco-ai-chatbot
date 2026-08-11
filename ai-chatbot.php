<?php
/**
 * Plugin Name: Hateco AI Chatbot
 * Plugin URI: https://github.com/google-deepmind/ai-chatbot
 * Description: A self-contained Hateco AI Chatbot with Retrieval-Augmented Generation (RAG) powered by the Gemini API. Index local files in WordPress MySQL and query them in real-time using a beautiful glassmorphic floating widget.
 * Version: 2.2.0
 * Author: Dao Trung
 * Text Domain: ai-chatbot
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define Constants
define('AI_CHATBOT_PATH', plugin_dir_path(__FILE__));
define('AI_CHATBOT_URL', plugin_dir_url(__FILE__));
define('AI_CHATBOT_VERSION', '2.2.0');

/**
 * Custom activation routine to create database tables for documents and chunks.
 */
function ai_chatbot_activate()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // 1. Table for tracking uploaded documents
    $table_docs = $wpdb->prefix . 'ai_chatbot_documents';
    $sql_docs = "CREATE TABLE $table_docs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        file_type varchar(50) NOT NULL,
        file_size bigint(20) NOT NULL,
        chunk_count int(11) DEFAULT 0,
        status varchar(50) DEFAULT 'indexed',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_docs);

    // 2. Table for storing chunked text and 768-dim embeddings JSON
    $table_chunks = $wpdb->prefix . 'ai_chatbot_chunks';
    $sql_chunks = "CREATE TABLE $table_chunks (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        document_id bigint(20) NOT NULL,
        content longtext NOT NULL,
        parent_content longtext,
        embedding longtext NOT NULL,
        metadata longtext,
        token_count int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY document_id (document_id),
        FULLTEXT KEY idx_content (content)
    ) $charset_collate;";
    dbDelta($sql_chunks);

    // Ensure FULLTEXT index exists for older installations
    $index_check = $wpdb->get_results("SHOW INDEX FROM $table_chunks WHERE Key_name = 'idx_content'");
    if (empty($index_check)) {
        $wpdb->query("ALTER TABLE $table_chunks ADD FULLTEXT idx_content (content)");
    }

    // Ensure metadata column exists for v2.0
    $col_check = $wpdb->get_results("SHOW COLUMNS FROM $table_chunks LIKE 'metadata'");
    if (empty($col_check)) {
        $wpdb->query("ALTER TABLE $table_chunks ADD metadata longtext AFTER embedding");
    }

    // 3. Table for storing collected customer leads (Name, Email, Phone, Note)
    $table_leads = $wpdb->prefix . 'ai_chatbot_leads';
    $sql_leads = "CREATE TABLE $table_leads (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        email varchar(255) DEFAULT '',
        phone varchar(50) NOT NULL,
        note text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_leads);

    // Ensure note column exists for conversational lead capture on existing installations
    $col_note_check = $wpdb->get_results("SHOW COLUMNS FROM $table_leads LIKE 'note'");
    if (empty($col_note_check)) {
        $wpdb->query("ALTER TABLE $table_leads ADD note text AFTER phone");
    }
    // Ensure email allows default string when extracted from chat
    $wpdb->query("ALTER TABLE $table_leads MODIFY email varchar(255) DEFAULT ''");

    // 4. Table for storing conversation sessions
    $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
    $sql_conversations = "CREATE TABLE $table_conversations (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id varchar(64) NOT NULL,
        lead_id bigint(20) DEFAULT NULL,
        lead_name varchar(255) DEFAULT '',
        status varchar(20) DEFAULT 'bot',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY session_id (session_id),
        KEY lead_id (lead_id)
    ) $charset_collate;";
    dbDelta($sql_conversations);

    // 5. Table for storing individual chat messages
    $table_messages = $wpdb->prefix . 'ai_chatbot_messages';
    $sql_messages = "CREATE TABLE $table_messages (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        conversation_id bigint(20) NOT NULL,
        role varchar(16) NOT NULL,
        content longtext NOT NULL,
        chat_type varchar(10) DEFAULT 'ai',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY conversation_id (conversation_id)
    ) $charset_collate;";
    dbDelta($sql_messages);
}
register_activation_hook(__FILE__, 'ai_chatbot_activate');

/**
 * Load plugin dependencies.
 */
require_once AI_CHATBOT_PATH . 'includes/class-openrouter-client.php';
require_once AI_CHATBOT_PATH . 'includes/class-rag-manager.php';
require_once AI_CHATBOT_PATH . 'includes/class-admin-settings.php';

/**
 * Primary Plugin Bootstrap Class
 */
class AI_Chatbot_Chatbot
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Self-healing database check: Auto-create tables if database version is outdated
        if (is_admin()) {
            $db_version = get_option('ai_chatbot_db_version', '0');
            if (version_compare($db_version, AI_CHATBOT_VERSION, '<')) {
                ai_chatbot_activate();
                update_option('ai_chatbot_db_version', AI_CHATBOT_VERSION);
            }
        }

        // Enqueue Assets for Frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        // Render Chat Widget in Footer (For Floating Bubble - Option A)
        add_action('wp_footer', array($this, 'render_chat_widget'));

        // Shortcode support
        add_shortcode('ai_chatbot', array($this, 'render_chat_widget_shortcode'));

        // AJAX Handlers
        add_action('wp_ajax_ai_chatbot_chat_query', array($this, 'handle_chat_query'));
        add_action('wp_ajax_nopriv_ai_chatbot_chat_query', array($this, 'handle_chat_query'));

        add_action('wp_ajax_ai_chatbot_submit_lead', array($this, 'handle_submit_lead'));
        add_action('wp_ajax_nopriv_ai_chatbot_submit_lead', array($this, 'handle_submit_lead'));

        // Conversation persistence AJAX handlers
        add_action('wp_ajax_ai_chatbot_save_message', array($this, 'handle_save_message'));
        add_action('wp_ajax_nopriv_ai_chatbot_save_message', array($this, 'handle_save_message'));
        add_action('wp_ajax_ai_chatbot_get_history', array($this, 'handle_get_history'));
        add_action('wp_ajax_nopriv_ai_chatbot_get_history', array($this, 'handle_get_history'));

        add_action('wp_ajax_ai_chatbot_switch_mode', array($this, 'handle_switch_mode'));
        add_action('wp_ajax_nopriv_ai_chatbot_switch_mode', array($this, 'handle_switch_mode'));
    }

    /**
     * Enqueue CSS and JS for frontend chat widget.
     */
    public function enqueue_frontend_assets()
    {
        // Enqueue Google Font 'Outfit' for a beautiful premium typography
        wp_enqueue_style('ai-chatbot-font', 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap', array(), null);

        wp_enqueue_style('ai-chatbot-style', AI_CHATBOT_URL . 'assets/css/chat-style.css', array(), AI_CHATBOT_VERSION);

        wp_enqueue_script('ai-chatbot-script', AI_CHATBOT_URL . 'assets/js/chat-script.js', array('jquery'), AI_CHATBOT_VERSION, true);

        // Localize variables to JS (e.g. AJAX url, nonce, UI configuration)
        $primary_color = get_option('ai_chatbot_primary_color', '#0ea5e9'); // Default electric blue
        $welcome_msg = get_option('ai_chatbot_welcome_message', 'Trường Cao đẳng Kinh tế Công nghệ Hà Nội xin chào! Chúng tôi có thể giúp gì cho bạn hôm nay?');
        $require_lead = get_option('ai_chatbot_require_lead_form', '0');

        wp_localize_script('ai-chatbot-script', 'aiChatbotVars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_chatbot_nonce'),
            'primary_color' => $primary_color,
            'welcome_msg' => $welcome_msg,
            'require_lead' => $require_lead
        ));
    }

    /**
     * Renders floating chat widget in footer.
     */
    public function render_chat_widget()
    {
        // Check if chatbot is enabled globally in settings
        $enabled = get_option('ai_chatbot_enable_widget', '1');
        if ('1' !== $enabled) {
            return;
        }

        include AI_CHATBOT_PATH . 'templates/chat-widget.php';
    }

    /**
     * Shortcode [ai_chatbot] handler for inline embedding.
     */
    public function render_chat_widget_shortcode($atts)
    {
        ob_start();
        // Inline version flag so script/styles know how to behave
        $is_inline = true;
        include AI_CHATBOT_PATH . 'templates/chat-widget.php';
        return ob_get_clean();
    }

    /**
     * AJAX handler to process user queries using RAG context and Gemini API.
     */
    public function handle_chat_query()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_chatbot_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed. Please reload the page.'));
        }

        $message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
        if (empty($message)) {
            wp_send_json_error(array('message' => 'Empty message.'));
        }

        $api_key = get_option('ai_chatbot_openrouter_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'Chatbot chưa được cấu hình. Vui lòng thêm OpenRouter API Key trong phần cài đặt.'));
        }

        // 0. Fetch history
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $history = array();
        if (!empty($session_id)) {
            global $wpdb;
            $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
            $table_messages = $wpdb->prefix . 'ai_chatbot_messages';

            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table_conversations WHERE session_id = %s",
                $session_id
            ));

            if ($conversation) {
                $messages_db = $wpdb->get_results($wpdb->prepare(
                    "SELECT role, content FROM $table_messages WHERE conversation_id = %d AND chat_type = 'ai' ORDER BY created_at DESC LIMIT 6",
                    $conversation->id
                ));

                if ($messages_db) {
                    $messages_db = array_reverse($messages_db);
                    foreach ($messages_db as $msg) {
                        $role = ($msg->role === 'bot') ? 'assistant' : 'user';
                        $history[] = array(
                            'role' => $role,
                            'content' => $msg->content
                        );
                    }
                }
            }
        }

        // ==========================================
        // PHASE 9.5: Automated Conversational Lead Capture
        // ==========================================
        $is_lead_capture = false;
        $captured_name = '';
        $captured_phone = '';
        $phone_regex = '/(?:(?:\+|00)84|0)(?:3|5|7|8|9)\d{8}\b/';
        if (preg_match($phone_regex, $message, $matches)) {
            $is_lead_capture = true;
            $captured_phone = $matches[0];

            // 1. Remove phone number and any associated phone labels (e.g., "số điện thoại là", "sđt của mình là")
            $phone_escaped = preg_quote($captured_phone, '/');
            $phone_label_regex = '/(?:(?:[,\-\s:;]\s*)|^)(?:(?:s[\s\.\-_]*đ[\s\.\-_]*t|s[\s\.\-_]*d[\s\.\-_]*t|số\s*(?:đ|điện)?\s*(?:t|thoại)?|liên\s*hệ|cung\s*cấp)(?:\s+(?:của|qua|tại)?\s*(?:mình|em|anh|chị|tớ|bạn)?)?\s*(?:là|:|—|-|\s)*)*' . $phone_escaped . '(?:(?:\s+(?:ạ|nhá|nhé|nha|ah|đấy|nhỉ|nhắc)(?:\s+(?:ạ|ad|admin|anh|chị|em|bạn))?)+|(?=[,\-\.\s:;]|$))/iu';
            $clean_msg = preg_replace($phone_label_regex, ' ', $message);
            $clean_msg = str_replace($captured_phone, ' ', $clean_msg);

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

            $captured_name = (!empty($clean_msg) && mb_strlen($clean_msg, 'UTF-8') >= 2) ? $clean_msg : 'Học sinh (Qua Chat)';

            // Determine question context from recent conversation history
            $context_note = 'Quan tâm chung (Để lại SĐT trong trò chuyện)';
            if (!empty($history)) {
                $reversed_history = array_reverse($history);
                foreach ($reversed_history as $h_msg) {
                    if ($h_msg['role'] === 'user' && strpos($h_msg['content'], $captured_phone) === false) {
                        $context_note = 'Quan tâm: ' . mb_substr($h_msg['content'], 0, 120, 'UTF-8');
                        break;
                    }
                }
            }

            global $wpdb;
            $table_leads_capture = $wpdb->prefix . 'ai_chatbot_leads';
            $wpdb->insert(
                $table_leads_capture,
                array(
                    'name' => $captured_name,
                    'email' => 'Chưa cung cấp (Từ Chat)',
                    'phone' => $captured_phone,
                    'note' => $context_note
                ),
                array('%s', '%s', '%s', '%s')
            );

            // Update conversation lead name and trigger webhook
            if (!empty($session_id)) {
                $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
                $wpdb->update(
                    $table_conversations,
                    array('lead_name' => $captured_name . ' - ' . $captured_phone),
                    array('session_id' => $session_id),
                    array('%s'),
                    array('%s')
                );

                $this->send_to_google_sheets_webhook('LEAD', $session_id, array('lead_name' => $captured_name . ' - ' . $captured_phone));
            }
        }

        // ==========================================
        // PHASE 10: Cache Check
        // ==========================================
        $cache_key = 'ai_chat_' . md5(strtolower(trim($message)) . serialize($history));
        $cached_response = get_transient($cache_key);
        if (!$is_lead_capture && $cached_response !== false) {
            // Fake an SSE stream for the cached response
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            // Output as a single chunk
            $data = array(
                'choices' => array(
                    array(
                        'delta' => array(
                            'content' => $cached_response
                        )
                    )
                )
            );
            echo "data: " . wp_json_encode($data) . "\n\n";
            echo "data: [DONE]\n\n";
            exit;
        }

        // ==========================================
        // PHASE 7: Intent Router (Agentic Bypass)
        // ==========================================
        $is_small_talk = false;
        $lower_message = trim(mb_strtolower($message, 'UTF-8'));
        $chitchat_patterns = array(
            '/^(xin chào|chào|hello|hi|hey|alo|ê|bạn ơi|có ai không)/i',
            '/^(cảm ơn|thanks|tks|thank you|cám ơn)/i',
            '/^(tạm biệt|bye|goodbye)/i'
        );
        foreach ($chitchat_patterns as $pattern) {
            if (preg_match($pattern, $lower_message) && mb_strlen($lower_message, 'UTF-8') < 50) {
                $is_small_talk = true;
                break;
            }
        }

        // 1. Reformulate Query (Contextual Retrieval)
        $chat_model = get_option('ai_chatbot_openrouter_model');
        if (empty($chat_model))
            $chat_model = 'google/gemini-2.5-flash';
        $embed_model = get_option('ai_chatbot_openrouter_embed_model');
        if (empty($embed_model))
            $embed_model = 'qwen/qwen3-embedding-8b';
        $client = new OpenRouter_API_Client($api_key, $chat_model, $embed_model);

        $search_query = $message;
        $enable_reformulate = get_option('ai_chatbot_enable_reformulate', '0');

        // Only reformulate if message looks like a pronoun-heavy follow-up (saves 1-3s per query)
        $needs_reformulation = false;
        if (!$is_small_talk && '1' === $enable_reformulate && !empty($history)) {
            $pronoun_patterns = '/^(nó|đó|cái đó|thế|vậy|sao|gì|nào|còn|thì|với lại)/ui';
            if (preg_match($pronoun_patterns, $lower_message) && mb_strlen($lower_message, 'UTF-8') < 80) {
                $needs_reformulation = true;
            }
        }
        if ($needs_reformulation) {
            $search_query = $client->reformulate_query($message, $history);
        }

        $rag_manager = new AI_Chatbot_Manager();

        $chunks_text = "";

        if (!$is_small_talk) {
            // Force top_k to at least 25 to fix missing context issues, even if DB has 5 saved
            $k = max(25, intval(get_option('ai_chatbot_top_k', '25')));
            $relevant_chunks = $rag_manager->search_similar_chunks($search_query, $k);

            // 2. Format context from database/Pinecone
            if (!empty($relevant_chunks)) {
                foreach ($relevant_chunks as $idx => $chunk) {
                    $doc_name = $chunk['document_name'];
                    // Append metadata if it exists
                    $meta_str = "";
                    if (isset($chunk['metadata']) && !empty($chunk['metadata'])) {
                        $meta_str = " (Meta: " . $chunk['metadata'] . ")";
                    }
                    $chunks_text .= "Đoạn trích " . ($idx + 1) . "$meta_str:\n" . $chunk['content'] . "\n\n";
                }
            } else {
                $chunks_text = "Không có tài liệu nào liên quan.";
            }
        }

        // Custom system prompt/persona from settings
        $default_prompt = "Bạn là Trợ lý AI tư vấn tuyển sinh chính thức của trường Cao đẳng Kinh tế Công nghệ Hà Nội (Hateco) Hỗ trợ giải đáp thắc mắc, tư vấn chọn ngành, hướng dẫn thủ tục xét tuyển cho học sinh, phụ huynh quan tâm đến trường HATECO. Xưng \"mình\", gọi người dùng là \"bạn\".

[KHO TRI THỨC]
{context}
[/KHO TRI THỨC]

QUY TẮC BẮT BUỘC:
1. Dựa vào [KHO TRI THỨC] để trả lời một cách thân thiện, nhiệt tình.
2. Trình bày các danh sách (ví dụ: danh sách ngành học) bằng dấu cộng (+) rõ ràng ở đầu mỗi dòng.
3. Có thể kết thúc câu trả lời bằng những câu hỏi gợi mở thân thiện như \"Bạn đang quan tâm đến ngành nào?\" hoặc \"Để mình tư vấn kỹ hơn cho bạn nhé.\"
4. KHÔNG tự bịa đặt bất kỳ thông tin, số liệu, học phí, hay ngành học nào KHÔNG có trong tài liệu.
5. Nếu thông tin HOÀN TOÀN KHÔNG CÓ trong [KHO TRI THỨC], mới trả lời đúng 1 câu: \"Dạ, phần này bên mình chưa có thông tin chính thức. Bạn có muốn để lại tên và số điện thoại để phòng đào tạo liên hệ hỗ trợ không ạ?\"";

        // Force the new prompt by bypassing the database option which holds the old one
        $system_prompt = $default_prompt;

        // Inject context into the prompt
        if (strpos($system_prompt, '{context}') !== false) {
            $system_prompt = str_replace('{context}', $chunks_text, $system_prompt);
        } else {
            $system_prompt .= "\n\n[KHO TRI THỨC]\n" . $chunks_text . "\n[/KHO TRI THỨC]";
        }

        $context_text = $system_prompt;

        if ($is_lead_capture) {
            $context_text .= "\n\n[HỆ THỐNG GHI NHẬN THÔNG TIN LIÊN HỆ BẮT BUỘC]: Người dùng vừa cung cấp Số điện thoại ($captured_phone) và Tên ($captured_name). Hệ thống đã tự động lưu thành công thông tin vào Danh sách Quản lý của nhà trường. Hãy cảm ơn bạn học sinh một cách niềm nở, xác nhận rằng Phòng Đào tạo Trường Cao đẳng Kinh tế Công nghệ Hà Nội (Hateco) sẽ sớm liên hệ trực tiếp qua số điện thoại trên để hỗ trợ tư vấn chi tiết, và hỏi xem bạn có thắc mắc gì thêm trong thời gian chờ đợi không.";
        }

        // History is now handled above

        // Eval mode: Return JSON with context instead of calling LLM with SSE
        $eval_mode = isset($_POST['eval_mode']) ? sanitize_text_field(wp_unslash($_POST['eval_mode'])) : '0';
        if ($eval_mode === '1') {
            // Generate answer synchronously instead of streaming
            $answer = $client->generate_chat_answer($message, $context_text, $history);

            // Extract raw contexts for eval
            $raw_contexts = array();
            if (isset($relevant_chunks) && is_array($relevant_chunks)) {
                foreach ($relevant_chunks as $chunk) {
                    $raw_contexts[] = $chunk['content'];
                }
            }

            wp_send_json_success(array(
                'answer' => is_wp_error($answer) ? $answer->get_error_message() : $answer,
                'contexts' => $raw_contexts
            ));
            exit;
        }

        // 4. Make OpenRouter API chat call with Streaming
        $client->generate_chat_answer_stream($message, $context_text, $history);
    }

    /**
     * AJAX handler to submit customer lead information.
     */
    public function handle_submit_lead()
    {
        check_ajax_referer('ai_chatbot_nonce', 'nonce');

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

        if (empty($name) || empty($email) || empty($phone)) {
            wp_send_json_error(array('message' => 'Vui lòng điền đầy đủ thông tin để bắt đầu tư vấn.'));
        }

        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'Địa chỉ email không hợp lệ.'));
        }

        global $wpdb;
        $table_leads = $wpdb->prefix . 'ai_chatbot_leads';

        $inserted = $wpdb->insert(
            $table_leads,
            array(
                'name' => $name,
                'email' => $email,
                'phone' => $phone
            ),
            array('%s', '%s', '%s')
        );

        if (!$inserted) {
            wp_send_json_error(array('message' => 'Không thể lưu thông tin. Vui lòng thử lại.'));
        }

        // Webhook for explicit lead form submit
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        if (!empty($session_id)) {
            $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
            $wpdb->update(
                $table_conversations,
                array('lead_name' => $name . ' - ' . $phone),
                array('session_id' => $session_id),
                array('%s'),
                array('%s')
            );

            $this->send_to_google_sheets_webhook('LEAD', $session_id, array('lead_name' => $name . ' - ' . $phone));
        }

        wp_send_json_success(array('message' => 'Thông tin đăng ký thành công!'));
    }

    /**
     * AJAX handler to save/update a conversation session and its messages.
     */
    public function handle_save_message()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_chatbot_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $role = isset($_POST['role']) ? sanitize_text_field(wp_unslash($_POST['role'])) : '';
        $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
        $lead_name = isset($_POST['lead_name']) ? sanitize_text_field(wp_unslash($_POST['lead_name'])) : '';
        $chat_type = isset($_POST['chat_type']) ? sanitize_text_field(wp_unslash($_POST['chat_type'])) : 'ai';

        if (empty($session_id) || empty($role) || empty($content)) {
            wp_send_json_error(array('message' => 'Missing required fields.'));
        }

        if (!in_array($role, array('user', 'bot'), true)) {
            wp_send_json_error(array('message' => 'Invalid role.'));
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
        $table_messages = $wpdb->prefix . 'ai_chatbot_messages';

        // Get or create conversation
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_conversations WHERE session_id = %s",
            $session_id
        ));

        $is_new_lead = false;
        if ($conversation) {
            $conversation_id = $conversation->id;
            // Update lead_name if provided
            if (!empty($lead_name) && empty($conversation->lead_name)) {
                $is_new_lead = true;
                $wpdb->update(
                    $table_conversations,
                    array('lead_name' => $lead_name, 'updated_at' => current_time('mysql'), 'status' => $chat_type === 'human' ? 'human' : 'bot'),
                    array('id' => $conversation_id),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
            } else {
                $wpdb->update(
                    $table_conversations,
                    array('updated_at' => current_time('mysql'), 'status' => $chat_type === 'human' ? 'human' : 'bot'),
                    array('id' => $conversation_id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
        } else {
            $inserted = $wpdb->insert(
                $table_conversations,
                array(
                    'session_id' => $session_id,
                    'lead_name' => $lead_name,
                    'status' => $chat_type === 'human' ? 'human' : 'bot',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%s', '%s')
            );
            if (!$inserted) {
                wp_send_json_error(array('message' => 'Could not create conversation.'));
            }
            $conversation_id = $wpdb->insert_id;
        }

        // Save the message
        $inserted = $wpdb->insert(
            $table_messages,
            array(
                'conversation_id' => $conversation_id,
                'role' => $role,
                'content' => $content,
                'chat_type' => $chat_type,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );

        if (!$inserted) {
            wp_send_json_error(array('message' => 'Could not save message.'));
        }

        if ($is_new_lead) {
            $this->send_to_google_sheets_webhook('LEAD', $session_id, array('lead_name' => $lead_name));
        }

        $this->send_to_google_sheets_webhook('MESSAGE', $session_id, array(
            'role' => $role,
            'content' => $content,
            'chat_type' => $chat_type,
            'lead_name' => $lead_name
        ));

        wp_send_json_success(array(
            'message_id' => $wpdb->insert_id,
            'conversation_id' => $conversation_id,
        ));
    }

    /**
     * AJAX handler to retrieve conversation history for a session.
     */
    public function handle_get_history()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_chatbot_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        if (empty($session_id)) {
            wp_send_json_error(array('message' => 'Missing session ID.'));
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
        $table_messages = $wpdb->prefix . 'ai_chatbot_messages';

        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_conversations WHERE session_id = %s",
            $session_id
        ));

        if (!$conversation) {
            wp_send_json_success(array('messages' => array(), 'lead_name' => ''));
        }

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT role, content, chat_type, created_at FROM $table_messages WHERE conversation_id = %d ORDER BY created_at ASC",
            $conversation->id
        ), ARRAY_A);

        wp_send_json_success(array(
            'messages' => $messages,
            'lead_name' => $conversation->lead_name,
        ));
    }

    /**
     * AJAX handler to explicitly switch conversation mode (bot <-> human)
     */
    public function handle_switch_mode()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_chatbot_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : '';

        if (empty($session_id) || !in_array($mode, array('bot', 'human'), true)) {
            wp_send_json_error(array('message' => 'Invalid parameters.'));
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';

        $wpdb->update(
            $table_conversations,
            array('status' => $mode, 'updated_at' => current_time('mysql')),
            array('session_id' => $session_id),
            array('%s', '%s'),
            array('%s')
        );

        wp_send_json_success();
    }

    private function send_to_google_sheets_webhook($type, $session_id, $data)
    {
        $webhook_url = get_option('ai_chatbot_google_sheets_webhook', '');
        if (empty($webhook_url)) {
            return;
        }

        if ($type === 'LEAD') {
            global $wpdb;
            $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
            $table_messages = $wpdb->prefix . 'ai_chatbot_messages';

            $conversation = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table_conversations WHERE session_id = %s", $session_id));
            if ($conversation) {
                $messages = $wpdb->get_results($wpdb->prepare("SELECT role, content FROM $table_messages WHERE conversation_id = %d ORDER BY created_at ASC", $conversation->id), ARRAY_A);

                $history_text = "";
                foreach ($messages as $msg) {
                    $sender = $msg['role'] === 'user' ? 'Khách hàng' : 'Chatbot';
                    $history_text .= "[$sender] " . wp_strip_all_tags($msg['content']) . "\n\n";
                }
                $data['history'] = trim($history_text);
            }
        }

        $payload = array(
            'type' => $type,
            'session_id' => $session_id,
            'data' => $data
        );

        $response = wp_remote_post($webhook_url, array(
            'method' => 'POST',
            'timeout' => 15,
            'blocking' => true,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload)
        ));

        if (is_wp_error($response)) {
            error_log('AI Chatbot Webhook Error: ' . $response->get_error_message());
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code >= 400) {
                error_log('AI Chatbot Webhook HTTP Error: ' . $status_code . ' - ' . wp_remote_retrieve_body($response));
            }
        }
    }
}

// Instantiate the primary plugin class
add_action('plugins_loaded', array('AI_Chatbot_Chatbot', 'get_instance'));
