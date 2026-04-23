<?php

if (!defined('ABSPATH')) {
    exit;
}

class PurgeCF_API {
    private $last_error_code = 'none';

    public function purge_everything($zone_id, $api_key, $enforce_https = true) {
        $this->last_error_code = 'none';

        if (!$this->is_valid_zone_id($zone_id) || !$this->is_valid_api_token($api_key)) {
            $this->last_error_code = 'invalid_input';
            return false;
        }

        $url = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache';
        $args = [
            'method'  => 'POST',
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => (bool) $enforce_https,
            'user-agent' => 'cf-purge-everything/1.0.0; ' . home_url('/'),
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode(['purge_everything' => true]),
        ];

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $this->last_error_code = 'request_error';
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            $this->last_error_code = 'http_' . (string) $status_code;
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($result)) {
            $this->last_error_code = 'invalid_json';
            return false;
        }

        if (!(isset($result['success']) && true === $result['success'])) {
            $this->last_error_code = 'api_unsuccessful';
            return false;
        }

        return true;
    }

    public function get_last_error_code() {
        return $this->last_error_code;
    }

    private function is_valid_zone_id($zone_id) {
        return is_string($zone_id) && preg_match('/^[a-f0-9]{32}$/', strtolower($zone_id));
    }

    private function is_valid_api_token($api_key) {
        return is_string($api_key) && preg_match('/^[A-Za-z0-9_-]{20,120}$/', $api_key);
    }
}
