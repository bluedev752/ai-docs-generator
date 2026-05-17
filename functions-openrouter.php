<?php

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
    return $data['choices'][0]['message']['content'] ?? '';
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
    if (strtoupper($method) === 'POST') {
        $json = json_encode($payload);
        echo "OpenRouter request length: " . strlen($json) . " bytes\n";
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    if ($curlError) {
        throw new RuntimeException("OpenRouter cURL error: $curlError");
    }
    if ($httpCode !== 200 || !$response) {
        throw new RuntimeException("OpenRouter API error (HTTP $httpCode): $response");
    }
    return json_decode($response, true);
}
