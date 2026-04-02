<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'sync_receipt_logs',
            'raw_orders',
            'raw_refunds',
            'raw_listings',
            'raw_services',
            'reconciliation_records',
            'refund_records',
            'payment_records',
            'receivable_records',
            'tickets',
            'projects',
            'service_orders',
            'sync_tasks',
            'platform_product_mappings',
            'products_sku',
            'products_spu',
            'shop_platform_configs',
            'shops',
            'user_roles',
            'permissions',
            'roles',
            'users',
            'sms_code_records',
            'audit_logs',
            'notifications',
            'webhook_events',
        ] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $adminRoleId = DB::table('roles')->insertGetId([
            'code' => 'ADMIN',
            'name' => 'Administrator',
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('shop_platform_configs')->insert([
            [
                'shop_id' => $amazonShopId,
                'auth_mode' => 'oauth2',
                'app_key_encrypted' => null,
                'app_secret_encrypted' => null,
                'client_id' => 'amazon-client-seed',
                'client_secret_encrypted' => null,
                'refresh_token_encrypted' => 'seed_refresh_amazon',
                'key_version' => 1,
                'is_configured' => true,
                'status' => 'AUTHORIZED',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'shop_id' => $tiktokShopId,
                'auth_mode' => 'oauth2',
                'app_key_encrypted' => null,
                'app_secret_encrypted' => null,
                'client_id' => 'tiktok-client-seed',
                'client_secret_encrypted' => null,
                'refresh_token_encrypted' => null,
                'key_version' => 1,
                'is_configured' => false,
                'status' => 'PENDING',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $serviceOrderId = DB::table('service_orders')->insertGetId([
            'order_no' => 'SO'.Carbon::now()->format('YmdHis').'SEED',
            'platform_code' => 'zbj',
            'shop_id' => $amazonShopId,
            'external_order_id' => 'ZBJ-ORD-SEED-001',
            'service_name' => 'ERP实施服务',
            'customer_name' => 'Demo Customer',
            'currency' => 'CNY',
            'amount' => 5000.00,
            'status' => 'confirmed',
            'delivery_mode' => 'project',
            'project_id' => null,
            'ticket_id' => null,
            'meta_json' => json_encode(['seed' => true]),
            'confirmed_at' => $now,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $projectId = DB::table('projects')->insertGetId([
            'project_no' => 'PRJ'.Carbon::now()->format('YmdHis').'SEED',
            'service_order_id' => $serviceOrderId,
            'name' => 'ERP实施服务 项目交付',
            'status' => 'pending',
            'owner' => null,
            'meta_json' => json_encode(['seed' => true]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('service_orders')->where('id', $serviceOrderId)->update([
            'project_id' => $projectId,
            'updated_at' => $now,
        ]);

        $receivableId = DB::table('receivable_records')->insertGetId([
            'receivable_no' => 'RCV'.Carbon::now()->format('YmdHis').'SEED',
            'service_order_id' => $serviceOrderId,
            'amount' => 5000.00,
            'received_amount' => 2000.00,
            'currency' => 'CNY',
            'status' => 'PARTIAL',
            'due_at' => $now->copy()->addDays(7),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $paymentId = DB::table('payment_records')->insertGetId([
            'payment_no' => 'PAY'.Carbon::now()->format('YmdHis').'SEED',
            'service_order_id' => $serviceOrderId,
            'receivable_record_id' => $receivableId,
            'amount' => 2000.00,
            'currency' => 'CNY',
            'paid_at' => $now,
            'channel' => 'bank_transfer',
            'reference_no' => 'PAYMENT-SEED-001',
            'note' => 'seed payment',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('reconciliation_records')->insert([
            'reconciliation_no' => 'REC'.Carbon::now()->format('YmdHis').'SEED',
            'service_order_id' => $serviceOrderId,
            'receivable_record_id' => $receivableId,
            'refund_record_id' => null,
            'delta_amount' => 2000.00,
            'currency' => 'CNY',
            'status' => 'CLOSED',
            'note' => 'seed payment reconciliation',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $adminUserId = DB::table('users')->insertGetId([
            'username' => 'admin',
            'password_hash' => Hash::make('123456'),
            'phone' => '13800000000',
            'status' => 'ACTIVE',
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_roles')->insert([
            'user_id' => $adminUserId,
            'role_id' => $adminRoleId,
            'created_at' => $now,
        ]);

        $amazonShopId = DB::table('shops')->insertGetId([
            'shop_code' => 'AMZ_US_001',
            'shop_name' => 'Amazon US',
            'platform_code' => 'amazon',
            'site_code' => 'US',
            'currency' => 'USD',
            'timezone' => 'America/Los_Angeles',
            'status' => 'ACTIVE',
            'owner_name' => 'Alice',
            'owner_phone' => '13800000001',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $tiktokShopId = DB::table('shops')->insertGetId([
            'shop_code' => 'TIKTOK_US_001',
            'shop_name' => 'TikTok US',
            'platform_code' => 'tiktok',
            'site_code' => 'US',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'status' => 'ACTIVE',
            'owner_name' => 'Bob',
            'owner_phone' => '13800000002',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $spu1 = DB::table('products_spu')->insertGetId([
            'spu_code' => 'SPU-TSHIRT-001',
            'title' => 'Basic T-Shirt',
            'brand' => 'KJ',
            'category_name' => 'Apparel',
            'status' => 'ACTIVE',
            'source_of_truth' => 'system',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $spu2 = DB::table('products_spu')->insertGetId([
            'spu_code' => 'SPU-MUG-001',
            'title' => 'Ceramic Mug',
            'brand' => 'KJ',
            'category_name' => 'Home',
            'status' => 'ACTIVE',
            'source_of_truth' => 'system',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sku1 = DB::table('products_sku')->insertGetId([
            'sku_code' => 'SKU-TSHIRT-BLACK-M',
            'spu_id' => $spu1,
            'sku_name' => 'T-Shirt Black M',
            'specs_json' => json_encode(['color' => 'black', 'size' => 'M']),
            'barcode' => null,
            'cost_price' => 4.20,
            'cost_currency' => 'USD',
            'retail_price' => 12.90,
            'retail_currency' => 'USD',
            'weight' => 0.230,
            'size_json' => json_encode(['length' => 30, 'width' => 22, 'height' => 2]),
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sku2 = DB::table('products_sku')->insertGetId([
            'sku_code' => 'SKU-TSHIRT-WHITE-L',
            'spu_id' => $spu1,
            'sku_name' => 'T-Shirt White L',
            'specs_json' => json_encode(['color' => 'white', 'size' => 'L']),
            'barcode' => null,
            'cost_price' => 4.50,
            'cost_currency' => 'USD',
            'retail_price' => 13.50,
            'retail_currency' => 'USD',
            'weight' => 0.240,
            'size_json' => json_encode(['length' => 32, 'width' => 24, 'height' => 2]),
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sku3 = DB::table('products_sku')->insertGetId([
            'sku_code' => 'SKU-MUG-RED-350',
            'spu_id' => $spu2,
            'sku_name' => 'Mug Red 350ml',
            'specs_json' => json_encode(['color' => 'red', 'capacity' => '350ml']),
            'barcode' => null,
            'cost_price' => 2.10,
            'cost_currency' => 'USD',
            'retail_price' => 8.90,
            'retail_currency' => 'USD',
            'weight' => 0.410,
            'size_json' => json_encode(['length' => 10, 'width' => 10, 'height' => 11]),
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('platform_product_mappings')->insert([
            [
                'shop_id' => $amazonShopId,
                'spu_id' => $spu1,
                'sku_id' => $sku1,
                'platform_code' => 'amazon',
                'site_code' => 'US',
                'external_listing_id' => 'AMZ-LIST-001',
                'external_sku_id' => 'AMZ-SKU-001',
                'external_status' => 'ONLINE',
                'raw_payload' => json_encode(['seed' => true]),
                'last_synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'shop_id' => $tiktokShopId,
                'spu_id' => $spu2,
                'sku_id' => $sku3,
                'platform_code' => 'tiktok',
                'site_code' => 'US',
                'external_listing_id' => 'TIKTOK-LIST-001',
                'external_sku_id' => 'TIKTOK-SKU-001',
                'external_status' => 'ONLINE',
                'raw_payload' => json_encode(['seed' => true]),
                'last_synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('sync_tasks')->insert([
            'task_no' => 'TK'.Carbon::now()->format('YmdHis').'FAIL01',
            'task_type' => 'product_publish',
            'biz_type' => 'product',
            'biz_id' => 'SKU-TSHIRT-BLACK-M',
            'shop_id' => $amazonShopId,
            'platform_code' => 'amazon',
            'site_code' => 'US',
            'status' => 'FAIL',
            'idempotency_key' => hash('sha256', 'seed-fail-'.Str::uuid()),
            'payload_json' => json_encode([
                'sku_code' => 'SKU-TSHIRT-BLACK-M',
                'mock_mode' => 'fail_immediate',
            ]),
            'result_summary_json' => json_encode([
                'success' => false,
                'accepted' => false,
                'final' => true,
                'code' => 'FAIL_IMMEDIATE',
                'message' => 'seed fail task',
                'external_id' => '',
                'raw_payload' => ['seed' => true],
            ]),
            'retry_count' => 1,
            'max_retry_count' => 3,
            'last_error_code' => 'FAIL_IMMEDIATE',
            'last_error_message' => 'seed fail task',
            'next_retry_at' => null,
            'accepted_at' => null,
            'finished_at' => $now,
            'created_by' => 'seeder',
            'updated_by' => 'seeder',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
