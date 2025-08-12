<?php

namespace Paymenter\Extensions\Gateways\Vipps\Http\Controllers;

use App\Helpers\ExtensionHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Paymenter\Models\Invoice;

class VippsController
{
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
        $token = Cache::get($cacheKey);
        if ($token) return $token;

        $apiBase = $this->apiBase($cfg);
        $res = Http::withHeaders(array_merge($this->headerCommon($cfg), [
                'client_id' => $cfg['client_id'] ?? '',
                'client_secret' => $cfg['client_secret'] ?? '',
            ]))
            ->post($apiBase . '/accesstoken/get');

        if (!$res->successful()) {
            Log::error('[Vipps] Access token (return) error', ['status' => $res->status(), 'body' => $res->body()]);
            abort(500, 'Vipps auth error');
        }
        $json = $res->json();
        return $json['access_token'] ?? '';
    }

    public function handleReturn(Request $request)
    {
        $reference = (string) $request->query('reference', '');
        if (!$reference) abort(400, 'Missing Vipps reference');

        $cfg = ExtensionHelper::getConfig('Vipps');
        $headers = $this->headerCommon($cfg);
        $token = $this->getAccessToken($cfg);
        $apiBase = $this->apiBase($cfg);

        $invoiceId = Cache::pull('vipps:ref:' . $reference);
        $invoice = $invoiceId ? Invoice::find($invoiceId) : null;

        $res = Http::withHeaders(array_merge($headers, [
            'Authorization' => 'Bearer ' . $token,
        ]))->get($apiBase . '/epayment/v1/payments/' . urlencode($reference));

        if (!$res->successful()) {
            Log::error('[Vipps] Get payment failed', ['status' => $res->status(), 'body' => $res->body()]);
            return response()->view('vipps::result', [
                'ok' => False,
                'message' => 'Kunne ikke bekrefte betalingen. Kontakt support om beløpet er reservert.'
            ]);
        }

        $data = $res->json();
        $authorized = (int) data_get($data, 'aggregate.authorizedAmount.value', 0);
        $captured = (int) data_get($data, 'aggregate.capturedAmount.value', 0);
        $currency = (string) data_get($data, 'amount.currency', 'NOK');

        $auto = (bool) ($cfg['auto_capture'] ?? True);
        if ($auto && $authorized > 0 && $captured == 0) {
            $cap = Http::withHeaders(array_merge($headers, [
                'Authorization' => 'Bearer ' . $token,
                'Idempotency-Key' => (string) Str::uuid(),
            ]))->post($apiBase . '/epayment/v1/payments/' . urlencode($reference) . '/capture', [
                'amount' => ['currency' => $currency, 'value' => $authorized]
            ]);

            if ($cap->successful()) {
                $captured = $authorized;
            } else {
                Log::error('[Vipps] Capture failed', ['status' => $cap->status(), 'body' => $cap->body()]);
            }
        }

        $ok = $captured > 0;

        try {
            if ($ok && $invoice) {
                ExtensionHelper::paymentPaid($invoice, 'Vipps', $captured / 100, [
                    'reference' => $reference
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[Vipps] Failed to mark invoice paid: ' . $e->getMessage());
        }

        return response()->view('vipps::result', [
            'ok' => $ok,
            'message' => $ok ? 'Betaling fullført' : 'Betaling ikke fullført',
        ]);
    }
}
