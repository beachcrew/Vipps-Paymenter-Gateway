<?php

namespace Paymenter\Extensions\Gateways\Vipps;

use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Paymenter\Models\Invoice;

class Vipps
{
    public function getName(): string { return 'Vipps'; }
    public function getSlug(): string { return 'vipps'; }
    public function getDescription(): string { return 'Betal raskt og sikkert med Vipps – godkjenn med et trykk i appen din'; }
    public function getIcon(): ?string
    {
        // Path to icon file relative to this extension
        return __DIR__ . '/vipps.svg';
    }
    

    public function boot(): void
    {
        require __DIR__ . '/routes.php';
        \View::addNamespace('vipps', __DIR__ . '/resources/views');
    }

    public function getConfig($values = []): array
    {
        return [
            ['name'=>'test_mode','label'=>'Use test environment','type'=>'checkbox','default'=>true],
            ['name'=>'client_id','label'=>'Client ID','type'=>'text','required'=>true],
            ['name'=>'client_secret','label'=>'Client Secret','type'=>'password','required'=>true],
            ['name'=>'subscription_key','label'=>'Ocp-Apim-Subscription-Key','type'=>'password','required'=>true],
            ['name'=>'msn','label'=>'Merchant-Serial-Number (MSN)','type'=>'text','required'=>true],
            ['name'=>'system_name','label'=>'Vipps-System-Name','type'=>'text','default'=>'paymenter'],
            ['name'=>'system_version','label'=>'Vipps-System-Version','type'=>'text','default'=>app()->version()],
            ['name'=>'plugin_name','label'=>'Vipps-System-Plugin-Name','type'=>'text','default'=>'paymenter-vipps'],
            ['name'=>'plugin_version','label'=>'Vipps-System-Plugin-Version','type'=>'text','default'=>'1.0.0'],
            ['name'=>'auto_capture','label'=>'Auto-capture after authorization','type'=>'checkbox','default'=>true],
        ];
    }

    public function getCheckoutConfig($values = []): array { return []; }

    public function canUseGateway($items, $type): bool
    {
        try {
            if ($type === 'invoice' && is_iterable($items) && count($items) > 0) {
                $first = is_array($items) ? reset($items) : $items[0];
                if (method_exists($first, 'invoice') && $first->invoice) {
                    $currency = $first->invoice->currency ?? 'NOK';
                    return strtoupper($currency) === 'NOK';
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Vipps] canUseGateway currency check failed: ' . $e->getMessage());
        }
        return true;
    }

    public function pay(Invoice $invoice, $total)
    {
        $amountInOre = (int) round(((float) $total) * 100);
        $cfg = ExtensionHelper::getConfig('Vipps');

        $reference = 'inv-' . $invoice->id . '-' . Str::random(8);
        $returnUrl = URL::to('/extensions/vipps/return') . '?reference=' . urlencode($reference);

        $headers = $this->headerCommon($cfg);
        $token = $this->getAccessToken($cfg);

        $apiBase = $this->apiBase($cfg);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'amount' => ['currency' => 'NOK', 'value' => $amountInOre],
            'paymentMethod' => ['type' => 'WALLET'],
            'reference' => $reference,
            'returnUrl' => $returnUrl,
            'userFlow' => 'WEB_REDIRECT',
            'paymentDescription' => 'Invoice #' . $invoice->id,
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ];

        $res = Http::withHeaders(array_merge($headers, [
                'Authorization' => 'Bearer ' . $token,
                'Idempotency-Key' => $idempotencyKey,
            ]))
            ->post($apiBase . '/epayment/v1/payments', $payload);

        if (!$res->successful()) {
            Log::error('[Vipps] Create payment failed', ['status' => $res->status(), 'body' => $res->body()]);
            abort(500, 'Vipps payments API error: ' . $res->status());
        }

        $redirectUrl = data_get($res->json(), 'redirectUrl');
        if (!$redirectUrl) {
            Log::error('[Vipps] No redirectUrl in response', ['json' => $res->json()]);
            abort(500, 'Vipps payments API missing redirect URL.');
        }

        Cache::put('vipps:ref:' . $reference, $invoice->id, now()->addHours(2));

        return response()->view('vipps::redirect', ['url' => $redirectUrl]);
    }

    private function apiBase(array $cfg): string
    {
        return !empty($cfg['test_mode']) ? 'https://apitest.vipps.no' : 'https://api.vipps.no';
    }

    private function headerCommon(array $cfg): array
    {
        return [
            'Ocp-Apim-Subscription-Key' => $cfg['subscription_key'] ?? '',
            'Merchant-Serial-Number' => $cfg['msn'] ?? '',
            'Vipps-System-Name' => $cfg['system_name'] ?? 'paymenter',
            'Vipps-System-Version' => $cfg['system_version'] ?? '1.0.0',
            'Vipps-System-Plugin-Name' => $cfg['plugin_name'] ?? 'paymenter-vipps',
            'Vipps-System-Plugin-Version' => $cfg['plugin_version'] ?? '1.0.0',
        ];
    }

    private function getAccessToken(array $cfg): string
    {
        $cacheKey = 'vipps:token:' . md5(($cfg['client_id'] ?? '') . '|' . ($cfg['msn'] ?? '') . '|' . (!empty($cfg['test_mode']) ? 'test' : 'prod'));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && strlen($cached) > 100) return $cached;

        $apiBase = $this->apiBase($cfg);
        $res = Http::withHeaders(array_merge($this->headerCommon($cfg), [
                'client_id' => $cfg['client_id'] ?? '',
                'client_secret' => $cfg['client_secret'] ?? '',
            ]))
            ->post($apiBase . '/accesstoken/get');

        if (!$res->successful()) {
            Log::error('[Vipps] Access token error', ['status' => $res->status(), 'body' => $res->body()]);
            abort(500, 'Vipps auth error');
        }
        $json = $res->json();
        $token = $json['access_token'] ?? null;
        $expiresIn = (int) ($json['expires_in'] ?? 3600);
        if (!$token) abort(500, 'Vipps auth missing access_token');
        Cache::put($cacheKey, $token, now()->addSeconds(max(300, $expiresIn - 60)));
        return $token;
    }
}
