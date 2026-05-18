<?php

class OpenRouterException extends RuntimeException {
    public int $httpCode;
    public array $errorData;
    public string $rawResponse;

    public function __construct(string $message, int $httpCode, array $errorData, string $rawResponse) {
        parent::__construct($message);
        $this->httpCode = $httpCode;
        $this->errorData = $errorData;
        $this->rawResponse = $rawResponse;
    }
}

/**
 * Send a conversation to the AI and get the next response.
 *
 * Append the returned assistant message to $messages for subsequent turns.
 *
 * @param  array  $messages  Full conversation history
 * @param  string $model     Model identifier
 * @return string            Assistant message content
 *
 * @example
 *   $messages = [];
 *   $messages[] = ['role' => 'user', 'content' => 'Hello'];
 *   $reply = openrouter_chat($messages, $model);
 *   $messages[] = ['role' => 'assistant', 'content' => $reply];
 *   $messages[] = ['role' => 'user', 'content' => 'How are you?']; // Next turn
 *   $reply2 = openrouter_chat($messages, $model);
 */
function openrouter_chat(array $messages, string $model): string {
    $data = openrouter_request('/v1/chat/completions', [
        'model' => $model,
        'messages' => $messages,
    ]);

    $content = $data['choices'][0]['message']['content'] ?? '';
    if ($content === '') {
        $raw = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        throw new RuntimeException("OpenRouter returned empty content. Full response:\n$raw");
    }

    return $content;
}

function openrouter_free_models(): array {
    $data = openrouter_request('/v1/models', [], 'GET');
    $free = [];
    foreach ($data['data'] ?? [] as $model) {
        $pricing = $model['pricing'] ?? [];
        if (floatval($pricing['prompt'] ?? 1) === 0.0 && floatval($pricing['completion'] ?? 1) === 0.0) {
            $free[] = $model['id'];
        }
    }
    return $free;
}

function openrouter_all_models(): array {
    $data = openrouter_request('/v1/models', [], 'GET');
    $models = [];
    foreach ($data['data'] ?? [] as $model) {
        $models[] = $model['id'];
    }
    return $models;
}

function openrouter_request(string $endpoint, array $payload = [], string $method = 'POST'): array {
    $url = OPENROUTER_BASE_URL . $endpoint;
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'Content-Type: application/json',
            'HTTP-Referer: https://localhost',
            'X-Title: AI Docs Generator',
        ],
    ];

    // Optional SOCKS5 proxy
    if (SOCKS5_PROXY) {
        $options[CURLOPT_PROXY] = SOCKS5_PROXY;
        $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
    }

    if (strtoupper($method) === 'POST') {
        $json = json_encode($payload);
        echo dim("Request size: " . intval(strlen($json) / 1024) . " KB\n");
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($ch, $options);
    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    if ($curlError) {
        throw new RuntimeException("OpenRouter cURL error: $curlError");
    }

    if ($httpCode !== 200 || !$rawResponse) {
        $errorData = json_decode($rawResponse, true) ?: [];
        throw new OpenRouterException(
            "OpenRouter API error (HTTP $httpCode)",
            $httpCode,
            $errorData,
            $rawResponse
        );
    }

    echo dim("Response size: " . intval(strlen($rawResponse) / 1024) . " KB\n");

    $result = json_decode($rawResponse, true);
    if (!is_array($result)) {
        throw new RuntimeException("OpenRouter returned invalid JSON (HTTP $httpCode). Raw: $rawResponse");
    }

    return $result;
}
