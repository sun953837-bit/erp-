<?php

namespace Tests\Feature;

use App\Services\Bi\BiEtlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BiEtlGoodsThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage1_bi_refresh_includes_goods_and_product_theme_tables(): void
    {
        config()->set('bi.stage1.alert_enabled', false);

        $shopId = $this->createShop('xianyu');
        $spuId = (int) DB::table('products_spu')->insertGetId([
            'spu_code' => 'SPU-001',
            'title' => '测试商品',
            'brand' => 'BrandA',
            'category_name' => '数码',
            'status' => 'ACTIVE',
            'source_of_truth' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products_sku')->insert([
            'sku_code' => 'SKU-001',
            'spu_id' => $spuId,
            'sku_name' => '测试商品-SKU',
            'specs_json' => json_encode(['color' => 'black'], JSON_UNESCAPED_UNICODE),
            'barcode' => null,
            'cost_price' => 10.00,
            'cost_currency' => 'CNY',
            'retail_price' => 99.00,
            'retail_currency' => 'CNY',
            'weight' => 0.100,
            'size_json' => json_encode(['l' => 1], JSON_UNESCAPED_UNICODE),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $goodsOrderId = (int) DB::table('orders')->insertGetId([
            'order_no' => 'ORD-G-001',
            'order_type' => 'goods',
            'platform_code' => 'xianyu',
            'shop_id' => $shopId,
            'external_order_id' => 'XY-G-1001',
            'legacy_service_order_id' => null,
            'project_id' => null,
            'ticket_id' => null,
            'subject' => '测试商品订单',
            'customer_name' => '李四',
            'customer_id' => 'buyer-g-1',
            'currency' => 'CNY',
            'amount' => 99.00,
            'status' => 'confirmed',
            'delivery_mode' => 'shipment',
            'meta_json' => null,
            'confirmed_at' => now(),
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $goodsOrderId,
            'item_no' => 'ITM-G-001',
            'item_type' => 'goods',
            'sku_code' => 'SKU-001',
            'title' => '测试商品-SKU',
            'quantity' => 1,
            'unit_price' => 99.00,
            'total_price' => 99.00,
            'currency' => 'CNY',
            'meta_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var BiEtlService $service */
        $service = app(BiEtlService::class);
        $result = $service->refresh(['mode' => 'full']);

        $this->assertArrayHasKey('dim_product', $result['counts']);
        $this->assertArrayHasKey('fact_goods_orders', $result['counts']);
        $this->assertGreaterThanOrEqual(1, (int) $result['counts']['dim_product']);
        $this->assertGreaterThanOrEqual(1, (int) $result['counts']['fact_goods_orders']);
        $this->assertArrayHasKey('service_source_comparison', $result);
    }

    private function createShop(string $platformCode): int
    {
        return (int) DB::table('shops')->insertGetId([
            'shop_code' => 'SHOP-BI-'.$platformCode.'-001',
            'shop_name' => strtoupper($platformCode).' BI 店铺',
            'platform_code' => $platformCode,
            'site_code' => 'CN',
            'currency' => 'CNY',
            'timezone' => 'Asia/Shanghai',
            'status' => 'ACTIVE',
            'owner_name' => 'owner',
            'owner_phone' => '13900000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
