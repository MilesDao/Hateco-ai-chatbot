<?php
/**
 * Class AI_Chatbot_Admin_Settings
 *
 * Renders and handles the WordPress administration settings dashboard,
 * including options saving, file uploads, collected leads list, and AJAX actions.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Chatbot_Admin_Settings
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX handlers for document upload, deletion and lead deletion
        add_action('wp_ajax_ai_chatbot_upload_file', array($this, 'handle_file_upload'));
        add_action('wp_ajax_ai_chatbot_delete_file', array($this, 'handle_file_deletion'));
        add_action('wp_ajax_ai_chatbot_delete_lead', array($this, 'handle_lead_deletion'));

        // AJAX handler for viewing conversation messages
        add_action('wp_ajax_ai_chatbot_get_conversation_messages', array($this, 'handle_get_conversation_messages'));
        add_action('wp_ajax_ai_chatbot_check_waiting', array($this, 'handle_check_waiting'));
    }

    /**
     * Add the menu to the admin dashboard.
     */
    public function register_admin_menu()
    {
        add_menu_page(
            'Hateco AI Chatbot',
            'Hateco AI Chatbot',
            'manage_options',
            'ai-chatbot',
            array($this, 'render_admin_dashboard'),
            'dashicons-format-chat',
            80
        );
    }

    /**
     * Register plugin configuration settings.
     */
    public function register_settings()
    {
        register_setting('ai_chatbot_options', 'ai_chatbot_openrouter_api_key');
        register_setting('ai_chatbot_options', 'ai_chatbot_openrouter_model');
        register_setting('ai_chatbot_options', 'ai_chatbot_openrouter_embed_model');
        register_setting('ai_chatbot_options', 'ai_chatbot_openrouter_rerank_model');
        register_setting('ai_chatbot_options', 'ai_chatbot_enable_reranker');
        register_setting('ai_chatbot_options', 'ai_chatbot_enable_reformulate');
        register_setting('ai_chatbot_options', 'ai_chatbot_enable_widget');
        register_setting('ai_chatbot_options', 'ai_chatbot_require_lead_form');
        register_setting('ai_chatbot_options', 'ai_chatbot_primary_color');
        register_setting('ai_chatbot_options', 'ai_chatbot_welcome_message');
        register_setting('ai_chatbot_options', 'ai_chatbot_system_prompt');
        register_setting('ai_chatbot_options', 'ai_chatbot_chunk_size');
        register_setting('ai_chatbot_options', 'ai_chatbot_chunk_overlap');
        register_setting('ai_chatbot_options', 'ai_chatbot_top_k');
        register_setting('ai_chatbot_options', 'ai_chatbot_pinecone_api_key');
        register_setting('ai_chatbot_options', 'ai_chatbot_pinecone_host');
    }

    /**
     * Enqueue admin color pickers and styling.
     */
    public function enqueue_admin_assets($hook)
    {
        if ('toplevel_page_ai-chatbot' !== $hook) {
            return;
        }

        // Enqueue WP Color Picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Enqueue Google Font for Admin Styling
        wp_enqueue_style('ai_chatbot-admin-font', 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap', array(), null);
    }

    /**
     * Render the beautiful administration panel HTML.
     */
    public function render_admin_dashboard()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Support implicit secret API Key via PHP constant OPENROUTER_API_KEY
        $api_key_is_constant = defined('OPENROUTER_API_KEY');
        $api_key = $api_key_is_constant ? OPENROUTER_API_KEY : get_option('ai_chatbot_openrouter_api_key', '');
        $chat_model = get_option('ai_chatbot_openrouter_model');
        if (empty($chat_model))
            $chat_model = 'deepseek/deepseek-v4-flash';

        $embed_model = get_option('ai_chatbot_openrouter_embed_model');
        if (empty($embed_model))
            $embed_model = 'qwen/qwen3-embedding-8b';

        $rerank_model = get_option('ai_chatbot_openrouter_rerank_model');
        if (empty($rerank_model))
            $rerank_model = 'cohere/rerank-v3.5';

        $enable_reranker = get_option('ai_chatbot_enable_reranker', '1');
        $enable_reformulate = get_option('ai_chatbot_enable_reformulate', '1');

        $enable_widget = get_option('ai_chatbot_enable_widget', '1');
        $require_lead = get_option('ai_chatbot_require_lead_form', '1');
        $primary_color = get_option('ai_chatbot_primary_color', '#0ea5e9');
        $welcome_msg = get_option('ai_chatbot_welcome_message', 'Trường Cao đẳng Kinh tế Công nghệ Hà Nội xin chào! Chúng tôi có thể giúp gì cho bạn hôm nay?');
        $default_prompt = "Bạn là Trợ lý Tư vấn Tuyển sinh Cao đẳng Kinh tế Công nghệ Hà Nội (Hateco). Xưng Anh/Chị, gọi người dùng là Em.

[KHO TRI THỨC]
{context}
[/KHO TRI THỨC]

QUY TẮC:
1. CHỈ dùng thông tin trong [KHO TRI THỨC]. Không bịa số liệu.
2. Trả lời ngắn gọn, tối đa 3-4 câu cho câu hỏi đơn giản. Liệt kê gạch đầu dòng nếu nhiều mục.
3. Luôn kết thúc bằng 1 câu hỏi gợi mở để tiếp tục hội thoại.
4. Nếu không có thông tin: \"Dạ, phần này anh/chị chưa có thông tin chính thức. Em có muốn để lại số điện thoại để phòng đào tạo liên hệ hỗ trợ không ạ?\"
5. Không giải thích lại câu hỏi. Trả lời thẳng.";
        $system_prompt = get_option('ai_chatbot_system_prompt', $default_prompt);
        $chunk_size = get_option('ai_chatbot_chunk_size', '1000');
        $chunk_overlap = get_option('ai_chatbot_chunk_overlap', '200');
        $top_k = get_option('ai_chatbot_top_k', '3');

        $pinecone_api_key = get_option('ai_chatbot_pinecone_api_key', '');
        $pinecone_host = get_option('ai_chatbot_pinecone_host', '');

        // Active Tab logic
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        ?>
        <style>
            .ai_chatbot-admin-wrap {
                font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                margin: 20px 20px 0 0;
                color: #1e293b;
            }

            .ai_chatbot-admin-wrap h1 {
                font-weight: 700;
                font-size: 28px;
                color: #0f172a;
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .ai_chatbot-admin-wrap h1 span.badge {
                font-size: 12px;
                background: #0ea5e9;
                color: white;
                padding: 4px 8px;
                border-radius: 9999px;
                font-weight: 500;
            }

            .ai_chatbot-tabs {
                border-bottom: 2px solid #e2e8f0;
                display: flex;
                gap: 20px;
                margin-bottom: 25px;
            }

            .ai_chatbot-tab-link {
                text-decoration: none;
                color: #64748b;
                font-weight: 500;
                font-size: 16px;
                padding: 10px 5px;
                border-bottom: 3px solid transparent;
                transition: all 0.2s ease;
                cursor: pointer;
            }

            .ai_chatbot-tab-link:hover,
            .ai_chatbot-tab-link.active {
                color: #0ea5e9;
                border-color: #0ea5e9;
            }

            .ai_chatbot-card {
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);
                border: 1px solid #f1f5f9;
                padding: 30px;
                margin-bottom: 20px;
            }

            .ai_chatbot-form-group {
                margin-bottom: 22px;
            }

            .ai_chatbot-form-group label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #334155;
                font-size: 15px;
            }

            .ai_chatbot-form-group input[type="text"],
            .ai_chatbot-form-group input[type="password"],
            .ai_chatbot-form-group input[type="number"],
            .ai_chatbot-form-group textarea {
                width: 100%;
                max-width: 600px;
                padding: 10px 14px;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                background-color: #f8fafc;
                font-size: 14px;
                color: #1e293b;
                transition: border-color 0.15s ease-in-out;
            }

            .ai_chatbot-form-group input:focus,
            .ai_chatbot-form-group textarea:focus {
                border-color: #0ea5e9;
                background-color: #ffffff;
                box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
                outline: none;
            }

            .ai_chatbot-form-group p.description {
                color: #64748b;
                font-size: 13px;
                margin-top: 6px;
                max-width: 600px;
            }

            .ai_chatbot-btn-primary {
                background: #0ea5e9;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 8px;
                font-weight: 600;
                font-size: 15px;
                cursor: pointer;
                transition: background 0.2s;
            }

            .ai_chatbot-btn-primary:hover {
                background: #0284c7;
            }

            /* File Upload Styling */
            .ai_chatbot-upload-zone {
                border: 2px dashed #cbd5e1;
                background: #f8fafc;
                border-radius: 12px;
                padding: 40px;
                text-align: center;
                cursor: pointer;
                transition: all 0.2s ease;
                max-width: 600px;
                margin-bottom: 30px;
            }

            .ai_chatbot-upload-zone:hover {
                border-color: #0ea5e9;
                background: #f0f9ff;
            }

            .ai_chatbot-upload-zone dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #64748b;
                margin-bottom: 12px;
            }

            .ai_chatbot-upload-zone h3 {
                margin: 0 0 6px 0;
                font-size: 16px;
                color: #334155;
            }

            .ai_chatbot-upload-zone p {
                margin: 0;
                color: #64748b;
                font-size: 13px;
            }

            /* Tables */
            .ai_chatbot-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
                margin-top: 10px;
            }

            .ai_chatbot-table th {
                background: #f8fafc;
                padding: 12px 16px;
                font-weight: 600;
                color: #475569;
                border-bottom: 2px solid #e2e8f0;
                font-size: 14px;
            }

            .ai_chatbot-table td {
                padding: 16px;
                border-bottom: 1px solid #f1f5f9;
                color: #334155;
                font-size: 14px;
            }

            .ai_chatbot-table tr:hover {
                background: #fafafa;
            }

            .badge-status {
                display: inline-block;
                padding: 4px 8px;
                font-size: 11px;
                font-weight: 600;
                border-radius: 9999px;
                text-transform: uppercase;
            }

            .badge-status.indexed {
                background: #dcfce7;
                color: #15803d;
            }

            .badge-status.pending {
                background: #fef9c3;
                color: #a16207;
            }

            .btn-delete {
                background: none;
                border: none;
                color: #ef4444;
                cursor: pointer;
                font-weight: 500;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-size: 13px;
                padding: 4px 8px;
                border-radius: 6px;
                transition: background 0.15s;
            }

            .btn-delete:hover {
                background: #fef2f2;
            }

            .btn-view {
                background: none;
                border: none;
                color: #0ea5e9;
                cursor: pointer;
                font-weight: 500;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-size: 13px;
                padding: 4px 8px;
                border-radius: 6px;
                transition: background 0.15s;
            }

            .btn-view:hover {
                background: #f0f9ff;
            }

            #ai_chatbot-upload-status {
                margin-top: 15px;
                padding: 12px;
                border-radius: 8px;
                display: none;
                max-width: 600px;
            }

            #ai_chatbot-upload-status.success {
                background-color: #dcfce7;
                color: #166534;
                border: 1px solid #bbf7d0;
                display: block;
            }

            #ai_chatbot-upload-status.error {
                background-color: #fee2e2;
                color: #991b1b;
                border: 1px solid #fca5a5;
                display: block;
            }
        </style>

        <div class="ai_chatbot-admin-wrap">
            <h1>Hateco AI Chatbot <span class="badge">v<?php echo esc_html(AI_CHATBOT_VERSION); ?></span></h1>

            <div class="ai_chatbot-tabs">
                <a href="?page=ai-chatbot&tab=settings"
                    class="ai_chatbot-tab-link <?php echo 'settings' === $active_tab ? 'active' : ''; ?>">Cấu hình Chatbot &
                    API</a>
                <a href="?page=ai-chatbot&tab=kb"
                    class="ai_chatbot-tab-link <?php echo 'kb' === $active_tab ? 'active' : ''; ?>">Tài liệu</a>
                <a href="?page=ai-chatbot&tab=leads"
                    class="ai_chatbot-tab-link <?php echo 'leads' === $active_tab ? 'active' : ''; ?>">Thông tin khách hàng
                    (Leads)</a>
                <a href="?page=ai-chatbot&tab=conversations"
                    class="ai_chatbot-tab-link <?php echo 'conversations' === $active_tab ? 'active' : ''; ?>">Lịch sử hội
                    thoại</a>
            </div>

            <?php if ('settings' === $active_tab): ?>
                <div class="ai_chatbot-card">
                    <form method="post" action="options.php">
                        <?php settings_fields('ai_chatbot_options'); ?>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_openrouter_api_key">OpenRouter API Key</label>
                            <?php if ($api_key_is_constant): ?>
                                <input type="text" disabled value="••••••••••••••••••••••••••••••••"
                                    style="background:#cbd5e1; color:#475569; max-width: 600px; cursor: not-allowed;">
                                <p class="description" style="color:#16a34a; font-weight:600; margin-top: 8px;">✔ Khóa API đã được cấu
                                    hình ngầm bảo mật trong mã nguồn (Hệ thống ẩn).</p>
                            <?php else: ?>
                                <input type="password" id="ai_chatbot_openrouter_api_key" name="ai_chatbot_openrouter_api_key"
                                    value="<?php echo esc_attr($api_key); ?>" placeholder="sk-or-v1-...">
                                <p class="description">Lấy mã API key tại <a href="https://openrouter.ai/keys" target="_blank"
                                        rel="noopener noreferrer">OpenRouter</a>.</p>
                            <?php endif; ?>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_openrouter_model">Mô hình Chat (LLM)</label>
                            <input type="text" id="ai_chatbot_openrouter_model" name="ai_chatbot_openrouter_model"
                                value="<?php echo esc_attr($chat_model); ?>" placeholder="deepseek/deepseek-v4-flash">
                            <p class="description">Nhập OpenRouter Model ID dùng để tạo câu trả lời (VD:
                                deepseek/deepseek-v4-flash).</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_openrouter_embed_model">Mô hình Nhúng (Embedding)</label>
                            <input type="text" id="ai_chatbot_openrouter_embed_model" name="ai_chatbot_openrouter_embed_model"
                                value="<?php echo esc_attr($embed_model); ?>" placeholder="qwen/qwen3-embedding-8b">
                            <p class="description">Nhập OpenRouter Embedding Model ID dùng để mã hóa tài liệu (VD:
                                qwen/qwen3-embedding-8b).</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_openrouter_rerank_model">Mô hình Sắp xếp lại (Reranker)</label>
                            <input type="text" id="ai_chatbot_openrouter_rerank_model" name="ai_chatbot_openrouter_rerank_model"
                                value="<?php echo esc_attr($rerank_model); ?>" placeholder="cohere/rerank-v3.5">
                            <p class="description">Nhập OpenRouter Reranker Model ID dùng để đánh giá độ chính xác tài liệu (VD:
                                cohere/rerank-v3.5).</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_pinecone_api_key">Pinecone API Key</label>
                            <input type="password" id="ai_chatbot_pinecone_api_key" name="ai_chatbot_pinecone_api_key"
                                value="<?php echo esc_attr($pinecone_api_key); ?>" placeholder="pcsk_...">
                            <p class="description">Lấy mã API key tại <a href="https://app.pinecone.io/" target="_blank"
                                    rel="noopener noreferrer">Pinecone</a> để sử dụng Vector Database.</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_pinecone_host">Pinecone Index Host</label>
                            <input type="text" id="ai_chatbot_pinecone_host" name="ai_chatbot_pinecone_host"
                                value="<?php echo esc_attr($pinecone_host); ?>"
                                placeholder="https://example-index-12345.svc.pinecone.io">
                            <p class="description">URL Host của Index trên Pinecone.</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_enable_widget">Hiển thị khung chat (Floating Bubble)</label>
                            <select id="ai_chatbot_enable_widget" name="ai_chatbot_enable_widget"
                                style="width: 100%; max-width: 600px; padding: 10px; border-radius: 8px;">
                                <option value="1" <?php selected($enable_widget, '1'); ?>>Bật tự động trên mọi trang của website
                                </option>
                                <option value="0" <?php selected($enable_widget, '0'); ?>>Tắt (Sử dụng Shortcode [ai_chatbot] trên
                                    trang riêng)</option>
                            </select>
                            <p class="description">Bật/tắt nút bong bóng chat ở góc dưới cùng bên phải màn hình.</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_require_lead_form">Thu thập thông tin khách hàng (Lead Capture)</label>
                            <select id="ai_chatbot_require_lead_form" name="ai_chatbot_require_lead_form"
                                style="width: 100%; max-width: 600px; padding: 10px; border-radius: 8px;">
                                <option value="1" <?php selected($require_lead, '1'); ?>>Bắt buộc điền Tên, Email, Số điện thoại
                                    trước khi chat</option>
                                <option value="0" <?php selected($require_lead, '0'); ?>>Tắt (Khách hàng có thể chat trực tiếp
                                    ngay lập tức)</option>
                            </select>
                            <p class="description">Bắt buộc khách hàng tiềm năng cung cấp thông tin liên lạc trước khi bắt đầu trò
                                chuyện tư vấn.</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_primary_color">Màu chủ đạo khung chat (Theme Color)</label>
                            <input type="text" id="ai_chatbot_primary_color" name="ai_chatbot_primary_color"
                                value="<?php echo esc_attr($primary_color); ?>" class="ai_chatbot-color-picker">
                            <p class="description">Chọn màu chủ đạo hiển thị cho widget phù hợp với thương hiệu của bạn. Mặc định là
                                xanh dương (#0ea5e9).</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_welcome_message">Tin nhắn chào mừng</label>
                            <input type="text" id="ai_chatbot_welcome_message" name="ai_chatbot_welcome_message"
                                value="<?php echo esc_attr($welcome_msg); ?>">
                            <p class="description">Lời nhắn đầu tiên của AI gửi tới khách hàng khi mở khung chat.</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_system_prompt">Chỉ dẫn hành vi Bot (Persona / System Instructions)</label>
                            <textarea id="ai_chatbot_system_prompt" name="ai_chatbot_system_prompt"
                                rows="4"><?php echo esc_textarea($system_prompt); ?></textarea>
                            <p class="description">Thiết lập giọng điệu, tính cách hoặc các ràng buộc cho chatbot AI (ví dụ: cấm nói
                                giá sản phẩm khác, luôn hướng dẫn liên hệ hotline...).</p>
                        </div>

                        <h3
                            style="margin-top: 30px; font-size: 18px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; max-width: 600px;">
                            Cấu hình RAG & Chunk nâng cao</h3>

                        <div class="ai_chatbot-form-group" style="margin-top: 15px;">
                            <label for="ai_chatbot_top_k">Số lượng đoạn trích gửi kèm (Top K)</label>
                            <input type="number" id="ai_chatbot_top_k" name="ai_chatbot_top_k"
                                value="<?php echo esc_attr($top_k); ?>" min="1" max="10">
                            <p class="description">Số lượng đoạn văn tài liệu khớp nhất được gửi làm bối cảnh để AI trả lời (khuyên
                                dùng: 3 hoặc 4).</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_chunk_size">Độ dài ký tự mỗi đoạn (Chunk Size)</label>
                            <input type="number" id="ai_chatbot_chunk_size" name="ai_chatbot_chunk_size"
                                value="<?php echo esc_attr($chunk_size); ?>" min="200" max="4000">
                            <p class="description">Kích thước tối đa mỗi khối ký tự khi chia nhỏ tài liệu để mã hóa (khuyên dùng:
                                800 - 1200).</p>
                        </div>

                        <div class="ai_chatbot-form-group">
                            <label for="ai_chatbot_chunk_overlap">Ký tự chồng lấn (Chunk Overlap)</label>
                            <input type="number" id="ai_chatbot_chunk_overlap" name="ai_chatbot_chunk_overlap"
                                value="<?php echo esc_attr($chunk_overlap); ?>" min="0" max="1000">
                            <p class="description">Độ chồng lấn ký tự giữa 2 đoạn kề nhau giúp duy trì ngữ cảnh liền mạch (mặc định:
                                200).</p>
                        </div>

                        <h3>Tối ưu Tốc độ (Speed Optimization)</h3>
                        <p style="color: #64748b; margin-bottom: 25px; max-width: 600px;">Các bước xử lý AI liên tiếp có thể gây
                            chậm trễ 3-5 giây. Bạn có thể tắt bớt các chức năng dưới đây để tăng tốc độ phản hồi.</p>

                        <div class="ai_chatbot-form-group checkbox-group" style="margin-bottom: 15px;">
                            <label>
                                <input type="checkbox" name="ai_chatbot_enable_reformulate" value="1" <?php checked('1', $enable_reformulate); ?>> <strong>Bật "Viết lại câu hỏi" (Query Reformulation)</strong>
                            </label>
                            <p class="description">Cho phép AI phân tích lịch sử chat để viết lại câu hỏi rõ ràng hơn. <em>Tắt đi sẽ
                                    giúp giảm ~1.5 giây độ trễ.</em></p>
                        </div>

                        <div class="ai_chatbot-form-group checkbox-group" style="margin-bottom: 25px;">
                            <label>
                                <input type="checkbox" name="ai_chatbot_enable_reranker" value="1" <?php checked('1', $enable_reranker); ?>> <strong>Bật Reranker (Cross-Encoder)</strong>
                            </label>
                            <p class="description">Dùng AI chuyên biệt để xếp hạng lại tài liệu. <em>Tắt đi sẽ giúp giảm ~1.5 giây
                                    độ trễ.</em></p>
                        </div>

                        <?php submit_button('Lưu cấu hình', 'primary', 'submit', true, array('class' => 'ai_chatbot-btn-primary')); ?>
                    </form>
                </div>
            <?php elseif ('kb' === $active_tab): ?>
                <div class="ai_chatbot-card">
                    <h2>Quản lý tệp Tài liệu</h2>
                    <p style="color: #64748b; margin-bottom: 25px; max-width: 600px;">
                        Tải lên các tài liệu hướng dẫn, FAQ, giới thiệu công ty hoặc sản phẩm dưới dạng tệp văn bản (.txt) hoặc tài
                        liệu (.pdf). Hệ thống của chúng tôi sẽ trích xuất, chia nhỏ và mã hóa chúng thành vector (embeddings) một
                        cách tự động để chatbot trả lời khách hàng chuẩn xác nhất.
                    </p>

                    <div class="ai_chatbot-upload-zone" id="ai_chatbot-dropzone">
                        <span class="dashicons dashicons-cloud-upload"
                            style="font-size: 40px; height: 40px; width: 40px; color: #0ea5e9;"></span>
                        <h3>Kéo thả tệp vào đây hoặc nhấn để chọn tệp</h3>
                        <p>Hỗ trợ định dạng .txt và .pdf (Tối đa: 5MB, Có thể chọn nhiều tệp)</p>
                        <input type="file" id="ai_chatbot-file-input" style="display: none;" accept=".txt,.pdf" multiple>
                    </div>

                    <div id="ai_chatbot-upload-status"></div>

                    <h3 style="margin-top: 40px; color: #0f172a; font-size: 18px;">Tài liệu đã được tải lên</h3>

                    <div style="overflow-x: auto; margin-top: 15px;">
                        <table class="ai_chatbot-table" id="ai_chatbot-docs-table">
                            <thead>
                                <tr>
                                    <th>Tên tệp</th>
                                    <th>Định dạng</th>
                                    <th>Dung lượng</th>
                                    <th>Trạng thái</th>
                                    <th>Số đoạn mã hóa (Chunks)</th>
                                    <th>Thời gian tải</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                global $wpdb;
                                $table_docs = $wpdb->prefix . 'ai_chatbot_documents';
                                $documents = $wpdb->get_results("SELECT * FROM $table_docs ORDER BY created_at DESC", ARRAY_A);

                                if (empty($documents)): ?>
                                    <tr class="no-docs-row">
                                        <td colspan="7" style="text-align: center; color: #94a3b8; padding: 30px;">Chưa có tài liệu nào
                                            được tải lên. Hãy tải lên một tệp phía trên để xây dựng bộ não cho AI.</td>
                                    </tr>
                                <?php else:
                                    foreach ($documents as $doc):
                                        $file_size_formatted = size_format($doc['file_size']);
                                        ?>
                                        <tr data-doc-id="<?php echo esc_attr($doc['id']); ?>">
                                            <td style="font-weight: 500;"><?php echo esc_html($doc['name']); ?></td>
                                            <td><span
                                                    style="text-transform: uppercase; font-size: 11px; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: 600; color: #475569;"><?php echo esc_html($doc['file_type']); ?></span>
                                            </td>
                                            <td><?php echo esc_html($file_size_formatted); ?></td>
                                            <td><span
                                                    class="badge-status <?php echo esc_attr($doc['status']); ?>"><?php echo esc_html($doc['status'] == 'indexed' ? 'Đã lưu' : 'Chờ xử lý'); ?></span>
                                            </td>
                                            <td style="font-weight: 600; text-align: center; color: #0f172a;">
                                                <?php echo esc_html($doc['chunk_count']); ?></td>
                                            <td style="color: #64748b; font-size: 13px;">
                                                <?php echo esc_html(date('d-m-Y H:i', strtotime($doc['created_at']))); ?></td>
                                            <td>
                                                <button class="btn-delete" data-doc-id="<?php echo esc_attr($doc['id']); ?>">
                                                    <span class="dashicons dashicons-trash"
                                                        style="font-size: 16px; width: 16px; height: 16px; line-height: 16px;"></span> Xóa
                                                    dữ liệu
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ('conversations' === $active_tab): ?>
                <div class="ai_chatbot-card">
                    <h2>Lịch sử hội thoại</h2>
                    <p style="color: #64748b; margin-bottom: 25px; max-width: 600px;">
                        Xem tất cả các cuộc hội thoại giữa người dùng và Hateco AI Chatbot. Nhấp vào một cuộc hội thoại để xem chi
                        tiết tin nhắn.
                    </p>

                    <?php
                    global $wpdb;
                    $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
                    $table_messages = $wpdb->prefix . 'ai_chatbot_messages';
                    $conversations = $wpdb->get_results(
                        "SELECT c.*, (SELECT COUNT(*) FROM $table_messages m WHERE m.conversation_id = c.id) as msg_count
                         FROM $table_conversations c
                         ORDER BY c.updated_at DESC",
                        ARRAY_A
                    );
                    ?>

                    <div style="overflow-x: auto; margin-top: 15px;">
                        <table class="ai_chatbot-table" id="ai_chatbot-conversations-table">
                            <thead>
                                <tr>
                                    <th>Phiên</th>
                                    <th>Khách hàng</th>
                                    <th>Số tin nhắn</th>
                                    <th>Bắt đầu</th>
                                    <th>Cập nhật</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($conversations)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: #94a3b8; padding: 30px;">Chưa có cuộc hội
                                            thoại nào.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($conversations as $conv): ?>
                                        <tr class="ai_chatbot-conv-row" data-conv-id="<?php echo esc_attr($conv['id']); ?>"
                                            style="cursor:pointer;">
                                            <td style="font-family: monospace; font-size: 12px; color: #64748b;">
                                                #<?php echo esc_html(substr($conv['session_id'], 0, 8)); ?></td>
                                            <td style="font-weight: 600; color: #0f172a;">
                                                <?php echo esc_html($conv['lead_name'] ?: 'Khách vãng lai'); ?></td>
                                            <td style="text-align: center;"><?php echo intval($conv['msg_count']); ?></td>
                                            <td style="color: #64748b; font-size: 13px;">
                                                <?php echo esc_html(date('d-m-Y H:i', strtotime($conv['created_at']))); ?></td>
                                            <td style="color: #64748b; font-size: 13px;">
                                                <?php echo esc_html(date('d-m-Y H:i', strtotime($conv['updated_at']))); ?></td>
                                            <td>
                                                <button class="ai_chatbot-btn-view-conv btn-view"
                                                    data-conv-id="<?php echo esc_attr($conv['id']); ?>">
                                                    Xem chi tiết
                                                </button>
                                            </td>
                                        </tr>
                                        <!-- Hidden detail row -->
                                        <tr class="ai_chatbot-conv-detail" data-conv-id="<?php echo esc_attr($conv['id']); ?>"
                                            data-session-id="<?php echo esc_attr($conv['session_id']); ?>" style="display:none;">
                                            <td colspan="6" style="padding: 0;">
                                                <div
                                                    style="background: #f8fafc; border-radius: 8px; margin: 8px; overflow: hidden; border: 1px solid #e2e8f0; max-width: 1000px; margin-left: auto; margin-right: auto; display: flex; flex-direction: column;">
                                                    <div
                                                        style="padding: 12px 16px; background: #ffffff; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                                                        <div style="font-weight: 600; color: #0f172a; font-size: 14px;">
                                                            Trò chuyện trực tiếp
                                                        </div>
                                                        <button class="ai_chatbot-btn-close-conv btn-view" style="color: #64748b;"
                                                            data-conv-id="<?php echo esc_attr($conv['id']); ?>">Đóng</button>
                                                    </div>

                                                    <div style="display: flex; flex-direction: row; height: 450px;">
                                                        <!-- AI Chat Only -->
                                                        <div style="flex: 1; display: flex; flex-direction: column;">
                                                            <div
                                                                style="padding: 10px; background: #f1f5f9; font-weight: 600; font-size: 13px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                                                                Lịch sử Chat với AI</div>
                                                            <div id="ai_chatbot-conv-log-ai-<?php echo esc_attr($conv['id']); ?>"
                                                                style="flex: 1; overflow-y: auto; padding: 16px; background: #ffffff; display: flex; flex-direction: column;">
                                                                <p style="text-align:center; color:#94a3b8; font-size:13px; margin: auto;">
                                                                    Đang tải...</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <script>
                    jQuery(document).ready(function ($) {



                        // Load conversation details
                        function renderUnifiedMsg(msg) {
                            var isUser = msg.role === 'user';
                            var isAdmin = (msg.role === 'bot' && msg.chat_type === 'human');
                            var isAI = (msg.role === 'bot' && msg.chat_type !== 'human');

                            var align = isUser ? 'flex-end' : 'flex-start';
                            var textBtnAlign = isUser ? 'right' : 'left';
                            var bg = isUser ? '#0ea5e9' : (isAdmin ? '#e0e7ff' : '#f1f5f9');
                            var color = isUser ? '#ffffff' : '#1e293b';
                            var border = isAdmin ? '1px solid #c7d2fe' : 'none';
                            var label = isUser ? 'Khách hàng' : (isAdmin ? '👤 Nhân viên' : '🤖 AI Bot');

                            var html = '<div style="display: flex; flex-direction: column; align-items: ' + align + '; margin-bottom: 12px; text-align: ' + textBtnAlign + ';">';
                            html += '<div style="max-width: 85%; background: ' + bg + '; color: ' + color + '; padding: 10px 14px; border-radius: 12px; font-size: 13px; line-height: 1.5; text-align: left; word-wrap: break-word; border: ' + border + ';">';
                            html += '<div style="font-size: 11px; font-weight: 700; opacity: 0.7; margin-bottom: 4px;">' + label + '</div>';
                            html += msg.content;
                            html += '</div>';
                            html += '<div style="font-size: 10px; opacity: 0.5; margin-top: 4px; padding: 0 4px;">' + msg.created_at + '</div>';
                            html += '</div>';
                            return html;
                        }

                        function fetchMessages(convId, forceScroll) {
                            $.post(ajaxurl, {
                                action: 'ai_chatbot_get_conversation_messages',
                                conv_id: convId,
                                nonce: '<?php echo wp_create_nonce("ai_chatbot_admin_nonce"); ?>'
                            }, function (response) {
                                if (response.success && response.data.messages) {
                                    var aiHtml = '';
                                    var humanHtml = '';

                                    $.each(response.data.messages, function (i, msg) {
                                        if (msg.chat_type === 'ai') {
                                            aiHtml += renderUnifiedMsg(msg);
                                        } else {
                                            humanHtml += renderUnifiedMsg(msg);
                                        }
                                    });

                                    var aiContainer = $('#ai_chatbot-conv-log-ai-' + convId);
                                    var humanContainer = $('#ai_chatbot-conv-log-human-' + convId);

                                    aiContainer.html(aiHtml || '<p style="text-align:center; color:#94a3b8; font-size:13px; margin: auto;">Chưa có hội thoại AI</p>');
                                    humanContainer.html(humanHtml || '<p style="text-align:center; color:#94a3b8; font-size:13px; margin: auto;">Chưa có tin nhắn hỗ trợ</p>');

                                    if (forceScroll) {
                                        aiContainer.scrollTop(aiContainer[0].scrollHeight);
                                        humanContainer.scrollTop(humanContainer[0].scrollHeight);
                                    }
                                }
                            });
                        }

                        $('.ai_chatbot-conv-row').on('click', function () {
                            var convId = $(this).data('conv-id');
                            var detailRow = $('.ai_chatbot-conv-detail[data-conv-id="' + convId + '"]');

                            if (detailRow.is(':visible')) {
                                detailRow.hide();
                                return;
                            }

                            $('.ai_chatbot-conv-detail').hide();
                            detailRow.show();

                            fetchMessages(convId, true);

                            if (!detailRow.data('polling')) {
                                var pollTimer = setInterval(function () {
                                    if (detailRow.is(':visible')) {
                                        fetchMessages(convId, false);
                                    }
                                }, 4000);
                                detailRow.data('polling', pollTimer);
                            }
                        });

                        $('.ai_chatbot-btn-close-conv').on('click', function (e) {
                            e.stopPropagation();
                            $(this).closest('.ai_chatbot-conv-detail').hide();
                        });

                        $('.ai_chatbot-btn-admin-send').on('click', function () {
                            var btn = $(this);
                            var convId = btn.data('conv-id');
                            var sessionId = btn.data('session-id');
                            var input = $('#ai_chatbot-admin-reply-' + convId);
                            var text = input.val().trim();

                            if (!text) return;

                            btn.prop('disabled', true).text('...');
                            input.prop('disabled', true);

                            $.post(ajaxurl, {
                                action: 'ai_chatbot_save_message',
                                nonce: '<?php echo wp_create_nonce("ai_chatbot_nonce"); ?>',
                                session_id: sessionId,
                                role: 'bot',
                                content: text,
                                chat_type: 'human'
                            }, function () {
                                input.val('').prop('disabled', false).focus();
                                btn.prop('disabled', false).text('Gửi');
                                fetchMessages(convId, true);
                            }).fail(function () {
                                alert("Có lỗi gửi tin nhắn.");
                                input.prop('disabled', false);
                                btn.prop('disabled', false).text('Gửi');
                            });
                        });

                        $('.ai_chatbot-admin-reply-input').on('keypress', function (e) {
                            if (e.which == 13) {
                                $(this).siblings('.ai_chatbot-btn-admin-send').click();
                            }
                        });
                    });
                </script>

            <?php elseif ('leads' === $active_tab): ?>
                <div class="ai_chatbot-card">
                    <h2>Danh sách khách hàng đăng ký tư vấn</h2>
                    <p style="color: #64748b; margin-bottom: 25px; max-width: 600px;">
                        Danh sách các khách hàng điền thông tin (Tên, Email, Số điện thoại) trước khi bắt đầu tư vấn với AI. Bạn có
                        thể sử dụng thông tin liên lạc này để gọi điện tư vấn chuyên sâu hoặc đưa vào phễu marketing/CRM.
                    </p>

                    <div style="overflow-x: auto; margin-top: 15px;">
                        <table class="ai_chatbot-table" id="ai_chatbot-leads-table">
                            <thead>
                                <tr>
                                    <th>Họ và tên</th>
                                    <th>Địa chỉ Email</th>
                                    <th>Số điện thoại</th>
                                    <th>Thời gian đăng ký</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                global $wpdb;
                                $table_leads = $wpdb->prefix . 'ai_chatbot_leads';
                                $leads = $wpdb->get_results("SELECT * FROM $table_leads ORDER BY created_at DESC", ARRAY_A);

                                if (empty($leads)): ?>
                                    <tr class="no-leads-row">
                                        <td colspan="5" style="text-align: center; color: #94a3b8; padding: 30px;">Chưa có khách hàng
                                            nào đăng ký thông tin tư vấn.</td>
                                    </tr>
                                <?php else:
                                    foreach ($leads as $lead):
                                        ?>
                                        <tr data-lead-id="<?php echo esc_attr($lead['id']); ?>">
                                            <td style="font-weight: 600; color: #0f172a;"><?php echo esc_html($lead['name']); ?></td>
                                            <td><a href="mailto:<?php echo esc_attr($lead['email']); ?>"
                                                    style="text-decoration:none; color:#0ea5e9;"><?php echo esc_html($lead['email']); ?></a>
                                            </td>
                                            <td style="font-weight: 500;"><?php echo esc_html($lead['phone']); ?></td>
                                            <td style="color: #64748b; font-size: 13px;">
                                                <?php echo esc_html(date('d-m-Y H:i', strtotime($lead['created_at']))); ?></td>
                                            <td>
                                                <button class="btn-delete-lead btn-delete"
                                                    data-lead-id="<?php echo esc_attr($lead['id']); ?>">
                                                    <span class="dashicons dashicons-trash"
                                                        style="font-size: 16px; width: 16px; height: 16px; line-height: 16px;"></span> Xóa
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // Initialize Color Picker
                $('.ai_chatbot-color-picker').wpColorPicker();

                // File drag and drop visual response
                var dropzone = $('#ai_chatbot-dropzone');
                var fileInput = $('#ai_chatbot-file-input');

                if (dropzone.length) {
                    dropzone.on('click', function () {
                        fileInput[0].click();
                    });

                    dropzone.on('dragover', function (e) {
                        e.preventDefault();
                        dropzone.css('border-color', '#0ea5e9').css('background', '#f0f9ff');
                    });

                    dropzone.on('dragleave', function (e) {
                        e.preventDefault();
                        dropzone.css('border-color', '#cbd5e1').css('background', '#f8fafc');
                    });

                    dropzone.on('drop', function (e) {
                        e.preventDefault();
                        dropzone.css('border-color', '#cbd5e1').css('background', '#f8fafc');
                        var files = e.originalEvent.dataTransfer.files;
                        if (files.length > 0) {
                            uploadFiles(files);
                        }
                    });

                    fileInput.on('change', function () {
                        if (this.files.length > 0) {
                            uploadFiles(this.files);
                        }
                    });
                }

                // AJAX Multiple File Upload Handler
                function uploadFiles(files) {
                    var statusBox = $('#ai_chatbot-upload-status');
                    var totalFiles = files.length;
                    var currentIndex = 0;
                    var successCount = 0;

                    function uploadNext() {
                        if (currentIndex >= totalFiles) {
                            if (successCount > 0) {
                                statusBox.attr('class', 'success').html('Đã tải lên hoàn tất ' + successCount + '/' + totalFiles + ' tệp! Đang tải lại...').show();
                                setTimeout(function () {
                                    location.reload();
                                }, 1500);
                            }
                            return;
                        }

                        var file = files[currentIndex];
                        var ext = file.name.split('.').pop().toLowerCase();

                        if (ext !== 'txt' && ext !== 'pdf') {
                            statusBox.attr('class', 'error').html('Lỗi tệp ' + file.name + ': Chỉ chấp nhận .txt hoặc .pdf.').show();
                            currentIndex++;
                            setTimeout(uploadNext, 1500);
                            return;
                        }
                        if (file.size > 5 * 1024 * 1024) {
                            statusBox.attr('class', 'error').html('Lỗi tệp ' + file.name + ': Vượt quá 5MB.').show();
                            currentIndex++;
                            setTimeout(uploadNext, 1500);
                            return;
                        }

                        var formData = new FormData();
                        formData.append('action', 'ai_chatbot_upload_file');
                        formData.append('file', file);
                        formData.append('nonce', '<?php echo wp_create_nonce("ai_chatbot_upload_nonce"); ?>');

                        statusBox.attr('class', 'success').html('<span class="dashicons dashicons-update spin" style="animation: spin 2s infinite linear; font-size:16px; margin-right:5px; height:16px; width:16px;"></span> Đang xử lý tệp ' + (currentIndex + 1) + '/' + totalFiles + ': ' + file.name + '...').show();

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function (response) {
                                if (response && response.success) {
                                    successCount++;
                                } else {
                                    var msg = (response && response.data && response.data.message) ? response.data.message : 'Đã xảy ra lỗi không xác định.';
                                    statusBox.attr('class', 'error').html('Lỗi với ' + file.name + ': ' + msg).show();
                                    // Pause to let user read error
                                    currentIndex++;
                                    setTimeout(uploadNext, 2500);
                                    return;
                                }
                                currentIndex++;
                                uploadNext();
                            },
                            error: function () {
                                statusBox.attr('class', 'error').html('Lỗi máy chủ với tệp ' + file.name).show();
                                currentIndex++;
                                setTimeout(uploadNext, 2500);
                            }
                        });
                    }

                    uploadNext();
                }

                // Add spinning animation style to admin head
                $("<style>")
                    .prop("type", "text/css")
                    .html("@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }")
                    .appendTo("head");

                // AJAX File Delete Handler
                $('.btn-delete').not('.btn-delete-lead').on('click', function () {
                    var btn = $(this);
                    var docId = btn.data('doc-id');

                    if (!confirm('Bạn có chắc chắn muốn xóa tài liệu này và toàn bộ các khối vector tương ứng khỏi bộ não của chatbot? Hành động này không thể hoàn tác.')) {
                        return;
                    }

                    btn.prop('disabled', true).text('Đang xóa...');

                    $.post(ajaxurl, {
                        action: 'ai_chatbot_delete_file',
                        doc_id: docId,
                        nonce: '<?php echo wp_create_nonce("ai_chatbot_delete_nonce"); ?>'
                    }, function (response) {
                        if (response.success) {
                            btn.closest('tr').fadeOut(400, function () {
                                $(this).remove();
                                if ($('#ai_chatbot-docs-table tbody tr').length === 0) {
                                    $('#ai_chatbot-docs-table tbody').html(
                                        '<tr class="no-docs-row"><td colspan="7" style="text-align: center; color: #94a3b8; padding: 30px;">Chưa có tài liệu nào được tải lên. Hãy tải lên một tệp phía trên để xây dựng bộ não cho AI.</td></tr>'
                                    );
                                }
                            });
                        } else {
                            alert('Xóa tài liệu thất bại: ' + response.data.message);
                            btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Xóa dữ liệu');
                        }
                    }).fail(function () {
                        alert('Đã xảy ra lỗi kết nối khi xóa tài liệu.');
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Xóa dữ liệu');
                    });
                });

                // AJAX Lead Delete Handler
                $('.btn-delete-lead').on('click', function () {
                    var btn = $(this);
                    var leadId = btn.data('lead-id');

                    if (!confirm('Bạn có chắc chắn muốn xóa thông tin khách hàng này khỏi cơ sở dữ liệu không? Hành động này không thể hoàn tác.')) {
                        return;
                    }

                    btn.prop('disabled', true).text('Đang xóa...');

                    $.post(ajaxurl, {
                        action: 'ai_chatbot_delete_lead',
                        lead_id: leadId,
                        nonce: '<?php echo wp_create_nonce("ai_chatbot_delete_lead_nonce"); ?>'
                    }, function (response) {
                        if (response.success) {
                            btn.closest('tr').fadeOut(400, function () {
                                $(this).remove();
                                if ($('#ai_chatbot-leads-table tbody tr').length === 0) {
                                    $('#ai_chatbot-leads-table tbody').html(
                                        '<tr class="no-leads-row"><td colspan="5" style="text-align: center; color: #94a3b8; padding: 30px;">Chưa có khách hàng nào đăng ký thông tin tư vấn.</td></tr>'
                                    );
                                }
                            });
                        } else {
                            alert('Xóa thông tin thất bại: ' + response.data.message);
                            btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Xóa');
                        }
                    }).fail(function () {
                        alert('Đã xảy ra lỗi kết nối khi xóa thông tin.');
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Xóa');
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Handles file uploading & instant vector RAG parsing.
     */
    public function handle_file_upload()
    {
        check_ajax_referer('ai_chatbot_upload_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện hành động này.'));
        }

        if (!isset($_FILES['file']) || empty($_FILES['file']['name'])) {
            wp_send_json_error(array('message' => 'Không tìm thấy tệp tải lên.'));
        }

        $file = $_FILES['file'];
        $file_name = sanitize_file_name($file['name']);
        $file_type = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $file_size = intval($file['size']);

        if ('txt' !== $file_type && 'pdf' !== $file_type) {
            wp_send_json_error(array('message' => 'Tệp không hợp lệ. Chỉ hỗ trợ tải tệp .txt và .pdf.'));
        }

        if ($file_size > 5 * 1024 * 1024) {
            wp_send_json_error(array('message' => 'Tệp vượt quá kích thước giới hạn 5MB.'));
        }

        global $wpdb;
        $table_docs = $wpdb->prefix . 'ai_chatbot_documents';

        // 1. Insert document row first (status = pending)
        $inserted = $wpdb->insert(
            $table_docs,
            array(
                'name' => $file_name,
                'file_type' => $file_type,
                'file_size' => $file_size,
                'chunk_count' => 0,
                'status' => 'pending'
            ),
            array('%s', '%s', '%d', '%d', '%s')
        );

        if (!$inserted) {
            wp_send_json_error(array('message' => 'Không thể tạo bản ghi tài liệu trong cơ sở dữ liệu.'));
        }

        $doc_id = $wpdb->insert_id;

        // 2. Process, chunk and index vectors using Gemini API
        $rag_manager = new AI_Chatbot_Manager();
        $result = $rag_manager->process_and_index_document($doc_id, $file['tmp_name'], $file_name);

        if (is_wp_error($result)) {
            // Delete the document record if it failed so user can try again
            $wpdb->delete($table_docs, array('id' => $doc_id), array('%d'));
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => 'Tài liệu đã được lập chỉ mục thành công!', 'doc_id' => $doc_id));
    }

    /**
     * Handles document removal & cascades vector indexes.
     */
    public function handle_file_deletion()
    {
        check_ajax_referer('ai_chatbot_delete_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện hành động này.'));
        }

        $doc_id = isset($_POST['doc_id']) ? intval($_POST['doc_id']) : 0;
        if (!$doc_id) {
            wp_send_json_error(array('message' => 'Không tìm thấy ID tài liệu.'));
        }

        global $wpdb;
        $table_docs = $wpdb->prefix . 'ai_chatbot_documents';
        $table_chunks = $wpdb->prefix . 'ai_chatbot_chunks';

        // 1. Fetch chunk IDs to delete from Pinecone
        $chunk_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table_chunks WHERE document_id = %d", $doc_id));

        if (!empty($chunk_ids)) {
            $pinecone_api_key = get_option('ai_chatbot_pinecone_api_key', '');
            $pinecone_host = get_option('ai_chatbot_pinecone_host', '');

            if (!empty($pinecone_api_key) && !empty($pinecone_host)) {
                $host = rtrim($pinecone_host, '/');
                $url = $host . '/vectors/delete';

                $ids_to_delete = array_map('strval', $chunk_ids);

                $body = array(
                    'ids' => $ids_to_delete,
                    'namespace' => ''
                );

                wp_remote_post($url, array(
                    'headers' => array(
                        'Api-Key' => $pinecone_api_key,
                        'Content-Type' => 'application/json'
                    ),
                    'body' => wp_json_encode($body),
                    'timeout' => 15
                ));
            }
        }

        // 2. Delete associated chunks from MySQL
        $wpdb->delete($table_chunks, array('document_id' => $doc_id), array('%d'));

        // 3. Delete parent document record
        $wpdb->delete($table_docs, array('id' => $doc_id), array('%d'));

        wp_send_json_success(array('message' => 'Xóa tài liệu và khối vector thành công.'));
    }

    /**
     * Handles customer lead removal.
     */
    public function handle_lead_deletion()
    {
        check_ajax_referer('ai_chatbot_delete_lead_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện hành động này.'));
        }

        $lead_id = isset($_POST['lead_id']) ? intval($_POST['lead_id']) : 0;
        if (!$lead_id) {
            wp_send_json_error(array('message' => 'Thiếu ID khách hàng.'));
        }

        global $wpdb;
        $table_leads = $wpdb->prefix . 'ai_chatbot_leads';

        $wpdb->delete($table_leads, array('id' => $lead_id), array('%d'));

        wp_send_json_success(array('message' => 'Xóa thông tin khách hàng thành công.'));
    }

    /**
     * AJAX handler to fetch messages for a conversation.
     */
    public function handle_check_waiting()
    {
        check_ajax_referer('ai_chatbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();

        global $wpdb;
        $table = $wpdb->prefix . 'ai_chatbot_conversations';
        $waiting = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'human'");
        wp_send_json_success(array('waiting' => intval($waiting)));
    }

    public function handle_get_conversation_messages()
    {
        check_ajax_referer('ai_chatbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        $conv_id = isset($_POST['conv_id']) ? intval($_POST['conv_id']) : 0;
        if (!$conv_id) {
            wp_send_json_error(array('message' => 'Invalid conversation ID.'));
        }

        global $wpdb;
        $table_messages = $wpdb->prefix . 'ai_chatbot_messages';

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT role, content, chat_type, created_at FROM $table_messages WHERE conversation_id = %d ORDER BY created_at ASC",
            $conv_id
        ), ARRAY_A);

        wp_send_json_success(array('messages' => $messages));
    }
}

// Instantiate settings controller
if (is_admin()) {
    new AI_Chatbot_Admin_Settings();
}
