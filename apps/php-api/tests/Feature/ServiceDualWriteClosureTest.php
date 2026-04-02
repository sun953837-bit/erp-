<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceDualWriteClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('FREEZE_LEGACY_SERVICE_ORDER_WRITE=false');
        $_ENV['FREEZE_LEGACY_SERVICE_ORDER_WRITE'] = 'false';
        putenv('SERVICE_ORDER_DUAL_WRITE_ENABLED=true');
        $_ENV['SERVICE_ORDER_DUAL_WRITE_ENABLED'] = 'true';
    }

    public function test_service_order_write_path_dual_writes_to_canonical_and_items(): void
    {
        $shopId = $this->createShop('xianyu');

        $createResponse = $this->postJson('/api/service-orders', [
            'platform_code' => 'xianyu',
            'shop_id' => $shopId,
            'external_order_id' => 'XY-DW-1001',
            'service_name' => '代运营服务',
            'customer_name' => '张三',
            'customer_id' => 'buyer-1001',
            'currency' => 'CNY',
            'amount' => 1200.00,
            'delivery_mode' => 'project',
        ]);
        $createResponse->assertStatus(201);
        $createResponse->assertJsonPath('success', true);

        $serviceOrderId = (int) $createResponse->json('data.id');
        $serviceOrder = ServiceOrder::query()->findOrFail($serviceOrderId);

        $canonical = Order::query()
            ->where('order_type', 'service')
            ->where('legacy_service_order_id', $serviceOrder->id)
            ->first();

        $this->assertNotNull($canonical);
        $this->assertSame('buyer-1001', (string) $canonical->customer_id);
        $this->assertSame('xianyu', (string) $canonical->platform_code);
        $this->assertSame('XY-DW-1001', (string) $canonical->external_order_id);

        $item = OrderItem::query()
            ->where('order_id', $canonical->id)
            ->where('item_type', 'service')
            ->first();
        $this->assertNotNull($item);
        $this->assertSame('代运营服务', (string) $item->title);
        $this->assertSame('1200.00', (string) $item->total_price);

        $statusResponse = $this->patchJson("/api/service-orders/{$serviceOrderId}/status", [
            'status' => 'confirmed',
            'delivery_mode' => 'project',
        ]);
        $statusResponse->assertStatus(200);
        $statusResponse->assertJsonPath('success', true);

        $paymentResponse = $this->postJson('/api/finance/payments', [
            'service_order_id' => $serviceOrderId,
            'amount' => 1200.00,
            'currency' => 'CNY',
            'channel' => 'offline',
        ]);
        $paymentResponse->assertStatus(200);
        $paymentResponse->assertJsonPath('success', true);

        $canonical->refresh();
        $meta = is_array($canonical->meta_json) ? $canonical->meta_json : [];
        $financeSnapshot = is_array($meta['finance_snapshot'] ?? null) ? $meta['finance_snapshot'] : [];
        $this->assertSame(1, (int) ($financeSnapshot['payment_count'] ?? 0));
        $this->assertSame(1, (int) ($financeSnapshot['receivable_count'] ?? 0));

        $reconcileResponse = $this->getJson("/api/orders/reconciliation/service?shop_id={$shopId}&platform_code=xianyu");
        $reconcileResponse->assertStatus(200);
        $reconcileResponse->assertJsonPath('success', true);
        $reconcileResponse->assertJsonPath('data.filters.shop_id', $shopId);
        $reconcileResponse->assertJsonPath('data.filters.platform_code', 'xianyu');
        $reconcileResponse->assertJsonPath('data.diff.service_item_mismatch.count', 0);
    }

    private function createShop(string $platformCode): int
    {
        return (int) DB::table('shops')->insertGetId([
            'shop_code' => 'SHOP-'.$platformCode.'-001',
            'shop_name' => strtoupper($platformCode).' 店铺',
            'platform_code' => $platformCode,
            'site_code' => 'CN',
            'currency' => 'CNY',
            'timezone' => 'Asia/Shanghai',
            'status' => 'ACTIVE',
            'owner_name' => 'owner',
            'owner_phone' => '13800000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
