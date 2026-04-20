<?php
/**
 * Crypto helpers for API key encryption and decryption.
 *
 * @package PurgeCF
 */

if (!defined('ABSPATH')) {
    exit;
}

class purgecf_Crypto {
    private const API_KEY_ENCRYPTION_PREFIX = 'enc:v1:';
    private const API_KEY_CIPHER = 'aes-256-cbc';

    public static function is_encrypted_api_key($value) {
        return is_string($value) && strpos($value, self::API_KEY_ENCRYPTION_PREFIX) === 0;
    }

    public static function encrypt_api_key($token) {
        if (!is_string($token) || $token === '') {
            return '';
        }

        if (self::is_encrypted_api_key($token)) {
            return $token;
        }

        if (!function_exists('openssl_encrypt') || !function_exists('openssl_cipher_iv_length')) {
            return $token;
        }

        $key = self::get_encryption_key();
        if ($key === '') {
            return $token;
        }

        $iv_length = openssl_cipher_iv_length(self::API_KEY_CIPHER);
        if (!is_int($iv_length) || $iv_length <= 0) {
            return $token;
        }

        try {
            $iv = random_bytes($iv_length);
        } catch (Exception $e) {
            return $token;
        }

        $encrypted = openssl_encrypt($token, self::API_KEY_CIPHER, $key, 1, $iv);
        if (!is_string($encrypted) || $encrypted === '') {
            return $token;
        }

        $payload = rtrim(strtr(base64_encode($iv . $encrypted), '+/', '-_'), '=');
        return self::API_KEY_ENCRYPTION_PREFIX . $payload;
    }

    public static function decrypt_api_key($value) {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (!self::is_encrypted_api_key($value)) {
            return $value;
        }

        if (!function_exists('openssl_decrypt') || !function_exists('openssl_cipher_iv_length')) {
            return '';
        }

        $key = self::get_encryption_key();
        if ($key === '') {
            return '';
        }

        $payload = substr($value, strlen(self::API_KEY_ENCRYPTION_PREFIX));
        if (!is_string($payload) || $payload === '') {
            return '';
        }

        $padding = strlen($payload) % 4;
        if ($padding > 0) {
            $payload .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if (!is_string($decoded) || $decoded === '') {
            return '';
        }

        $iv_length = openssl_cipher_iv_length(self::API_KEY_CIPHER);
        if (!is_int($iv_length) || $iv_length <= 0 || strlen($decoded) <= $iv_length) {
            return '';
        }

        $iv = substr($decoded, 0, $iv_length);
        $ciphertext = substr($decoded, $iv_length);

        $decrypted = openssl_decrypt($ciphertext, self::API_KEY_CIPHER, $key, 1, $iv);
        return is_string($decrypted) ? $decrypted : '';
    }

    private static function get_encryption_key() {
        $salt = defined('AUTH_SALT') ? (string) AUTH_SALT : (string) wp_salt('auth');
        if ($salt === '') {
            return '';
        }

        return hash('sha256', 'purgecf|' . $salt, true);
    }
}