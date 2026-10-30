# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (PHP 7.4+, jQuery frontend) providing a Vietnamese admissions-advisor chatbot for Hateco college. It does hybrid RAG over uploaded PDF/TXT documents, calls LLMs via OpenRouter, captures leads from chat, and supports AI ↔ human-agent chat handoff. There is no build step, no PHP test suite, and no linter configured.

## Commands

- **Package the plugin:** `python package_plugin.py` → writes `hateco-chatbot-updated.zip` (excludes `.venv`, `eval`, `data`, `.git`, `.codegraph`, `*.py`, `*.zip`, `*.log`). Source/output paths are hardcoded absolute Windows paths in the script. Install the zip via WP Admin → Plugins → Upload.
- **Local WordPress (Docker):** `cd eval/wp-local && docker compose up -d`, then once: `docker compose run --rm -T wpcli wp core install --url=http://localhost:8080 --title=Hateco-local --admin_user=admin --admin_password=admin --admin_email=a@b.co --skip-email` and `... wpcli wp plugin activate hateco-ai-chatbot` (repo is bind-mounted read-only, so code edits are live; site http://localhost:8080, admin/admin). `OPENROUTER_API_KEY=... python eval/wp_experiment.py` uploads the data, imports the FAQ and compares FAQ-in-RAG vs not via the real `admin-ajax` pipeline (`eval/faq_experiment_report.html`). Use `docker compose run --rm -T wpcli wp ...` (the `wp` prefix is required).
- **Terminal chat test:** `python eval/chat_cli.py [--ctx] [--url https://site/]` talks to a live deployed WordPress site via `ai_chatbot_chat_query` with `eval_mode=1` (JSON `{answer, contexts}`); `eval/run_lead_flow_check.py` checks the lead flow. There is no RAGAS/ground-truth eval anymore.
- `data/raw/` holds the original knowledge-base files (2 DOCX + the scanned GCN PDF, which has no text layer so the plugin's PDF extractor can't read it). `data/clean/cau-hoi-thuong-gap.xlsx` is the FAQ sheet to import in the admin FAQ tab (same Q&A as the DOCX, verbatim); `data/clean/*.txt` are the cleaned, hand-transcribed versions to upload in the admin Knowledge Base tab; re-upload after any edit (delete the old document first).

## Architecture

- `ai-chatbot.php` — entry point. Activation hook creates 6 tables via `dbDelta` (`{prefix}ai_chatbot_documents`, `_chunks` (with FULLTEXT on `content`), `_leads`, `_conversations`, `_messages`, `_faqs`). Singleton `AI_Chatbot_Chatbot` registers frontend assets, footer widget, `[ai_chatbot]` shortcode, and all public AJAX handlers (`wp_ajax_` + `wp_ajax_nopriv_`).
- `handle_chat_query()` is the core pipeline, in order:
  1. Load last 6 AI-mode messages for the session as history.
  2. **Lead capture:** a Vietnamese phone regex triggers regex-based name extraction, inserts into `_leads`, updates the conversation, and fires the Google Sheets webhook (`send_to_google_sheets_webhook`).
  3. **FAQ lookup:** `AI_Chatbot_FAQ::find()` (`includes/class-faq.php`, rows imported from .xlsx/.csv in the admin FAQ tab, which replaces all rows) embeds each FAQ question on import (`embedding` column) and matches the message by cosine ≥ `EMBED_THRESHOLD` (0.75, uncalibrated; the eval JSON/report expose `faq_score`), falling back to accent-insensitive keyword Dice ≥ 0.6 when no vectors/embedding call fails; a hit is returned verbatim (SSE, or JSON in `eval_mode`) and skips cache/RAG/LLM. Skipped on lead capture. `php tests/test-faq.php` covers matching and the xlsx XML parser.
  4. **Transient cache** keyed on message + history (skipped on lead capture); cached hits are replayed as a fake SSE stream.
  5. Small-talk regex router skips retrieval; optional query reformulation only for short pronoun-led follow-ups.
  6. `AI_Chatbot_Manager::search_similar_chunks()` with `k = max(25, top_k)` (top_k is forced ≥ 25).
  7. The base system prompt is **hardcoded** (`$default_prompt`, with `{context}` placeholder); the admin "Hành vi AI" tab (`ai_chatbot_response_style`, `_sample_replies`, `_business_info`, own settings group) is appended to it when non-empty.
  8. Streams via `generate_chat_answer_stream()` (SSE), or, when `eval_mode=1`, returns JSON `{answer, contexts}` synchronously — `eval/chat_cli.py` depends on this contract.
- `includes/class-rag-manager.php` (`AI_Chatbot_Manager`) — ingestion: pure-PHP PDF text extraction (FlateDecode, no OCR) → recursive parent/child chunking → embeddings → MySQL rows + Pinecone upsert. Retrieval: MySQL FULLTEXT boolean search (sparse) + Pinecone query (dense; `cosine_similarity()` exists but is unused, so no Pinecone config = sparse-only) → RRF (k=60) → optional Cohere rerank via OpenRouter (`ai_chatbot_enable_reranker`) → returns parent content. Query embeddings are cached in transients.
- `includes/class-openrouter-client.php` — HTTP wrapper for embeddings, query reformulation, chat (sync and streaming). Model defaults live both here and as fallbacks in `ai-chatbot.php` / rag manager (`deepseek/deepseek-v4-flash-0731`, `qwen/qwen3-embedding-8b`) — keep them in sync.
- `includes/class-admin-settings.php` — admin menu, `register_setting` options (all prefixed `ai_chatbot_`), inline admin JS, and admin-only AJAX: file upload/delete (delete also removes vectors from Pinecone), lead deletion, conversation viewing, and waiting-for-human polling.
- `assets/js/chat-script.js` + `templates/chat-widget.php` — widget UI; parses the SSE stream, persists messages via `ai_chatbot_save_message`, restores history, and toggles `chat_type` between `ai` and `human` (`ai_chatbot_switch_mode`).

## Conventions

- User-facing strings, prompts, and admin labels are in Vietnamese; keep them that way.
- The README says the OpenRouter key can be set via an `OPENROUTER_API_KEY` constant in `wp-config.php`, but only the admin page honors it; `handle_chat_query()` and the RAG manager read `get_option('ai_chatbot_openrouter_api_key')` directly.
- Schema changes go in `ai_chatbot_activate()` (dbDelta), plus an explicit `ALTER` guard for existing installs as done for the FULLTEXT index; bump `AI_CHATBOT_VERSION` and the plugin header together.
