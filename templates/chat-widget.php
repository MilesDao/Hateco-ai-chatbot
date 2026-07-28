<?php
/**
 * Template: chat-widget.php
 *
 * Renders the HTML markup for the RAG Hateco AI Chatbot.
 * Supports both floating widget mode and shortcode inline mode.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if this is an inline shortcode render
$is_inline = isset($is_inline) && $is_inline;

$primary_color = get_option('ai_chatbot_primary_color', '#0ea5e9');
$welcome_msg = get_option('ai_chatbot_welcome_message', 'Trường Cao đẳng Kinh tế Công nghệ Hà Nội xin chào! Chúng tôi có thể giúp gì cho bạn hôm nay?');
$bot_name = get_option('ai_chatbot_bot_name', 'Hateco Hateco AI Chatbot');

// Unique ID suffix to prevent DOM collisions
$widget_id = $is_inline ? 'ai_chatbot-chat-inline' : 'ai_chatbot-chat-floating';
?>

<div class="ai_chatbot-chatbot-container <?php echo $is_inline ? 'ai_chatbot-layout-inline' : 'ai_chatbot-layout-floating'; ?>"
    id="<?php echo esc_attr($widget_id); ?>"
    style="--ai_chatbot-primary: <?php echo esc_attr($primary_color); ?>; --ai_chatbot-primary-glow: <?php echo esc_attr($primary_color); ?>33;">

    <?php if (!$is_inline): ?>
        <!-- Floating Chat Bubble Trigger (Option A) -->
        <button class="ai_chatbot-chat-bubble" id="<?php echo esc_attr($widget_id); ?>-trigger"
            aria-label="Open AI Assistant">
            <svg class="icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
            <svg class="icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
            <span class="ai_chatbot-pulse-dot"></span>
        </button>
    <?php endif; ?>

    <!-- Chat Pane Window -->
    <div class="ai_chatbot-chat-window <?php echo !$is_inline ? 'ai_chatbot-state-closed' : ''; ?>"
        id="<?php echo esc_attr($widget_id); ?>-window">
        <!-- Chat Header -->
        <div class="ai_chatbot-chat-header">
            <div class="ai_chatbot-header-info">
                <div class="ai_chatbot-avatar">
                    <img src="<?php echo esc_url(AI_CHATBOT_URL . 'assets/logo-1.png'); ?>"
                        alt="Logo Trường Cao đẳng Kinh tế Công nghệ Hà Nội"
                        style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <span class="status-indicator"></span>
                </div>
                <div>
                    <h4 class="ai_chatbot-bot-title"><?php echo esc_html($bot_name); ?></h4>
                    <span class="ai_chatbot-bot-subtitle">Đang hoạt động</span>
                </div>
            </div>

            <?php if (!$is_inline): ?>
                <!-- Minimize Button for Floating Window -->
                <button class="ai_chatbot-btn-minimize" id="<?php echo esc_attr($widget_id); ?>-minimize"
                    aria-label="Minimize Chat">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                </button>
            <?php endif; ?>
        </div>

        <!-- Lead Capture Form Overlay -->
        <div class="ai_chatbot-lead-form-overlay" id="<?php echo esc_attr($widget_id); ?>-lead-overlay"
            style="display: none;">
            <div class="ai_chatbot-lead-form-content">
                <h3>Đăng ký Tư vấn</h3>
                <p>Chào mừng bạn đến với Trường Cao đẳng Kinh tế Công nghệ Hà Nội Nơi chắp cánh tri thức – kiến tạo
                    tương lai nghề nghiệp vững chắc.</p>
                <form class="ai_chatbot-lead-form" id="<?php echo esc_attr($widget_id); ?>-lead-capture-form">
                    <div class="ai_chatbot-lead-group">
                        <input type="text" id="<?php echo esc_attr($widget_id); ?>-lead-name" required
                            placeholder="Họ và tên *">
                    </div>
                    <div class="ai_chatbot-lead-group">
                        <input type="email" id="<?php echo esc_attr($widget_id); ?>-lead-email" required
                            placeholder="Địa chỉ Email *">
                    </div>
                    <div class="ai_chatbot-lead-group">
                        <input type="tel" id="<?php echo esc_attr($widget_id); ?>-lead-phone" required
                            placeholder="Số điện thoại *">
                    </div>
                    <div class="ai_chatbot-lead-error" id="<?php echo esc_attr($widget_id); ?>-lead-error"
                        style="display:none; color:#ef4444; font-size:12px; margin-bottom:12px; font-weight:500;"></div>
                    <button type="submit" class="ai_chatbot-btn-lead-submit"
                        id="<?php echo esc_attr($widget_id); ?>-lead-submit">Bắt đầu tư vấn</button>
                </form>
            </div>
        </div>

        <!-- Main Content Area: AI View -->
        <div class="ai_chatbot-view ai_chatbot-view-ai" id="<?php echo esc_attr($widget_id); ?>-view-ai">
            <!-- Chat Conversation Area -->
            <div class="ai_chatbot-chat-messages" id="<?php echo esc_attr($widget_id); ?>-messages">
                <!-- Initial Greeting -->
                <div class="ai_chatbot-msg-wrapper bot">
                    <div class="ai_chatbot-msg-bubble">
                        <p><?php echo esc_html($welcome_msg); ?></p>
                    </div>
                </div>
            </div>

            <!-- Chat Input Area -->
            <div class="ai_chatbot-chat-input-area ai_chatbot-hidden"
                id="<?php echo esc_attr($widget_id); ?>-input-area" style="display: none;">
                <form class="ai_chatbot-chat-form" id="<?php echo esc_attr($widget_id); ?>-form" autocomplete="off">
                    <input type="text" class="ai_chatbot-input-field" id="<?php echo esc_attr($widget_id); ?>-input"
                        placeholder="Type your message" aria-label="Type message" disabled>
                    <button type="submit" class="ai_chatbot-btn-send" id="<?php echo esc_attr($widget_id); ?>-submit"
                        aria-label="Send message" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </form>
            </div>

        </div>


    </div>
</div>