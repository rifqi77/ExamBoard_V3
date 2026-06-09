<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppConfigAi;
use App\Services\AiProviders;
use App\Support\CryptoSecrets;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admin → AI settings. Port of the original AiSettingsClient +
 * /api/admin/ai-settings route (GET/PUT/PATCH).
 *
 * Admin picks the active text + image AI providers, the text model, and the
 * sampling temperature; and pastes per-provider API keys that are stored
 * AES-256-GCM encrypted on the app_config_ai singleton row. Raw key values
 * are NEVER returned — only a masked "set/unset" boolean per provider.
 *
 * The whole controller is admin-only (route group is gated by role:admin).
 */
class AiSettingsController extends Controller
{
    /** GET /admin/ai-settings — render the settings page. */
    public function show(Request $request)
    {
        return Inertia::render('admin/AiSettings', $this->payload());
    }

    /**
     * PUT /admin/ai-settings — update provider / model / temperature /
     * image-provider. Rejects unknown providers + models that don't belong
     * to the chosen provider; clamps out-of-range temperatures to [0, 2].
     */
    public function update(Request $request)
    {
        $textProvider = $request->input('textProvider');
        if (! AiProviders::isValidTextProvider($textProvider)) {
            return back()->with('error', 'textProvider must be one of: '.implode(', ', AiProviders::TEXT_PROVIDERS));
        }

        // Empty model is allowed → fall back to that provider's default.
        $requestedModel = $request->input('textModel');
        $requestedModel = is_string($requestedModel) && trim($requestedModel) !== ''
            ? trim($requestedModel)
            : AiProviders::defaultModelFor($textProvider);

        if (! AiProviders::isValidModelFor($textProvider, $requestedModel)) {
            return back()->with('error',
                "textModel \"{$requestedModel}\" is not in the registry for {$textProvider}. Valid: "
                .implode(', ', AiProviders::TEXT_MODELS[$textProvider]));
        }

        $imageProvider = $request->input('imageProvider');
        if (! AiProviders::isValidImageProvider($imageProvider)) {
            return back()->with('error', 'imageProvider must be one of: '.implode(', ', AiProviders::IMAGE_PROVIDERS));
        }

        $row = AppConfigAi::singleton();
        $row->text_provider = $textProvider;
        $row->text_model = $requestedModel;
        $row->temperature = AiProviders::clampTemperature($request->input('temperature'));
        $row->image_provider = $imageProvider;
        $row->updated_by = $request->user()->id;
        $row->save();

        return back()->with('success', 'AI settings saved.');
    }

    /**
     * PATCH /admin/ai-settings/keys — update only the encrypted API-key blob.
     *   - non-empty string  → encrypt + store under that provider
     *   - empty string/null → clear that provider's stored key (env fallback)
     *   - provider absent    → left untouched
     * We never echo decrypted key values back, only the boolean status.
     */
    public function updateKeys(Request $request)
    {
        $patch = $request->input('keys');
        if (! is_array($patch)) {
            return back()->with('error', '`keys` object is required.');
        }

        $row = AppConfigAi::singleton();
        $current = is_array($row->ai_keys) ? $row->ai_keys : [];
        $next = $current;

        foreach (['gemini', 'claude', 'openai'] as $provider) {
            if (! array_key_exists($provider, $patch)) {
                continue;
            }
            $raw = $patch[$provider];
            if ($raw === null || $raw === '') {
                unset($next[$provider]);

                continue;
            }
            if (! is_string($raw) || strlen($raw) > 500) {
                return back()->with('error', "{$provider} key must be a non-empty string under 500 chars.");
            }
            $next[$provider] = CryptoSecrets::encryptSecret(trim($raw));
        }

        $row->ai_keys = $next;
        $row->updated_by = $request->user()->id;
        $row->save();

        return back()->with('success', 'API keys updated.');
    }

    /**
     * Shared page payload: the current admin-chosen settings, masked key
     * status per provider, and the static provider + model registries so the
     * client renders dropdowns without a duplicate registry.
     *
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        return [
            'settings' => AiProviders::activeSettings(),
            'keyStatus' => AiProviders::keyStatus(),
            'providers' => [
                'text' => AiProviders::TEXT_PROVIDERS,
                'image' => AiProviders::IMAGE_PROVIDERS,
                'models' => AiProviders::TEXT_MODELS,
            ],
        ];
    }
}
