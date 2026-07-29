# Installation & Configuration Guide: Hateco AI Chatbot

Welcome to your **Hateco AI Chatbot** WordPress plugin! This plugin adds a premium, glassmorphic floating AI assistant to your WordPress website. It utilizes **Retrieval-Augmented Generation (RAG)** powered by Google Gemini, allowing it to search uploaded company documents (PDFs and Text files) locally in your WordPress database to deliver highly personalized, accurate replies.

---

## 📂 Plugin File Structure

Before packaging, verify that all files are situated in your workspace in the following structure:

```
plugin-chatbot-ai/
├── ai-chatbot.php         # Main entry point and initialization
├── instruction.md                 # This instruction file
├── includes/
│   ├── class-admin-settings.php   # Admin settings & document dashboard
│   ├── class-ai_chatbot-client.php    # Network controller for Gemini API
│   └── class-rag-manager.php      # PDF/TXT parsing, chunking, and similarity search
├── templates/
│   └── chat-widget.php            # Chat widget HTML layout
└── assets/
    ├── css/
    │   └── chat-style.css         # Modern glassmorphism dark-slate styles
    └── js/
        └── chat-script.js         # AJAX and frontend chat logic
```

---

## 🛠️ Step 1: Package the Plugin

To install the chatbot onto WordPress, you will need to zip the plugin directory:

1. Locate the parent folder: `plugin-chatbot-ai`.
2. Compress (Zip) the folder:
   - **On Windows**: Right-click the folder, select **Compress to ZIP file**, and name it `ai-chatbot.zip`.
   - **On Mac**: Right-click the folder, select **Compress "plugin-chatbot-ai"**, and rename the output to `ai-chatbot.zip`.

> [!IMPORTANT]
> Ensure the zip structure contains the folder `plugin-chatbot-ai` at its root, rather than compressing the files directly inside.

---

## 🚀 Step 2: Install and Activate on WordPress

1. Log in to your **WordPress Administration Panel** (e.g. `yourwebsite.com/wp-admin`).
2. Navigate to **Plugins** > **Add New** in the sidebar.
3. Click the **Upload Plugin** button at the top of the page.
4. Click **Choose File** and select your newly packaged `ai-chatbot.zip`.
5. Click **Install Now**.
6. Once successfully uploaded, click **Activate Plugin**.

---

## 🔑 Step 3: Configure Gemini API & Chatbot settings

Upon activation, a new menu called **Hateco AI Chatbot** will appear in your WordPress sidebar.

1. Click on **Hateco AI Chatbot** > **API & Chatbot Settings**.
2. **Gemini API Key (Cấu hình bảo mật ngầm)**:
   - *Cách 1 (Nhập thủ công)*: Dán mã API key của bạn vào đây. Lấy mã miễn phí tại **[Google AI Studio](https://aistudio.google.com/)**.
   - *Cách 2 (Khuyên dùng - Ẩn hoàn toàn)*: Để ẩn API key hoàn toàn không cho người quản trị website nhìn thấy, hãy mở tệp `wp-config.php` hoặc file mã nguồn của WordPress và thêm dòng khai báo sau:
     ```php
     define( 'GEMINI_API_KEY', 'MÃ_API_KEY_CỦA_BẠN_Ở_ĐÂY' );
     ```
     Sau khi cấu hình bằng cách này, ô nhập API Key trong trang quản trị sẽ tự động khóa lại và ẩn đi: **"✔ Khóa API đã được cấu hình ngầm bảo mật trong mã nguồn (Hệ thống ẩn)."**
3. **Floating Widget**: Toggle whether the floating chat bubble should automatically appear in the bottom-right corner across your entire site.
4. **Thu thập thông tin khách hàng (Lead Capture)**: Bật/tắt tính năng bắt buộc khách hàng nhập Tên, Email, SĐT trước khi trò chuyện cùng AI.
5. **Theme Color**: Use the interactive color picker to choose your branding color (the default is a premium electric-blue `#0ea5e9`).
6. **Welcome Message**: Set the introductory message that greets users when they open the chat pane.
7. **AI Bot Persona / System Instructions**: Define the chatbot's behavior, tone of voice, or restrictions.
   * *Example*: `"You are a polite receptionist for ACME Corp. Answer questions concisely using only the text snippets."*
8. **Advanced Settings (Default values recommended)**:
   - *Context Chunk Count (Top K)*: The number of most relevant matching snippets sent to Gemini (recommended: `3` or `4`).
   - *Chunk Size*: Maximum characters per chunk (`1000`).
   - *Chunk Overlap*: Character overlap between adjacent chunks (`200`).
9. Click **Save Configuration**.

---

## 📚 Step 4: Build Your RAG Knowledge Base

This is where the magic of Retrieval-Augmented Generation happens. Instead of relying on general knowledge, you can upload specific documents to customize the AI's intelligence:

1. Inside **Hateco AI Chatbot**, switch to the **Knowledge Base Files** tab.
2. Drag and drop a `.txt` or `.pdf` document (such as a pricing page, FAQ list, user guide, or company profile) into the dashed upload area, or click it to choose a file.
3. The system will automatically:
   - Extract the plain text from the file (using a lightweight, pure PHP FlateDecode stream parser for PDF).
   - Divide the text into logical, overlapping blocks (snapping to word boundaries so sentences aren't split in half).
   - Call Gemini's `text-embedding-004` model to compute a 768-dimension vector embedding for each block.
   - Store the chunks and embedding vectors locally in custom MySQL tables (`{wp_prefix}ai_chatbot_chunks` and `{wp_prefix}ai_chatbot_documents`).
4. Once completed, your file will appear in the **Ingested Documents** table, showing the size, status, and number of created chunks.

> [!NOTE]
> Storing the embeddings locally inside your own WordPress database is **100% free** and fully self-contained. It eliminates the need for expensive third-party cloud vector database subscriptions!

---

## 💬 Step 5: How to Display the Chatbot

You have two beautiful layout options to choose from:

### Option A: Global Floating Bubble (Default & Recommended)
If **Enable Floating Chat Widget** is set to "Enabled" in the settings, a modern electric-blue chat bubble will appear in the bottom-right corner of every page on your site.
- When clicked, it smoothly slides up a premium glassmorphic chat pane.
- Responsive design: Fits beautifully on desktops and automatically scales to a full-screen mobile panel on phones.

### Option B: Inline Page Embed (Shortcode)
To embed the chatbot directly inside a specific page, post, or layout area (e.g. an "FAQ Support" page), use the WordPress shortcode:
```text
[ai_chatbot]
```
- Paste this inside the WordPress Gutenberg Block Editor using a **Shortcode** block, or inside any Page Builder (Elementor, Divi, Beaver, etc.).
- The inline version matches the responsive design of your layout but nests directly inside the page flow instead of floating over the screen.

---

## 👥 Step 6: Quản lý thông tin khách hàng (Customer Leads)

Nếu bạn bật tính năng **Lead Capture** (Thu thập thông tin khách hàng):
1. Truy cập vào **Hateco AI Chatbot** > Chọn tab **Thông tin khách hàng (Leads)**.
2. Tại đây bạn sẽ thấy danh sách đầy đủ tất cả khách hàng đã đăng ký trước khi trò chuyện cùng AI, bao gồm:
   - **Họ và tên**: Tên khách hàng đăng ký.
   - **Địa chỉ Email**: Nhấp vào email để gửi thư trực tiếp cho khách hàng.
   - **Số điện thoại**: Số điện thoại liên hệ để gọi điện tư vấn.
   - **Thời gian đăng ký**: Thời điểm khách hàng điền form đăng ký.
3. Người quản trị có thể xóa thông tin khách hàng bằng cách nhấn nút **Xóa** ở cột Thao tác. Hành động này sẽ được thực hiện qua cơ chế AJAX an toàn và bảo mật.

---

## 🛠️ Step 7: How RAG works in Action

When a visitor types a question in the chat widget:
1. The widget triggers a secure WordPress AJAX request (`wp_ajax_ai_chatbot_chat_query`).
2. The RAG Manager vectorizes the user's question using Gemini's embedding API.
3. It performs a **high-speed Cosine Similarity** comparison between the question vector and all document chunks stored in your MySQL database.
4. It extracts the top $K$ most similar text chunks (matching content) in milliseconds.
5. It packs these relevant chunks as context alongside your system prompt, sending them to **`ai_chatbot-2.5-flash`** for a highly specialized, context-aware reply.
6. The frontend renders the result, formatting lists and bold text on the fly using a secure HTML markdown converter.

---

## 🔍 Troubleshooting

* **Chat says "Gemini API Error: Embeddings API returned HTTP 403..."**: Your Gemini API key is incorrect or has expired. Generate a new key in Google AI Studio and update your plugin settings.
* **Uploaded PDF files show 0 chunks**: Ensure the PDF is a text-based PDF (not scanned images of text). The PHP PDF extractor reads text streams. Scanned PDFs require OCR, which is not supported natively.
* **Large files fail to upload**: Check your WordPress maximum upload limit (normally 2MB - 128MB). We recommend uploading smaller, modular files (under 2MB) for optimal indexing speeds.
* **Chat doesn't open when the bubble is clicked**: Verify that jQuery is enqueued correctly on your theme (standard for almost all WordPress themes). Check the browser Console (F12) for JavaScript errors.
* **Deleting a file**: Click the **Delete** button next to any file in your Knowledge Base tab. This cascade deletes the document record and drops all its corresponding chunks and embeddings, instantly removing it from the chatbot's memory.
