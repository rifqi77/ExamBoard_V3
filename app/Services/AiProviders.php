<?php

namespace App\Services;

use App\Models\AppConfigAi;
use App\Support\CryptoSecrets;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Provider abstraction over Pollinations (keyless default), Google Gemini,
 * Anthropic Claude, and OpenAI. PHP port of the original src/lib/ai-providers.ts.
 *
 * The admin picks the active text provider + model + temperature + image
 * provider in /admin/ai-settings (stored on the app_config_ai singleton row).
 * The teacher's AI-generate endpoint reads that row, then calls generateText()
 * / generateImage() here.
 *
 * No provider SDKs are used — every provider is called through its REST API
 * via Laravel's Http client, so the keyless default (Pollinations) works with
 * empty keys and the others need only an API key.
 *
 * All adapters take a "json" flag — when true we ask the provider to return
 * strict JSON so the caller can parse without regex acrobatics.
 */
class AiProviders
{
    // ─────────────────────────────────────────────────────────────────────
    // Static config (registries)
    // ─────────────────────────────────────────────────────────────────────

    /** @var string[] */
    public const TEXT_PROVIDERS = ['pollinations', 'gemini', 'claude', 'openai'];

    /** @var string[] */
    public const IMAGE_PROVIDERS = ['off', 'pollinations', 'gemini', 'openai'];

    /** Human labels for the admin UI. */
    public const TEXT_PROVIDER_LABELS = [
        'pollinations' => 'Pollinations.ai (free, no key)',
        'gemini' => 'Google Gemini',
        'claude' => 'Anthropic Claude',
        'openai' => 'OpenAI',
    ];

    public const IMAGE_PROVIDER_LABELS = [
        'off' => 'Off (no image generation)',
        'pollinations' => 'Pollinations.ai (Flux, free, no key)',
        'gemini' => 'Google Imagen (Gemini)',
        'openai' => 'OpenAI DALL·E / gpt-image',
    ];

    /**
     * Curated model list per text provider. Order = recommended → less so.
     * First entry is the default for that provider.
     *
     * @var array<string,string[]>
     */
    public const TEXT_MODELS = [
        'pollinations' => ['openai', 'openai-large', 'mistral', 'llama'],
        'gemini' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite'],
        'claude' => ['claude-sonnet-4-5', 'claude-haiku-4-5', 'claude-opus-4-1'],
        'openai' => ['gpt-4o', 'gpt-4o-mini', 'gpt-5'],
    ];

    // Image model is implicit per image provider (admin doesn't pick it).
    private const IMAGE_MODEL_GEMINI = 'imagen-4.0-generate-001';

    private const IMAGE_MODEL_OPENAI = 'gpt-image-1';

    private const IMAGE_MODEL_POLLINATIONS = 'flux';

    // Pollinations sits behind Cloudflare and frequently bounces 5xx/429
    // under load — short retry-with-backoff turns transient gateway errors
    // into a single successful response from the caller's perspective.
    private const POLLINATIONS_RETRIES = 3;

    /** @var int[] backoff in ms (parity); used as seconds via /1000 below */
    private const POLLINATIONS_BACKOFF_MS = [1500, 3000, 6000];

    /** Providers that actually need an API key (Pollinations has no key concept). */
    private const ENV_NAME = [
        'gemini' => 'GEMINI_API_KEY',
        'claude' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Validation / defaults (parity with ai-providers.ts)
    // ─────────────────────────────────────────────────────────────────────

    public static function defaultModelFor(string $provider): string
    {
        return self::TEXT_MODELS[$provider][0] ?? '';
    }

    public static function isValidTextProvider(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::TEXT_PROVIDERS, true);
    }

    public static function isValidImageProvider(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::IMAGE_PROVIDERS, true);
    }

    public static function isValidModelFor(string $provider, mixed $model): bool
    {
        return is_string($model) && in_array($model, self::TEXT_MODELS[$provider] ?? [], true);
    }

    /** Clamp to [0, 2]; fall back to 0.7 for non-numeric input (parity). */
    public static function clampTemperature(mixed $value): float
    {
        $n = is_numeric($value) ? (float) $value : NAN;
        if (! is_finite($n)) {
            return 0.7;
        }

        return max(0.0, min(2.0, $n));
    }

    /**
     * Read the active settings off the app_config_ai singleton row, coercing
     * anything missing / invalid to a stable shape (parity with
     * normalizeSettings + getActiveAiSettings).
     *
     * @return array{textProvider:string,textModel:string,temperature:float,imageProvider:string}
     */
    public static function activeSettings(): array
    {
        $row = AppConfigAi::singleton();

        $textProvider = self::isValidTextProvider($row->text_provider)
            ? $row->text_provider
            : 'claude';
        $textModel = self::isValidModelFor($textProvider, $row->text_model)
            ? $row->text_model
            : self::defaultModelFor($textProvider);
        $imageProvider = self::isValidImageProvider($row->image_provider)
            ? $row->image_provider
            : 'gemini';

        return [
            'textProvider' => $textProvider,
            'textModel' => $textModel,
            'temperature' => self::clampTemperature($row->temperature),
            'imageProvider' => $imageProvider,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Key resolution (DB-wins / env-fallback)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve the key for a keyed provider with DB-wins / env-fallback
     * semantics. Returns null when neither source has a usable value.
     */
    private static function resolveProviderKey(string $provider): ?string
    {
        try {
            $row = AppConfigAi::singleton();
            $map = is_array($row->ai_keys) ? $row->ai_keys : [];
            $blob = $map[$provider] ?? null;
            if (is_string($blob) && $blob !== '') {
                $fromDb = trim((string) (CryptoSecrets::decryptSecret($blob) ?? ''));
                if ($fromDb !== '') {
                    return $fromDb;
                }
            }
        } catch (\Throwable) {
            // Fall through to env on any DB / decryption hiccup.
        }

        $envKey = match ($provider) {
            'gemini' => (string) config('exam.ai.gemini_key', ''),
            'claude' => (string) config('exam.ai.anthropic_key', ''),
            'openai' => (string) config('exam.ai.openai_key', ''),
            default => '',
        };
        $envKey = trim($envKey);

        return $envKey !== '' ? $envKey : null;
    }

    /**
     * Boolean key-availability per provider for the admin UI.
     * Pollinations is always reachable (no key concept).
     *
     * @return array{pollinations:bool,gemini:bool,claude:bool,openai:bool}
     */
    public static function keyStatus(): array
    {
        return [
            'pollinations' => true,
            'gemini' => self::resolveProviderKey('gemini') !== null,
            'claude' => self::resolveProviderKey('claude') !== null,
            'openai' => self::resolveProviderKey('openai') !== null,
        ];
    }

    private static function requireKeyFor(string $provider): string
    {
        $key = self::resolveProviderKey($provider);
        if ($key === null) {
            $env = self::ENV_NAME[$provider] ?? strtoupper($provider).'_API_KEY';
            throw new RuntimeException(
                "{$env} is not configured. Paste a key under Admin → AI settings (or set it in .env)."
            );
        }

        return $key;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Text generation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array{prompt:string,json?:bool}  $input
     * @param  array{textProvider:string,textModel:string,temperature:float,imageProvider:string}  $settings
     * @return array{text:string,provider:string,model:string}
     */
    public static function generateText(array $input, array $settings): array
    {
        return match ($settings['textProvider']) {
            'pollinations' => self::generateTextPollinations($input, $settings),
            'gemini' => self::generateTextGemini($input, $settings),
            'claude' => self::generateTextClaude($input, $settings),
            'openai' => self::generateTextOpenAi($input, $settings),
            default => throw new RuntimeException("Unknown text provider: {$settings['textProvider']}"),
        };
    }

    /**
     * @param  array{prompt:string,json?:bool}  $input
     * @param  array<string,mixed>  $settings
     * @return array{text:string,provider:string,model:string}
     */
    private static function generateTextPollinations(array $input, array $settings): array
    {
        $body = [
            'model' => $settings['textModel'],
            'messages' => [['role' => 'user', 'content' => $input['prompt']]],
            'temperature' => $settings['temperature'],
            'private' => true,
        ];
        if (! empty($input['json'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = self::postWithRetry(
            'https://text.pollinations.ai/openai',
            $body,
            240, // 4 minutes — Pollinations can take 20+ s for long JSON replies.
            fn (int $status, string $bodyText) => self::pollinationsErrorMessage($status, $bodyText)
        );

        $data = $response->json();
        $text = $data['choices'][0]['message']['content'] ?? '';
        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Pollinations returned an empty completion.');
        }

        return ['text' => $text, 'provider' => 'pollinations', 'model' => $settings['textModel']];
    }

    /**
     * @param  array{prompt:string,json?:bool}  $input
     * @param  array<string,mixed>  $settings
     * @return array{text:string,provider:string,model:string}
     */
    private static function generateTextGemini(array $input, array $settings): array
    {
        $apiKey = self::requireKeyFor('gemini');
        $model = $settings['textModel'];

        $config = ['temperature' => $settings['temperature']];
        if (! empty($input['json'])) {
            $config['responseMimeType'] = 'application/json';
        }

        $response = Http::timeout(240)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                [
                    'contents' => [['parts' => [['text' => $input['prompt']]]]],
                    'generationConfig' => $config,
                ]
            );

        if (! $response->successful()) {
            throw new RuntimeException('Gemini request failed ('.$response->status().'): '.self::snippet($response->body()));
        }

        $data = $response->json();
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $p) {
            if (isset($p['text']) && is_string($p['text'])) {
                $text .= $p['text'];
            }
        }

        return ['text' => $text, 'provider' => 'gemini', 'model' => $model];
    }

    /**
     * @param  array{prompt:string,json?:bool}  $input
     * @param  array<string,mixed>  $settings
     * @return array{text:string,provider:string,model:string}
     */
    private static function generateTextClaude(array $input, array $settings): array
    {
        $apiKey = self::requireKeyFor('claude');

        $payload = [
            'model' => $settings['textModel'],
            'max_tokens' => 8192,
            'temperature' => $settings['temperature'],
            'messages' => [['role' => 'user', 'content' => $input['prompt']]],
        ];
        if (! empty($input['json'])) {
            $payload['system'] = 'Respond with ONLY a single JSON value matching the requested schema. '
                .'No prose, no markdown fences, no commentary.';
        }

        $response = Http::timeout(240)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->post('https://api.anthropic.com/v1/messages', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Claude request failed ('.$response->status().'): '.self::snippet($response->body()));
        }

        $data = $response->json();
        $blocks = $data['content'] ?? [];
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }

        return ['text' => $text, 'provider' => 'claude', 'model' => $settings['textModel']];
    }

    /**
     * @param  array{prompt:string,json?:bool}  $input
     * @param  array<string,mixed>  $settings
     * @return array{text:string,provider:string,model:string}
     */
    private static function generateTextOpenAi(array $input, array $settings): array
    {
        $apiKey = self::requireKeyFor('openai');

        $payload = [
            'model' => $settings['textModel'],
            'temperature' => $settings['temperature'],
            'messages' => [['role' => 'user', 'content' => $input['prompt']]],
        ];
        if (! empty($input['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::timeout(240)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI request failed ('.$response->status().'): '.self::snippet($response->body()));
        }

        $data = $response->json();
        $text = $data['choices'][0]['message']['content'] ?? '';

        return ['text' => is_string($text) ? $text : '', 'provider' => 'openai', 'model' => $settings['textModel']];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Image generation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array{prompt:string}  $input
     * @param  array{imageProvider:string}  $settings
     * @return array{base64:string,mimeType:string,provider:string,model:string}
     */
    public static function generateImage(array $input, array $settings): array
    {
        if ($settings['imageProvider'] === 'off') {
            throw new RuntimeException('Image generation is disabled in admin AI settings.');
        }

        return match ($settings['imageProvider']) {
            'pollinations' => self::generateImagePollinations($input),
            'gemini' => self::generateImageGemini($input),
            'openai' => self::generateImageOpenAi($input),
            default => throw new RuntimeException("Unknown image provider: {$settings['imageProvider']}"),
        };
    }

    /**
     * @param  array{prompt:string}  $input
     * @return array{base64:string,mimeType:string,provider:string,model:string}
     */
    private static function generateImagePollinations(array $input): array
    {
        $params = http_build_query([
            'width' => '1024',
            'height' => '1024',
            'model' => self::IMAGE_MODEL_POLLINATIONS,
            'nologo' => 'true',
            'private' => 'true',
            'seed' => (string) random_int(0, 999_999_999),
        ]);
        $url = 'https://image.pollinations.ai/prompt/'.rawurlencode($input['prompt']).'?'.$params;

        $response = self::getWithRetry(
            $url,
            120, // Flux can take 15-30 s per image; allow up to 2 minutes.
            function (int $status, string $body) {
                if ($status >= 500 && $status < 600) {
                    return "Pollinations.ai image service is overloaded (HTTP {$status}). Try again in a minute.";
                }
                if ($status === 429) {
                    return 'Pollinations.ai is rate-limiting this IP for images. Wait a minute.';
                }
                if (self::isLikelyHtml($body)) {
                    return "Pollinations.ai image request returned HTTP {$status} (HTML error page).";
                }

                return "Pollinations image request failed ({$status}).";
            }
        );

        $bytes = $response->body();
        if ($bytes === '' || $bytes === null) {
            throw new RuntimeException('Pollinations returned empty image bytes.');
        }
        $mimeType = $response->header('Content-Type') ?: 'image/jpeg';

        return [
            'base64' => base64_encode($bytes),
            'mimeType' => $mimeType,
            'provider' => 'pollinations',
            'model' => self::IMAGE_MODEL_POLLINATIONS,
        ];
    }

    /**
     * @param  array{prompt:string}  $input
     * @return array{base64:string,mimeType:string,provider:string,model:string}
     */
    private static function generateImageGemini(array $input): array
    {
        $apiKey = self::requireKeyFor('gemini');
        $model = self::IMAGE_MODEL_GEMINI;

        $response = Http::timeout(120)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:predict",
                [
                    'instances' => [['prompt' => $input['prompt']]],
                    'parameters' => ['sampleCount' => 1],
                ]
            );

        if (! $response->successful()) {
            throw new RuntimeException('Gemini Imagen request failed ('.$response->status().'): '.self::snippet($response->body()));
        }

        $data = $response->json();
        $first = $data['predictions'][0] ?? null;
        $base64 = $first['bytesBase64Encoded'] ?? null;
        if (! is_string($base64) || $base64 === '') {
            throw new RuntimeException('Gemini Imagen returned no image bytes.');
        }

        return [
            'base64' => $base64,
            'mimeType' => $first['mimeType'] ?? 'image/png',
            'provider' => 'gemini',
            'model' => $model,
        ];
    }

    /**
     * @param  array{prompt:string}  $input
     * @return array{base64:string,mimeType:string,provider:string,model:string}
     */
    private static function generateImageOpenAi(array $input): array
    {
        $apiKey = self::requireKeyFor('openai');

        $response = Http::timeout(120)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => self::IMAGE_MODEL_OPENAI,
                'prompt' => $input['prompt'],
                'size' => '1024x1024',
                'n' => 1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI image request failed ('.$response->status().'): '.self::snippet($response->body()));
        }

        $data = $response->json();
        $base64 = $data['data'][0]['b64_json'] ?? null;
        if (! is_string($base64) || $base64 === '') {
            throw new RuntimeException(
                'OpenAI image generation returned no base64 payload. Check the model supports b64_json output.'
            );
        }

        return [
            'base64' => $base64,
            'mimeType' => 'image/png',
            'provider' => 'openai',
            'model' => self::IMAGE_MODEL_OPENAI,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // HTTP helpers (retry + error shaping for Pollinations)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST with retry on 5xx/429/network error (parity with fetchWithRetry).
     *
     * @param  array<string,mixed>  $body
     * @param  callable(int,string):string  $describe
     */
    private static function postWithRetry(string $url, array $body, int $timeoutSeconds, callable $describe): \Illuminate\Http\Client\Response
    {
        $lastError = '';
        for ($attempt = 0; $attempt < self::POLLINATIONS_RETRIES; $attempt++) {
            try {
                $response = Http::timeout($timeoutSeconds)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($url, $body);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage() ?: 'request failed';
                if ($attempt === self::POLLINATIONS_RETRIES - 1) {
                    break;
                }
                self::backoff($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $retriable = $status >= 500 || $status === 429;
            $lastError = $describe($status, $response->body());
            if (! $retriable || $attempt === self::POLLINATIONS_RETRIES - 1) {
                throw new RuntimeException($lastError);
            }
            self::backoff($attempt);
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'Pollinations request failed.');
    }

    /**
     * GET with retry on 5xx/429/network error.
     *
     * @param  callable(int,string):string  $describe
     */
    private static function getWithRetry(string $url, int $timeoutSeconds, callable $describe): \Illuminate\Http\Client\Response
    {
        $lastError = '';
        for ($attempt = 0; $attempt < self::POLLINATIONS_RETRIES; $attempt++) {
            try {
                $response = Http::timeout($timeoutSeconds)->get($url);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage() ?: 'request failed';
                if ($attempt === self::POLLINATIONS_RETRIES - 1) {
                    break;
                }
                self::backoff($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $retriable = $status >= 500 || $status === 429;
            $lastError = $describe($status, $response->body());
            if (! $retriable || $attempt === self::POLLINATIONS_RETRIES - 1) {
                throw new RuntimeException($lastError);
            }
            self::backoff($attempt);
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'Pollinations request failed.');
    }

    private static function backoff(int $attempt): void
    {
        $ms = self::POLLINATIONS_BACKOFF_MS[$attempt] ?? 1500;
        usleep($ms * 1000);
    }

    private static function isLikelyHtml(string $body): bool
    {
        $head = strtolower(substr(ltrim($body), 0, 50));

        return str_starts_with($head, '<!doctype') || str_starts_with($head, '<html');
    }

    private static function pollinationsErrorMessage(int $status, string $body): string
    {
        if ($status >= 500 && $status < 600) {
            return "Pollinations.ai is overloaded right now (HTTP {$status}). Try again in a minute, or switch to a different provider in Admin → AI settings.";
        }
        if ($status === 429) {
            return 'Pollinations.ai is rate-limiting this IP. Wait a minute before generating again.';
        }
        if (self::isLikelyHtml($body)) {
            return "Pollinations.ai returned HTTP {$status} (HTML error page). Try again or check status at https://pollinations.ai.";
        }
        $snippet = self::snippet($body);

        return "Pollinations text request failed ({$status})".($snippet !== '' ? ": {$snippet}" : '').'.';
    }

    private static function snippet(string $body): string
    {
        return substr(trim((string) preg_replace('/\s+/', ' ', $body)), 0, 200);
    }
}
