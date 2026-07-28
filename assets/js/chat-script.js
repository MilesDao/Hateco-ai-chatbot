/**
 * Frontend Controller for Hateco AI Chatbot
 * Manages multiple chatbot instances, AJAX requests, scrolling, and markdown parsing.
 */

jQuery(document).ready(function ($) {

    // Initialize every chatbot instance on the page
    $('.ai_chatbot-chatbot-container').each(function () {
        var container = $(this);
        var id = container.attr('id');
        var isInline = container.hasClass('ai_chatbot-layout-inline');

        var bubble = $('#' + id + '-trigger');
        var chatWindow = $('#' + id + '-window');
        var minimizeBtn = $('#' + id + '-minimize');

        // AI elements
        var messagesViewport = $('#' + id + '-messages');
        var chatForm = $('#' + id + '-form');
        var inputField = $('#' + id + '-input');
        var submitBtn = $('#' + id + '-submit');
        var inputArea = $('#' + id + '-input-area');
        // Lead capture selectors
        var leadOverlay = $('#' + id + '-lead-overlay');
        var leadForm = $('#' + id + '-lead-capture-form');
        var leadSubmit = $('#' + id + '-lead-submit');
        var leadError = $('#' + id + '-lead-error');

        // Initial setup for Lead Capture Form
        var hasLeadSubmitted = false;
        var leadName = '';
        var requireLeadForm = true;

        // Session / Conversation persistence
        var sessionId = '';
        try {
            sessionId = localStorage.getItem('ai_chatbot_session_id');
            if (!sessionId) {
                sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);
                localStorage.setItem('ai_chatbot_session_id', sessionId);
            }
            hasLeadSubmitted = localStorage.getItem('ai_chatbot_lead_submitted') === 'true';
            leadName = localStorage.getItem('ai_chatbot_lead_name') || '';
        } catch (e) {
            console.warn("Hateco AI Chatbot: localStorage is blocked or unsupported in this browser environment.", e);
            sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);
        }

        // State
        var currentMode = 'bot';
        var loadedHistoryCount = 0;
        var isWaitingForResponse = false;
        var pendingMessage = ''; // store typed text while waiting
        // Persist a message to the backend
        function saveMessage(role, content, chatType) {
            chatType = chatType || 'ai';
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) ? aiChatbotVars.ajax_url : '/wp-admin/admin-ajax.php';
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) ? aiChatbotVars.nonce : '';
            try {
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_chatbot_save_message',
                        nonce: nonceValue,
                        session_id: sessionId,
                        role: role,
                        content: content,
                        lead_name: leadName,
                        chat_type: chatType
                    },
                    async: true
                });
            } catch (e) {
                console.warn("Hateco AI Chatbot: Failed to persist message.", e);
            }
        }

        // Load conversation history from backend
        function loadHistory() {
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) ? aiChatbotVars.ajax_url : '/wp-admin/admin-ajax.php';
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) ? aiChatbotVars.nonce : '';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_chatbot_get_history',
                    nonce: nonceValue,
                    session_id: sessionId
                },
                success: function (response) {
                    if (typeof response === 'string') {
                        try { response = JSON.parse(response); } catch (e) { }
                    }
                    if (response && response.success && response.data && response.data.messages) {
                        var msgs = response.data.messages;

                        if (msgs.length > loadedHistoryCount) {
                            if (loadedHistoryCount === 0 && msgs.length > 0) {
                                // Remove AI welcome placeholder on first load
                                messagesViewport.find('.ai_chatbot-msg-wrapper.bot:first').remove();
                            }

                            var newMsgs = msgs.slice(loadedHistoryCount);
                            $.each(newMsgs, function (i, msg) {
                                var sender = msg.role === 'user' ? 'user' : 'bot';
                                var msgMode = msg.chat_type === 'human' ? 'human' : 'ai';

                                var msgTime = '';
                                if (msg.created_at) {
                                    var parts = msg.created_at.split(' ');
                                    if (parts.length > 1) {
                                        var timeParts = parts[1].split(':');
                                        if (timeParts.length > 1) {
                                            msgTime = timeParts[0] + ':' + timeParts[1];
                                        }
                                    }
                                }

                                if (sender === 'bot' && msgMode === 'ai') {
                                    var html = parseMarkdown(msg.content);
                                    appendMessage(html, sender, true, msgTime, msgMode);
                                } else {
                                    var html = parseMarkdown(msg.content);
                                    appendMessage(html, sender, true, msgTime, msgMode);
                                }
                            });

                            loadedHistoryCount = msgs.length;
                            scrollToBottom(false, 'ai');
                            scrollToBottom(false, 'human');
                        }
                    }
                }
            });
        }

        // Automatically enable chat interaction without upfront lead forms
        if (leadOverlay.length) {
            leadOverlay.hide();
        }
        inputArea.removeClass('ai_chatbot-hidden').show();
        inputField.prop('disabled', false);
        submitBtn.prop('disabled', false);

        if (leadName) {
            var nameParts = leadName.trim().split(/\s+/);
            var firstName = nameParts.length > 0 ? nameParts[nameParts.length - 1] : '';
            if (firstName) {
                var welcomeGreeting = 'Trường Cao đẳng Kinh tế Công nghệ Hà Nội xin chào ' + firstName + '! Chúng tôi có thể giúp gì cho bạn hôm nay?';
                messagesViewport.find('.ai_chatbot-msg-wrapper.bot:first .ai_chatbot-msg-bubble p').text(welcomeGreeting);
            }
        }

        // Load history on page load
        if (sessionId) {
            setTimeout(function () { loadHistory(); }, 100);
        }

        if (!isInline) {
            bubble.on('click', function (e) {
                e.stopPropagation();
                if (chatWindow.hasClass('ai_chatbot-state-closed')) {
                    openChat();
                } else {
                    closeChat();
                }
            });

            minimizeBtn.on('click', function (e) {
                e.stopPropagation();
                closeChat();
            });

            $(document).on('keyup', function (e) {
                if (e.key === "Escape" && !chatWindow.hasClass('ai_chatbot-state-closed')) {
                    closeChat();
                }
            });

            chatWindow.on('click', function (e) { e.stopPropagation(); });
        }

        function openChat() {
            chatWindow.removeClass('ai_chatbot-state-closed');
            bubble.find('.icon-open').hide();
            bubble.find('.icon-close').show();
            bubble.find('.ai_chatbot-pulse-dot').fadeOut(300);

            // Ensure input field and send button are explicitly enabled and interactive whenever chat opens
            inputArea.removeClass('ai_chatbot-hidden').show();
            inputField.prop('disabled', false).prop('readonly', false).css('pointer-events', 'auto');
            submitBtn.prop('disabled', false);

            setTimeout(function () {
                inputField.focus();
            }, 100);
            setTimeout(function () {
                inputField.focus();
            }, 300);
            scrollToBottom(true, currentMode);
        }

        function closeChat() {
            chatWindow.addClass('ai_chatbot-state-closed');
            bubble.find('.icon-close').hide();
            bubble.find('.icon-open').show();
        }

        // AI Chat Form Submit
        chatForm.on('submit', function (e) {
            e.preventDefault();
            var userText = inputField.val().trim();
            if (!userText) return;

            // If still waiting for AI, block sending
            if (isWaitingForResponse) return;

            isWaitingForResponse = true;
            inputField.val('');
            // Keep input ENABLED so user can type, only disable submit
            submitBtn.prop('disabled', true);

            appendMessage(userText, 'user', false, '', 'ai');
            scrollToBottom(true, 'ai');

            saveMessage('user', userText, 'ai');
            loadedHistoryCount++; // increment to account for user msg just saved

            appendTypingIndicator();
            scrollToBottom(true, 'ai');

            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) ? aiChatbotVars.ajax_url : '/wp-admin/admin-ajax.php';
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) ? aiChatbotVars.nonce : '';

            try {
                var formData = new FormData();
                formData.append('action', 'ai_chatbot_chat_query');
                formData.append('message', userText);
                formData.append('session_id', sessionId);
                formData.append('nonce', nonceValue);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                }).then(async response => {
                    if (!response.ok) throw new Error('Network response was not ok');

                    var reader = response.body.getReader();
                    var decoder = new TextDecoder("utf-8");
                    var fullAnswer = "";
                    var currentBoxHTML = "";
                    var currentMsgBubble = null;
                    var streamBuffer = ""; // Buffer to hold incomplete lines

                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;

                        var chunk = decoder.decode(value, { stream: true });
                        streamBuffer += chunk;

                        try {
                            var parsedJson = JSON.parse(streamBuffer.trim());
                            if (parsedJson.success === false && parsedJson.data && parsedJson.data.message) {
                                if (!currentMsgBubble) removeTypingIndicator();
                                appendMessage('<p style="color: #ef4444; font-weight: 500;">Lỗi: ' + parsedJson.data.message + '</p>', 'bot', true, '', 'ai');
                                fullAnswer = "[Lỗi hệ thống]";
                                break;
                            } else if (parsedJson.error && parsedJson.error.message) {
                                if (!currentMsgBubble) removeTypingIndicator();
                                appendMessage('<p style="color: #ef4444; font-weight: 500;">Lỗi AI Model: ' + parsedJson.error.message + '</p>', 'bot', true, '', 'ai');
                                fullAnswer = "[Lỗi AI Model]";
                                break;
                            }
                        } catch (e) { }

                        var lines = streamBuffer.split('\n');
                        streamBuffer = lines.pop(); // The last element is an incomplete line, put it back in the buffer

                        for (var i = 0; i < lines.length; i++) {
                            var line = lines[i].trim();
                            if (line.startsWith('data: ')) {
                                var dataStr = line.substring(6).trim();
                                if (dataStr === '[DONE]') continue;
                                if (dataStr.startsWith('[ERROR]')) {
                                    if (!currentMsgBubble) {
                                        removeTypingIndicator();
                                        appendMessage('<p style="color: #ef4444; font-weight: 500;">' + dataStr + '</p>', 'bot', true, '', 'ai');
                                        currentMsgBubble = messagesViewport.find('.ai_chatbot-msg-wrapper.bot:not(.ai_chatbot-typing-loader):last .ai_chatbot-msg-bubble');
                                    }
                                    continue;
                                }

                                try {
                                    var dataObj = JSON.parse(dataStr);
                                    if (dataObj.choices && dataObj.choices[0] && dataObj.choices[0].delta && dataObj.choices[0].delta.content) {
                                        var deltaText = dataObj.choices[0].delta.content;
                                        fullAnswer += deltaText;

                                        currentBoxHTML = parseMarkdown(fullAnswer);

                                        if (!currentMsgBubble) {
                                            removeTypingIndicator();
                                            appendMessage('', 'bot', false, '', 'ai');
                                            currentMsgBubble = messagesViewport.find('.ai_chatbot-msg-wrapper.bot:not(.ai_chatbot-typing-loader):last .ai_chatbot-msg-bubble');
                                        }

                                        currentMsgBubble.html(currentBoxHTML);
                                        scrollToBottom(false, 'ai');
                                    }
                                } catch (err) { }
                            }
                        }
                    }

                    if (!currentMsgBubble) {
                        removeTypingIndicator();
                    }

                    inputField.focus();
                    submitBtn.prop('disabled', false);
                    isWaitingForResponse = false;
                    saveMessage('bot', fullAnswer, 'ai');
                    loadedHistoryCount++;

                }).catch(error => {
                    removeTypingIndicator();
                    inputField.focus();
                    submitBtn.prop('disabled', false);
                    isWaitingForResponse = false;
                    appendMessage('<p style="color: #ef4444; font-weight: 500;">Có lỗi kết nối hệ thống. Vui lòng thử lại.</p>', 'bot', true, '', 'ai');
                    scrollToBottom(true, 'ai');
                });
            } catch (err) {
                removeTypingIndicator();
                inputField.focus();
                submitBtn.prop('disabled', false);
                isWaitingForResponse = false;
                appendMessage('<p style="color: #ef4444; font-weight: 500;">Đã xảy ra lỗi ngoài ý muốn. Vui lòng tải lại trang.</p>', 'bot', true, '', 'ai');
            }
        });


        // Helper to append speech bubble
        function appendMessage(content, sender, isHTML, time, mode) {
            mode = mode || 'ai';
            var timeString = time || getCurrentTime();
            var bubbleContent = isHTML ? content : escapeHtml(content);

            var innerHtml = '';
            var bubbleHtml = '<div class="ai_chatbot-msg-bubble">' + (isHTML ? bubbleContent : '<p>' + bubbleContent + '</p>') + '</div>';
            var innerHtml = bubbleHtml;

            var msgHtml = '<div class="ai_chatbot-msg-wrapper ' + sender + '">' + innerHtml + '</div>';
            messagesViewport.append(msgHtml);
        }

        // Typing loader helpers
        function appendTypingIndicator() {
            var loaderHtml =
                '<div class="ai_chatbot-msg-wrapper bot ai_chatbot-typing-loader">' +
                '<div class="ai_chatbot-msg-bubble">' +
                '<div class="ai_chatbot-typing-indicator">' +
                '<span class="ai_chatbot-typing-dot"></span>' +
                '<span class="ai_chatbot-typing-dot"></span>' +
                '<span class="ai_chatbot-typing-dot"></span>' +
                '</div>' +
                '</div>' +
                '</div>';
            messagesViewport.append(loaderHtml);
        }

        function removeTypingIndicator() {
            messagesViewport.find('.ai_chatbot-typing-loader').remove();
        }

        // Scroll conversation to bottom
        function scrollToBottom(animate, mode) {
            mode = mode || 'ai';
            var vp = messagesViewport;
            var scrollHeight = vp[0].scrollHeight;
            if (animate) {
                vp.animate({ scrollTop: scrollHeight }, 300);
            } else {
                vp.scrollTop(scrollHeight);
            }
        }

        function getCurrentTime() {
            var now = new Date();
            var hours = now.getHours().toString().padStart(2, '0');
            var minutes = now.getMinutes().toString().padStart(2, '0');
            return hours + ':' + minutes;
        }

        function escapeHtml(text) {
            if (!text || typeof text !== 'string') return '';
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        function parseMarkdown(markdownText) {
            if (!markdownText || typeof markdownText !== 'string') return '';
            var html = escapeHtml(markdownText);
            html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');

            var lines = html.split('\n');
            var listOpen = false;
            var processedLines = [];

            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                if (line.startsWith('- ') || line.startsWith('* ')) {
                    if (!listOpen) {
                        processedLines.push('<ul>');
                        listOpen = true;
                    }
                    processedLines.push('<li>' + line.substring(2) + '</li>');
                } else {
                    if (listOpen) {
                        processedLines.push('</ul>');
                        listOpen = false;
                    }
                    processedLines.push(lines[i]);
                }
            }
            if (listOpen) processedLines.push('</ul>');

            html = processedLines.join('\n');
            var paragraphs = html.split(/\n\n+/);
            var finalHTML = [];

            for (var p = 0; p < paragraphs.length; p++) {
                var pText = paragraphs[p].trim();
                if (!pText) continue;
                if (pText.startsWith('<ul>') || pText.startsWith('<ol>') || pText.startsWith('<li>')) {
                    finalHTML.push(pText);
                } else {
                    finalHTML.push('<p>' + pText.replace(/\n/g, '<br>') + '</p>');
                }
            }
            return finalHTML.join('');
        }
    });
});
