<?php
/**
 * Class OpenRouter_API_Client
 *
 * Handles API interaction with OpenRouter endpoints for
 * Vector Embeddings and Content Generation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OpenRouter_API_Client {

    private $api_key;
    private $base_url = 'https://openrouter.ai/api/v1';
    private $chat_model;
    private $embed_model;

    public function __construct( $api_key, $chat_model = 'openai/gpt-oss-120b:free', $embed_model = 'nvidia/llama-nemotron-embed-vl-1b-v2:free' ) {
        $this->api_key = trim($api_key);
        $this->chat_model = trim($chat_model);
        $this->embed_model = trim($embed_model);
    }

    /**
     * Get embeddings for a text block.
     *
     * @param string $text The chunk text to embed.
     * @return array|WP_Error Array of floats representing embedding vector, or WP_Error.
     */
    public function get_embedding( $text ) {
        if ( empty( $text ) ) {
            return new WP_Error( 'empty_text', 'Cannot embed empty text' );
        }

        $endpoint = "{$this->base_url}/embeddings";

        $body = array(
            'model' => $this->embed_model,
            'input' => $text
        );

        $response = wp_remote_post( $endpoint, array(
            'method'    => 'POST',
            'headers'   => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => site_url(),
                'X-Title'       => 'WordPress_Chatbot'
            ),
            'body'      => wp_json_encode( $body ),
            'timeout'   => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        if ( 200 !== $response_code ) {
            $err_data = json_decode( $response_body, true );
            $err_msg  = isset( $err_data['error']['message'] ) ? $err_data['error']['message'] : 'Unknown error';
            return new WP_Error( 'openrouter_api_error', "Embeddings API returned HTTP $response_code: $err_msg" );
        }

        $data = json_decode( $response_body, true );

        if ( isset( $data['data'][0]['embedding'] ) ) {
            return $data['data'][0]['embedding'];
        }

        return new WP_Error( 'parsing_error', 'Failed to retrieve embedding vector from OpenRouter response' );
    }

    /**
     * Reformulates the user's query based on the conversation history.
     */
    public function reformulate_query( $message, $history ) {
        if ( empty( $history ) ) {
            return $message;
        }

        $endpoint = "{$this->base_url}/chat/completions";

        $system_instruction = "Bạn là một AI hỗ trợ viết lại câu hỏi. Nhiệm vụ của bạn là dựa vào ngữ cảnh của các tin nhắn trước đó, hãy viết lại câu hỏi mới nhất của người dùng thành một câu hoàn chỉnh, độc lập và rõ ràng ngữ nghĩa, KHÔNG thay đổi ý định của họ. CHỈ trả lời bằng câu đã viết lại, không thêm lời giải thích.";

        $messages = array();

        // Use system role for faster inference
        $messages[] = array(
            'role' => 'system',
            'content' => $system_instruction
        );

        foreach ( $history as $msg ) {
            $messages[] = array(
                'role' => $msg['role'],
                'content' => $msg['content']
            );
        }

        $messages[] = array(
            'role' => 'user',
            'content' => $message
        );

        $body = array(
            'model'       => $this->chat_model,
            'messages'    => $messages,
            'temperature' => 0.1,
            'max_tokens'  => 150
        );

        $response = wp_remote_post( $endpoint, array(
            'headers'   => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => site_url(),
                'X-Title'       => 'WordPress_Chatbot'
            ),
            'body'      => wp_json_encode( $body ),
            'timeout'   => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return $message;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $response_code ) {
            return $message;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            $reformulated = trim( $data['choices'][0]['message']['content'] );
            return empty( $reformulated ) ? $message : $reformulated;
        }

        return $message;
    }

    /**
     * Generates a chat answer using the specified OpenRouter chat model.
     *
     * @param string $message User question.
     * @param string $system_instruction System prompt / RAG context text.
     * @return string|WP_Error Generated answer string, or WP_Error.
     */
    public function generate_chat_answer( $message, $system_instruction, $history = array() ) {
        $messages = array();

        // Use proper system role for context — this is much faster than stuffing in user message
        $messages[] = array(
            'role' => 'system',
            'content' => $system_instruction
        );

        if ( ! empty( $history ) && is_array( $history ) ) {
            foreach ( $history as $msg ) {
                $messages[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }

        $messages[] = array(
            'role' => 'user',
            'content' => $message
        );

        $body = array(
            'model' => $this->chat_model,
            'messages' => $messages,
            'temperature' => 0.1,
            'max_tokens' => 500
        );

        $response = wp_remote_post( $endpoint, array(
            'method'    => 'POST',
            'headers'   => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => site_url(),
                'X-Title'       => get_bloginfo( 'name' )
            ),
            'body'      => wp_json_encode( $body ),
            'timeout'   => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        if ( 200 !== $response_code ) {
            $err_data = json_decode( $response_body, true );
            $err_msg  = isset( $err_data['error']['message'] ) ? $err_data['error']['message'] : 'Unknown error';
            return new WP_Error( 'openrouter_api_error', "Chat API returned HTTP $response_code: $err_msg" );
        }

        $data = json_decode( $response_body, true );

        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            return $data['choices'][0]['message']['content'];
        }

        return new WP_Error( 'parsing_error', 'Failed to retrieve chatbot response text from OpenRouter API' );
    }

    /**
     * Generates a chat answer using the specified OpenRouter chat model with SSE Streaming.
     */
    public function generate_chat_answer_stream( $message, $system_instruction, $history = array() ) {
        $endpoint = "{$this->base_url}/chat/completions";

        $messages = array();

        // Use proper system role — faster inference and better instruction following
        $messages[] = array(
            'role' => 'system',
            'content' => $system_instruction
        );

        if ( ! empty( $history ) && is_array( $history ) ) {
            foreach ( $history as $msg ) {
                $messages[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }

        $messages[] = array(
            'role' => 'user',
            'content' => $message
        );

        $body = array(
            'model'       => $this->chat_model,
            'messages'    => $messages,
            'stream'      => true,
            'temperature' => 0.1,
            'max_tokens'  => 500
        );

        $ch = curl_init( $endpoint );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $body ) );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json',
            'HTTP-Referer: ' . site_url(),
            'Referer: ' . site_url(),
            'X-Title: WordPress_Chatbot'
        ));

        // Ensure headers are sent
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        
        // Disable output buffering
        while ( ob_get_level() > 0 ) {
            ob_end_flush();
        }
        flush();

        // Use a write function to handle the stream
        curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) {
            echo $data;
            flush();
            return strlen( $data );
        });

        curl_exec( $ch );

        if ( curl_errno( $ch ) ) {
            $error_msg = curl_error( $ch );
            echo "data: [ERROR] " . $error_msg . "\n\n";
        }
        
        curl_close( $ch );
        exit;
    }
}
