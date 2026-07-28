<?php
/**
 * Class AI_Chatbot_Manager
 *
 * Manages document ingestion, chunking, database storage,
 * and vector-based Cosine Similarity searching.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Chatbot_Manager
{

    /**
     * Parse and chunk a file, then request embeddings and save to DB.
     *
     * @param int $doc_id Document ID from {wp_prefix}ai_chatbot_documents.
     * @param string $file_path Local path to the uploaded file.
     * @param string $file_name Original filename.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function process_and_index_document($doc_id, $file_path, $file_name)
    {
        global $wpdb;

        $api_key = get_option('ai_chatbot_openrouter_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', 'Please supply an OpenRouter API Key in the settings first.');
        }

        // 1. Extract raw text based on file type
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $text = '';

        if ('txt' === $ext) {
            $text = file_get_contents($file_path);
        } elseif ('pdf' === $ext) {
            $text = self::extract_text_from_pdf($file_path);
        } else {
            return new WP_Error('unsupported_format', 'Unsupported file format. Only .txt and .pdf are supported currently.');
        }

        if (empty(trim($text))) {
            return new WP_Error('empty_document', 'No readable text could be extracted from the uploaded document.');
        }

        // 2. Chunk the text
        $chunk_size = intval(get_option('ai_chatbot_chunk_size', '1000'));
        $chunk_overlap = intval(get_option('ai_chatbot_chunk_overlap', '200'));
        $chunks = $this->chunk_text($text, $file_name, $chunk_size, $chunk_overlap);

        if (empty($chunks)) {
            return new WP_Error('chunking_failed', 'Failed to chunk the document contents.');
        }

        // 3. Generate embeddings and save to database
        $chat_model = get_option('ai_chatbot_openrouter_model');
        if (empty($chat_model)) {
            $chat_model = 'google/gemini-2.5-flash';
        }
        $embed_model = get_option('ai_chatbot_openrouter_embed_model');
        if (empty($embed_model)) {
            $embed_model = 'qwen/qwen3-embedding-8b';
        }
        $client = new OpenRouter_API_Client($api_key, $chat_model, $embed_model);
        $table_chunks = $wpdb->prefix . 'ai_chatbot_chunks';

        $successful_chunks = 0;
        $pinecone_vectors = array();

        foreach ($chunks as $chunk_data) {
            $child_content = $chunk_data['child'];
            $parent_content = $chunk_data['parent'];

            // Trim and clean chunk content
            $child_content = trim($child_content);
            if (empty($child_content)) {
                continue;
            }

            // Get embedding vector for the CHILD chunk
            $embedding = $client->get_embedding($child_content);

            if (is_wp_error($embedding)) {
                // If one chunk fails, log and continue, or fail entirely depending on strictness.
                // We'll log to WordPress error log and try to continue so partial results aren't lost.
                error_log('Hateco AI Chatbot Indexing Error: ' . $embedding->get_error_message());
                continue;
            }

            $metadata_json = isset($chunk_data['metadata']) ? $chunk_data['metadata'] : '{}';

            // Save chunk in DB
            $inserted = $wpdb->insert(
                $table_chunks,
                array(
                    'document_id' => $doc_id,
                    'content' => $child_content,
                    'parent_content' => $parent_content,
                    'embedding' => wp_json_encode($embedding),
                    'metadata' => $metadata_json,
                    'token_count' => intval(strlen($child_content) / 4) // rough estimate of tokens
                ),
                array('%d', '%s', '%s', '%s', '%s', '%d')
            );

            if ($inserted) {
                $chunk_id = $wpdb->insert_id;
                $successful_chunks++;

                // Prepare vector for Pinecone (only index the child content)
                $pinecone_metadata = array(
                    'document_id' => $doc_id,
                    'content' => $child_content
                );

                // Merge extracted metadata into Pinecone metadata
                $parsed_meta = json_decode($metadata_json, true);
                if (is_array($parsed_meta)) {
                    foreach ($parsed_meta as $mk => $mv) {
                        if (!empty($mv))
                            $pinecone_metadata[$mk] = $mv;
                    }
                }

                $pinecone_vectors[] = array(
                    'id' => strval($chunk_id),
                    'values' => $embedding,
                    'metadata' => $pinecone_metadata
                );
            }
        }

        // Push to Pinecone if configured
        if (!empty($pinecone_vectors)) {
            $pinecone_result = $this->pinecone_upsert($pinecone_vectors);
            if (is_wp_error($pinecone_result)) {
                return $pinecone_result; // Return the exact error so UI can display it
            }
        }

        // Update document status & actual chunk count in the DB
        $table_docs = $wpdb->prefix . 'ai_chatbot_documents';
        $wpdb->update(
            $table_docs,
            array(
                'chunk_count' => $successful_chunks,
                'status' => 'indexed'
            ),
            array('id' => $doc_id),
            array('%d', '%s'),
            array('%d')
        );

        if (0 === $successful_chunks) {
            return new WP_Error('indexing_failed', 'Failed to generate embeddings for all chunks of the document. Please verify your API Key.');
        }

        return true;
    }

    /**
     * Upsert vectors to Pinecone
     * 
     * @param array $vectors Array of vectors to upsert
     * @return bool|WP_Error
     */
    private function pinecone_upsert($vectors)
    {
        $api_key = get_option('ai_chatbot_pinecone_api_key', '');
        $host = get_option('ai_chatbot_pinecone_host', '');

        if (empty($api_key) || empty($host)) {
            return false;
        }

        $host = rtrim($host, '/');
        $url = $host . '/vectors/upsert';

        $body = array(
            'vectors' => $vectors,
            'namespace' => ''
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Api-Key' => $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('Pinecone Upsert Error: ' . $response->get_error_message());
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if (200 !== $status) {
            $body_str = wp_remote_retrieve_body($response);
            $body_data = json_decode($body_str, true);
            $error_msg = isset($body_data['message']) ? $body_data['message'] : (isset($body_data['error']) ? json_encode($body_data['error']) : 'Pinecone API Error');
            error_log('Pinecone Upsert Failed. Status: ' . $status . ' Body: ' . $body_str);
            return new WP_Error('pinecone_error', 'Lỗi Pinecone (HTTP ' . $status . '): ' . $error_msg);
        }

        return true;
    }

    /**
     * Splits long text into chunks using Recursive Character Text Splitter algorithm.
     *
     * @param string $text Raw text.
     * @param string $doc_name Name of the document for metadata enrichment.
     * @param int $chunk_size Maximum size of each chunk (in characters).
     * @param int $chunk_overlap Size of overlap between chunks (in characters).
     * @return array List of chunk objects ['child' => '...', 'parent' => '...'].
     */
    private function chunk_text($text, $doc_name = '', $chunk_size = 1000, $chunk_overlap = 200)
    {
        // Semantic Pre-processing (V2.0)
        // Identify common topics and add metadata
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $lines = explode("\n", $text);

        $chunks = array();
        $current_chunk = "";
        $current_topic = "";
        $current_major = "";
        $current_year = "2026";

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            // Heuristic detection for Major
            if (preg_match('/(?:Ngành|Chuyên ngành)\s*[:\-]?\s*(.*)/ui', $line, $matches)) {
                $current_major = trim($matches[1]);
            }
            if (preg_match('/Mã\s*ngành\s*[:\-]?\s*([A-Z0-9]+)/ui', $line, $matches)) {
                if (empty($current_major))
                    $current_major = trim($matches[1]);
            }

            // Heuristic detection for Topics (Headings)
            if (preg_match('/^(Học phí|Điểm chuẩn|Chỉ tiêu|Học bổng|Phương thức xét tuyển|Tổ hợp xét tuyển|Chương trình đào tạo)[:\-\.]?(.*)/ui', $line, $matches)) {
                // If we hit a new topic, and current chunk is big enough, we save it.
                if (mb_strlen($current_chunk) > 50) {
                    $metadata = wp_json_encode(array(
                        'topic' => $current_topic,
                        'major' => $current_major,
                        'year' => $current_year
                    ));

                    $enriched = "Tên tài liệu: " . $doc_name . "\nNội dung: " . trim($current_chunk);
                    $chunks[] = array(
                        'child' => $enriched,
                        'parent' => $enriched,
                        'metadata' => $metadata
                    );
                    $current_chunk = "";
                }
                $current_topic = mb_strtolower(trim($matches[1]), 'UTF-8');
            }

            $current_chunk .= $line . "\n";

            // Fallback if chunk gets too large (exceeds chunk_size)
            if (mb_strlen($current_chunk) > $chunk_size) {
                // Do the standard recursive split on this piece
                $sub_chunks = $this->recursive_split($current_chunk, $chunk_size, $chunk_overlap, array(". ", "\n", " "));
                foreach ($sub_chunks as $sc) {
                    $metadata = wp_json_encode(array(
                        'topic' => $current_topic,
                        'major' => $current_major,
                        'year' => $current_year
                    ));
                    $enriched = "Tên tài liệu: " . $doc_name . "\nNội dung: " . trim($sc);
                    $chunks[] = array('child' => $enriched, 'parent' => $enriched, 'metadata' => $metadata);
                }
                $current_chunk = "";
            }
        }

        // Add remaining chunk
        if (mb_strlen(trim($current_chunk)) > 0) {
            $metadata = wp_json_encode(array(
                'topic' => $current_topic,
                'major' => $current_major,
                'year' => $current_year
            ));
            $enriched = "Tên tài liệu: " . $doc_name . "\nNội dung: " . trim($current_chunk);
            $chunks[] = array('child' => $enriched, 'parent' => $enriched, 'metadata' => $metadata);
        }

        return $chunks;
    }

    private function recursive_split($text, $chunk_size, $chunk_overlap, $separators)
    {
        $final_chunks = array();

        $separator = $separators[count($separators) - 1];
        foreach ($separators as $s) {
            if ($s === '' || mb_strpos($text, $s) !== false) {
                $separator = $s;
                break;
            }
        }

        $splits = array();
        if ($separator !== '') {
            $splits = explode($separator, $text);
        } else {
            $splits = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        }

        $good_splits = array();
        foreach ($splits as $s) {
            if (mb_strlen($s) <= $chunk_size) {
                $good_splits[] = $s;
            } else {
                if (!empty($good_splits)) {
                    $merged = $this->merge_splits($good_splits, $separator, $chunk_size, $chunk_overlap);
                    $final_chunks = array_merge($final_chunks, $merged);
                    $good_splits = array();
                }

                $next_separators = $separators;
                $idx = array_search($separator, $separators);
                if ($idx !== false && $idx < count($separators) - 1) {
                    $next_separators = array_slice($separators, $idx + 1);
                } else {
                    $next_separators = array('');
                }

                $other_info = $this->recursive_split($s, $chunk_size, $chunk_overlap, $next_separators);
                $final_chunks = array_merge($final_chunks, $other_info);
            }
        }

        if (!empty($good_splits)) {
            $merged = $this->merge_splits($good_splits, $separator, $chunk_size, $chunk_overlap);
            $final_chunks = array_merge($final_chunks, $merged);
        }

        return $final_chunks;
    }

    private function merge_splits($splits, $separator, $chunk_size, $chunk_overlap)
    {
        $docs = array();
        $current_doc = array();
        $total = 0;
        $sep_len = mb_strlen($separator);

        foreach ($splits as $s) {
            $len = mb_strlen($s);
            $new_len = $total + $len + (empty($current_doc) ? 0 : $sep_len);

            if ($new_len > $chunk_size && $total > 0) {
                $docs[] = implode($separator, $current_doc) . $separator;

                while ($total > $chunk_overlap || ($total + $len + $sep_len > $chunk_size && $total > 0)) {
                    $removed = array_shift($current_doc);
                    $total -= mb_strlen($removed) + (empty($current_doc) ? 0 : $sep_len);
                }
            }
            $current_doc[] = $s;
            $total += $len + (count($current_doc) > 1 ? $sep_len : 0);
        }
        if ($total > 0) {
            $docs[] = implode($separator, $current_doc);
        }
        return $docs;
    }

    /**
     * Perform local similarity search using vector Cosine Similarity.
     *
     * @param string $query User query text.
     * @param int $k Number of chunks to return.
     * @return array Array of associative arrays containing chunks: [ 'content', 'document_name', 'similarity' ]
     */
    public function search_similar_chunks($query, $k = 5, $threshold = 0.25)
    {
        global $wpdb;

        $api_key = get_option('ai_chatbot_openrouter_api_key', '');
        if (empty($api_key)) {
            return array();
        }

        $fetch_count = $k * 4; // fetch more candidates for better filtering
        $table_chunks = $wpdb->prefix . 'ai_chatbot_chunks';
        $table_docs = $wpdb->prefix . 'ai_chatbot_documents';

        // 1. SPARSE RETRIEVAL FIRST (instant, no API call needed)
        $sparse_results = array();
        $words = preg_split('/\s+/', trim($query));
        $boolean_query = '';
        foreach ($words as $word) {
            if (strlen($word) > 2) {
                $boolean_query .= '+' . $word . '* ';
            }
        }
        $boolean_query = trim($boolean_query);
        if (!empty($boolean_query)) {
            $sql = $wpdb->prepare("
                SELECT c.id 
                FROM $table_chunks c
                JOIN $table_docs d ON c.document_id = d.id
                WHERE d.status = 'indexed' AND MATCH(c.content) AGAINST(%s IN BOOLEAN MODE)
                LIMIT %d
            ", $boolean_query, $fetch_count);
            $fts_ids = $wpdb->get_col($sql);
            if (!empty($fts_ids)) {
                $sparse_results = array_map('intval', $fts_ids);
            }
        }

        // 2. DENSE RETRIEVAL (Pinecone or DB fallback)
        $dense_results = array(); // chunk_id => score
        $dense_scores_map = array(); // preserve original dense scores
        $chat_model = get_option('ai_chatbot_openrouter_model');
        if (empty($chat_model))
            $chat_model = 'google/gemini-2.5-flash';
        $embed_model = get_option('ai_chatbot_openrouter_embed_model');
        if (empty($embed_model))
            $embed_model = 'qwen/qwen3-embedding-8b';

        // Check embedding cache first (saves 0.5-2s on repeated queries)
        $embed_cache_key = 'ai_embed_' . md5(strtolower(trim($query)));
        $query_vector = get_transient($embed_cache_key);

        if ($query_vector === false) {
            $client = new OpenRouter_API_Client($api_key, $chat_model, $embed_model);
            $query_vector = $client->get_embedding($query);
            if (!is_wp_error($query_vector)) {
                set_transient($embed_cache_key, $query_vector, HOUR_IN_SECONDS);
            }
        }

        $pinecone_api_key = get_option('ai_chatbot_pinecone_api_key', '');
        $pinecone_host = get_option('ai_chatbot_pinecone_host', '');

        if (!empty($query_vector) && !is_wp_error($query_vector) && !empty($pinecone_api_key) && !empty($pinecone_host)) {
            $host = rtrim($pinecone_host, '/');
            $url = $host . '/query';
            $body = array('vector' => $query_vector, 'topK' => $fetch_count, 'includeMetadata' => true);
            $response = wp_remote_post($url, array(
                'headers' => array('Api-Key' => $pinecone_api_key, 'Content-Type' => 'application/json'),
                'body' => wp_json_encode($body),
                'timeout' => 15
            ));

            if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                $body_data = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body_data['matches'])) {
                    foreach ($body_data['matches'] as $match) {
                        $chunk_id = intval($match['id']);
                        $score = isset($match['score']) ? floatval($match['score']) : 0;
                        if ($chunk_id > 0) {
                            $dense_results[] = $chunk_id;
                            $dense_scores_map[$chunk_id] = $score;
                        }
                    }
                }
            }
        }

        // 3. RECIPROCAL RANK FUSION (RRF)
        $rrf_scores = array();
        $constant_k = 60;

        foreach ($dense_results as $rank => $chunk_id) {
            if (!isset($rrf_scores[$chunk_id]))
                $rrf_scores[$chunk_id] = 0;
            $rrf_scores[$chunk_id] += 1 / ($constant_k + $rank + 1);
        }

        foreach ($sparse_results as $rank => $chunk_id) {
            if (!isset($rrf_scores[$chunk_id]))
                $rrf_scores[$chunk_id] = 0;
            $rrf_scores[$chunk_id] += 1 / ($constant_k + $rank + 1);
        }

        // Boost chunks that appear in BOTH dense and sparse (intersection bonus)
        foreach ($rrf_scores as $chunk_id => &$score) {
            $in_dense = in_array($chunk_id, $dense_results);
            $in_sparse = in_array($chunk_id, $sparse_results);
            if ($in_dense && $in_sparse) {
                $score *= 1.5; // 50% boost for appearing in both retrieval methods
            }
        }
        unset($score);

        if (empty($rrf_scores)) {
            return array();
        }

        arsort($rrf_scores);
        $top_rrf_ids = array_slice(array_keys($rrf_scores), 0, $fetch_count);
        $ids_str = implode(',', $top_rrf_ids);

        // Fetch chunk data from DB
        $db_chunks = $wpdb->get_results("
            SELECT c.id, c.content, c.parent_content, c.metadata, d.name as document_name 
            FROM $table_chunks c
            JOIN $table_docs d ON c.document_id = d.id
            WHERE c.id IN ($ids_str)
        ", ARRAY_A);

        if (empty($db_chunks))
            return array();

        // Map chunks by ID
        $chunks_map = array();
        $docs_for_rerank = array();
        foreach ($db_chunks as $chunk) {
            $chunks_map[$chunk['id']] = $chunk;
        }

        // Prepare list for reranker
        foreach ($top_rrf_ids as $id) {
            if (isset($chunks_map[$id])) {
                $docs_for_rerank[] = $chunks_map[$id]['content'];
            }
        }

        // 4. CROSS-ENCODER RERANKING via OpenRouter
        $enable_reranker = get_option('ai_chatbot_enable_reranker', '0');
        $reranked_indices = array();

        if ('1' === $enable_reranker) {
            $rerank_model = get_option('ai_chatbot_openrouter_rerank_model');
            if (empty($rerank_model))
                $rerank_model = 'cohere/rerank-v3.5';

            $rerank_url = "https://openrouter.ai/api/v1/rerank";
            $rerank_body = array(
                'model' => $rerank_model,
                'query' => $query,
                'documents' => $docs_for_rerank
            );
            $rerank_response = wp_remote_post($rerank_url, array(
                'headers' => array('Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'),
                'body' => wp_json_encode($rerank_body),
                'timeout' => 15
            ));

            if (!is_wp_error($rerank_response) && 200 === wp_remote_retrieve_response_code($rerank_response)) {
                $r_data = json_decode(wp_remote_retrieve_body($rerank_response), true);
                if (!empty($r_data['results'])) {
                    foreach ($r_data['results'] as $res) {
                        // $res['index'] maps to $docs_for_rerank index
                        $reranked_indices[] = array(
                            'index' => $res['index'],
                            'score' => isset($res['relevance_score']) ? $res['relevance_score'] : 0
                        );
                    }
                }
            }
        }

        // If reranker disabled or failed: use RRF order WITH actual similarity scores
        if (empty($reranked_indices)) {
            foreach ($docs_for_rerank as $i => $doc) {
                $chunk_id = $top_rrf_ids[$i];
                // Use actual Pinecone score if available, otherwise use RRF score
                $actual_score = isset($dense_scores_map[$chunk_id]) ? $dense_scores_map[$chunk_id] : $rrf_scores[$chunk_id];
                $reranked_indices[] = array('index' => $i, 'score' => $actual_score);
            }
            // Sort by actual score descending
            usort($reranked_indices, function ($a, $b) {
                return $b['score'] <=> $a['score'];
            });
        }

        // 5. Build Final Context using Parent Chunks
        $final_chunks = array();
        $seen_parents = array();

        foreach ($reranked_indices as $r) {
            if (count($final_chunks) >= $k)
                break;

            // Skip low-relevance chunks when reranker is off (noise filter)
            $enable_reranker_check = get_option('ai_chatbot_enable_reranker', '0');
            if ('1' !== $enable_reranker_check && $r['score'] < $threshold)
                continue;

            $orig_index = $r['index'];
            if (!isset($top_rrf_ids[$orig_index]))
                continue;
            $chunk_id = $top_rrf_ids[$orig_index];
            if (!isset($chunks_map[$chunk_id]))
                continue;
            $chunk = $chunks_map[$chunk_id];

            $parent_content = !empty($chunk['parent_content']) ? $chunk['parent_content'] : $chunk['content'];

            // Deduplicate: if we already included this parent chunk, skip it to save tokens
            $hash = md5($parent_content);
            if (isset($seen_parents[$hash]))
                continue;

            $seen_parents[$hash] = true;
            $final_chunks[] = array(
                'content' => $parent_content,
                'document_name' => $chunk['document_name'],
                'similarity' => $r['score'],
                'metadata' => isset($chunk['metadata']) ? $chunk['metadata'] : ''
            );
        }

        return $final_chunks;
    }

    /**
     * Calculates the Cosine Similarity between two floating-point vector arrays.
     */
    private function cosine_similarity($vec1, $vec2)
    {
        $dot_product = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;
        $count = count($vec1);

        for ($i = 0; $i < $count; $i++) {
            $dot_product += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }

        if ($norm1 == 0.0 || $norm2 == 0.0) {
            return 0.0;
        }

        return $dot_product / (sqrt($norm1) * sqrt($norm2));
    }

    /**
     * Highly robust pure PHP PDF Text Extractor.
     * Parses standard compressed streams (FlateDecode) and extracts raw Tj/TJ content.
     */
    public static function extract_text_from_pdf($file_path)
    {
        if (!file_exists($file_path)) {
            return '';
        }

        $content = file_get_contents($file_path);
        if (!$content) {
            return '';
        }

        $text = '';

        // Find all object blocks in the PDF
        preg_match_all("/\s*(\d+\s+\d+\s+obj.*?.?endobj)/s", $content, $objects);

        if (!empty($objects[1])) {
            foreach ($objects[1] as $object) {
                // If it is a compressed stream using FlateDecode
                if (preg_match("/\/Filter\s*\/FlateDecode/i", $object) && preg_match("/stream\r?\n(.*?)\r?\nendstream/is", $object, $stream)) {
                    $data = $stream[1];
                    // Decompress the stream
                    $decompressed = @gzuncompress($data);
                    if (!$decompressed) {
                        // Attempt inflate if zlib header is slightly offset or omitted
                        $decompressed = @gzinflate(substr($data, 2, -4));
                    }
                    if ($decompressed) {
                        // 1. Tj matches
                        preg_match_all("/\((.*?)\)\s*Tj/is", $decompressed, $tj_matches);
                        foreach ($tj_matches[1] as $chunk) {
                            $text .= self::clean_pdf_string($chunk) . ' ';
                        }

                        // 2. TJ matches (bracketed array of strings and positioning offsets)
                        preg_match_all("/\[(.*?)\]\s*TJ/is", $decompressed, $tj_brackets);
                        foreach ($tj_brackets[1] as $bracket_contents) {
                            preg_match_all("/\((.*?)\)/is", $bracket_contents, $nested_strings);
                            foreach ($nested_strings[1] as $chunk) {
                                $text .= self::clean_pdf_string($chunk) . ' ';
                            }
                        }
                    }
                } else {
                    // Extract uncompressed text streams Tj/TJ
                    preg_match_all("/\((.*?)\)\s*Tj/is", $object, $tj_matches);
                    foreach ($tj_matches[1] as $chunk) {
                        $text .= self::clean_pdf_string($chunk) . ' ';
                    }
                }
            }
        } else {
            // Fallback: search for brackets in raw file if PDF structure is corrupted
            preg_match_all("/\((.*?)\)/", $content, $fallback_matches);
            foreach ($fallback_matches[1] as $chunk) {
                $text .= self::clean_pdf_string($chunk) . ' ';
            }
        }

        // Clean up whitespaces
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Helper to clean up PDF escape codes and return plain UTF-8.
     */
    private static function clean_pdf_string($str)
    {
        // PDF strings backslash escapes
        $str = str_replace(
            array('\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'),
            array('(', ')', '\\', "\n", "\r", "\t"),
            $str
        );
        // Remove non-printable / structural junk characters often present in binary streams
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str);
    }
}
