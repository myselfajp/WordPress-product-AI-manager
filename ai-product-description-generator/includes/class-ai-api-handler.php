<?php
/**
 * API Handler Class
 * Handles all AI API calls (Gemini, Groq, OpenAI, Claude, DeepSeek)
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Product_Description_API_Handler {
    
    public function call_api($prompt, $settings) {
        $api_provider = $settings['api_provider'];
        $api_key = $settings['api_key'];
        $model = isset($settings['model']) ? $settings['model'] : '';
        
        switch ($api_provider) {
            case 'gemini':
                return $this->call_gemini_api($prompt, $api_key, $model);
            case 'groq':
                return $this->call_groq_api($prompt, $api_key, $model);
            case 'claude':
                return $this->call_claude_api($prompt, $api_key, $model);
            case 'openai':
                return $this->call_openai_api($prompt, $api_key, $model);
            case 'deepseek':
                return $this->call_deepseek_api($prompt, $api_key, $model);
            default:
                return new WP_Error('invalid_provider', 'Invalid API provider');
        }
    }
    
    private function call_gemini_api($prompt, $api_key, $model) {
        $model = $model ?: 'gemini-pro';
        
        // Remove 'models/' prefix if present
        $model = str_replace('models/', '', $model);
        
        $url = 'https://generativelanguage.googleapis.com/v1/models/' . $model . ':generateContent?key=' . $api_key;
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'contents' => array(
                    array(
                        'parts' => array(
                            array('text' => $prompt)
                        )
                    )
                )
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            return $body['candidates'][0]['content']['parts'][0]['text'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    private function call_groq_api($prompt, $api_key, $model) {
        $model = $model ?: 'mixtral-8x7b-32768';
        
        $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 2048
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    private function call_claude_api($prompt, $api_key, $model) {
        $model = $model ?: 'claude-3-5-sonnet-20241022';
        
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode(array(
                'model' => $model,
                'max_tokens' => 2048,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                )
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['content'][0]['text'])) {
            return $body['content'][0]['text'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    private function call_openai_api($prompt, $api_key, $model) {
        $model = $model ?: 'gpt-3.5-turbo';
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 2048
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    private function call_deepseek_api($prompt, $api_key, $model) {
        $model = $model ?: 'deepseek-chat';
        
        $response = wp_remote_post('https://api.deepseek.com/v1/chat/completions', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 2048
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }
        
        if (isset($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        
        return new WP_Error('invalid_response', 'Invalid API response');
    }
}

