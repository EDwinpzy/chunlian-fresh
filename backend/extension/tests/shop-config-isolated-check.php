<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Chunlian\Service\CustomerCatalogService;
use Chunlian\Service\FreshCategoryManagementService;
use Chunlian\Service\FreshCategoryPolicy;
use Chunlian\Service\GoodsWriteService;
use Chunlian\Service\ShopSettingService;

/**
 * Repeatable SQLite integration check for store settings and category management.
 * The adapter translates MySQL NOW()/INSERT IGNORE and strips FOR UPDATE so the
 * service flows can run on SQLite. It checks that lock queries are issued inside
 * transactions, but SQLite does not implement MySQL row locks; concurrency
 * behavior still requires verification against the production MySQL engine.
 */
final class SQLiteCompatPDO extends PDO
{
    public int $forUpdateCallsInTransaction = 0;
    public int $forUpdateCallsOutsideTransaction = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->recordForUpdate($query);
        return parent::prepare($this->translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->recordForUpdate($query);
        $query = $this->translate($query);
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->translate($statement));
    }

    private function translate(string $sql): string
    {
        $sql = preg_replace('/\\s+FOR UPDATE\\b/i', '', $sql) ?? $sql;
        return preg_replace('/^\\s*INSERT IGNORE INTO/i', 'INSERT OR IGNORE INTO', $sql) ?? $sql;
    }

    private function recordForUpdate(string $sql): void
    {
        if (preg_match('/\\bFOR UPDATE\\b/i', $sql) !== 1) {
            return;
        }
        if ($this->inTransaction()) {
            $this->forUpdateCallsInTransaction++;
        } else {
            $this->forUpdateCallsOutsideTransaction++;
        }
    }
}

$assertions = 0;

function expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new SQLiteCompatPDO();
$schema = [
    'CREATE TABLE veg_shop_setting (id INTEGER PRIMARY KEY, shop_name TEXT NOT NULL, notice TEXT NOT NULL, phone TEXT NOT NULL, payment_qr_url TEXT NOT NULL, delivery_fee_fen INTEGER NOT NULL, is_open INTEGER NOT NULL, min_lead_minutes INTEGER NOT NULL, updated_at TEXT NOT NULL)',
    "CREATE TABLE veg_shop_profile (id INTEGER PRIMARY KEY, shop_logo_url TEXT NOT NULL DEFAULT '', shop_address TEXT NOT NULL DEFAULT '', business_hours TEXT NOT NULL DEFAULT '', shop_description TEXT NOT NULL DEFAULT '', delivery_description TEXT NOT NULL DEFAULT '', home_banner_title TEXT NOT NULL DEFAULT '', home_banner_subtitle TEXT NOT NULL DEFAULT '', shop_slogan TEXT NOT NULL DEFAULT '', search_hot_words TEXT NOT NULL DEFAULT '[]', home_section_title TEXT NOT NULL DEFAULT '', home_featured_product_ids TEXT NOT NULL DEFAULT '[]', notification_popup_title TEXT NOT NULL DEFAULT '', notification_popup_image_url TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL)",
    'CREATE TABLE veg_operation_log (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT NOT NULL, actor_id INTEGER NULL, order_id INTEGER NULL, action TEXT NOT NULL, detail_json TEXT NOT NULL, created_at TEXT NOT NULL)',
    'CREATE TABLE veg_fresh_category_root (category_id INTEGER PRIMARY KEY, created_at TEXT NOT NULL)',
    'CREATE TABLE veg_fresh_category_state (id INTEGER PRIMARY KEY, legacy_seeded INTEGER NOT NULL, updated_at TEXT NOT NULL)',
    'CREATE TABLE shopxo_goods_category (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL, name TEXT NOT NULL, vice_name TEXT NOT NULL, icon TEXT NOT NULL, icon_active TEXT NOT NULL, "describe" TEXT NOT NULL, bg_color TEXT NOT NULL, is_home_recommended INTEGER NOT NULL, sort INTEGER NOT NULL, is_enable INTEGER NOT NULL, add_time INTEGER NOT NULL, upd_time INTEGER NOT NULL)',
    'CREATE TABLE shopxo_goods_category_join (goods_id INTEGER NOT NULL, category_id INTEGER NOT NULL, add_time INTEGER NOT NULL)',
    "CREATE TABLE shopxo_goods (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, title_color TEXT NOT NULL DEFAULT '', simple_desc TEXT NOT NULL DEFAULT '', spec_desc TEXT NOT NULL DEFAULT '', images TEXT NOT NULL DEFAULT '', original_price TEXT NOT NULL DEFAULT '0.00', min_original_price TEXT NOT NULL DEFAULT '0.00', max_original_price TEXT NOT NULL DEFAULT '0.00', price TEXT NOT NULL DEFAULT '0.00', min_price TEXT NOT NULL DEFAULT '0.00', max_price TEXT NOT NULL DEFAULT '0.00', inventory INTEGER NOT NULL DEFAULT 0, inventory_unit TEXT NOT NULL DEFAULT '', sales_count INTEGER NOT NULL DEFAULT 0, is_shelves INTEGER NOT NULL DEFAULT 0, is_exist_many_spec INTEGER NOT NULL DEFAULT 0, content_web TEXT NOT NULL DEFAULT '', buy_min_number INTEGER NOT NULL DEFAULT 1, buy_max_number INTEGER NOT NULL DEFAULT 0, is_deduction_inventory INTEGER NOT NULL DEFAULT 1, sort_level INTEGER NOT NULL DEFAULT 0, is_delete_time INTEGER NOT NULL DEFAULT 0, add_time INTEGER NOT NULL DEFAULT 0, upd_time INTEGER NOT NULL DEFAULT 0)",
    'CREATE TABLE shopxo_goods_spec_base (id INTEGER PRIMARY KEY AUTOINCREMENT, goods_id INTEGER NOT NULL, price TEXT NOT NULL, original_price TEXT NOT NULL, inventory INTEGER NOT NULL, buy_min_number INTEGER NOT NULL, buy_max_number INTEGER NOT NULL, weight INTEGER NOT NULL, volume INTEGER NOT NULL, coding TEXT NOT NULL, barcode TEXT NOT NULL, inventory_unit TEXT NOT NULL, extends TEXT NOT NULL, add_time INTEGER NOT NULL)',
    'CREATE TABLE shopxo_goods_spec_value (id INTEGER PRIMARY KEY AUTOINCREMENT, goods_id INTEGER NOT NULL, goods_spec_base_id INTEGER NOT NULL, value TEXT NOT NULL)',
];
foreach ($schema as $statement) {
    $pdo->exec($statement);
}

$pdo->exec("INSERT INTO veg_shop_setting VALUES (1, '春连鲜选', '公告', '', '', 0, 1, 30, NOW())");
$pdo->exec("INSERT INTO veg_shop_profile (id, search_hot_words, updated_at) VALUES (1, '[]', NOW())");
$pdo->exec("INSERT INTO veg_fresh_category_state VALUES (1, 0, NOW())");
$pdo->exec("INSERT INTO shopxo_goods_category (pid,name,vice_name,icon,icon_active,\"describe\",bg_color,is_home_recommended,sort,is_enable,add_time,upd_time) VALUES "
    . "(0,'叶菜类','','','', '', '#fff', 0, 100, 1, 1, 1),"
    . "(1,'小青菜','','','', '', '#fff', 0, 90, 1, 1, 1),"
    . "(0,'数码办公','','','', '', '#fff', 0, 80, 1, 1, 1)");

$settings = new ShopSettingService($pdo);
$saved = $settings->update([
    'shop_name' => '小禾鲜铺',
    'shop_logo_url' => '/static/upload/images/store/logo.png',
    'shop_address' => '上海市浦东新区示例路 8 号',
    'business_hours' => '每日 07:00–20:00',
    'shop_description' => '当天采收，附近配送。',
    'delivery_description' => '满 30 元当日送达。',
    'home_banner_title' => '今天的新鲜菜',
    'home_banner_subtitle' => '清晨直达',
    'shop_slogan' => '新鲜看得见',
    'search_hot_words' => ['小青菜', '土鸡蛋'],
    'home_section_title' => '今日鲜选',
    'home_featured_product_ids' => ['100', '101'],
    'notification_popup_title' => '周末鲜蔬上新',
    'notification_popup_image_url' => 'https://example.test/popup.png',
    'payment_qr_url' => 'https://example.test/qr.png',
    'delivery_fee_fen' => 500,
    'is_open' => false,
    'min_lead_minutes' => 45,
], '7');
expect($saved['code'] === 'OK', 'ShopSettingService update should succeed');
$readback = $settings->get();
expect($readback['shop_name'] === '小禾鲜铺', 'shop name should persist');
expect($readback['shop_logo_url'] === '/static/upload/images/store/logo.png', 'shop logo should persist');
expect($readback['shop_address'] === '上海市浦东新区示例路 8 号', 'address should persist');
expect($readback['business_hours'] === '每日 07:00–20:00', 'business hours should persist');
expect($readback['shop_description'] === '当天采收，附近配送。', 'description should persist');
expect($readback['delivery_description'] === '满 30 元当日送达。', 'delivery description should persist');
expect($readback['home_banner_title'] === '今天的新鲜菜', 'banner title should persist');
expect($readback['home_banner_subtitle'] === '清晨直达', 'banner subtitle should persist');
expect($readback['shop_slogan'] === '新鲜看得见', 'shop slogan should persist');
expect($readback['search_hot_words'] === ['小青菜', '土鸡蛋'], 'hot words should round-trip as a JSON list');
expect($readback['home_section_title'] === '今日鲜选', 'home section title should persist');
expect($readback['home_featured_product_ids'] === ['100', '101'], 'featured product IDs should round-trip in carousel order');
expect($readback['notification_popup_title'] === '周末鲜蔬上新', 'notification popup title should persist');
expect($readback['notification_popup_image_url'] === 'https://example.test/popup.png', 'notification popup image URL should persist');
expect($readback['delivery_fee_fen'] === 500 && $readback['min_lead_minutes'] === 45, 'numeric settings should persist');
expect($readback['is_open'] === false, 'open state should remain a boolean');
expect($settings->update(['shop_logo_url' => 'javascript:alert(1)'], '7')['code'] === 'VALIDATION_FAILED', 'invalid logo URL should be rejected');
expect($settings->update(['notification_popup_image_url' => 'javascript:alert(1)'], '7')['code'] === 'VALIDATION_FAILED', 'invalid notification popup image URL should be rejected');
expect($settings->update(['search_hot_words' => '小青菜'], '7')['code'] === 'VALIDATION_FAILED', 'hot words must be an array');
expect($settings->update(['home_featured_product_ids' => ['100', '100']], '7')['code'] === 'VALIDATION_FAILED', 'featured product IDs must be unique');
expect($settings->update(['home_featured_product_ids' => ['abc']], '7')['code'] === 'VALIDATION_FAILED', 'featured product IDs must be numeric strings');
expect($settings->update(['home_featured_product_ids' => array_fill(0, 11, '100')], '7')['code'] === 'VALIDATION_FAILED', 'featured product IDs must not exceed 10 items');
$clearedPopup = $settings->update([
    'notification_popup_title' => '',
    'notification_popup_image_url' => '',
], '7');
expect($clearedPopup['code'] === 'OK', 'notification popup fields should be clearable');
expect($clearedPopup['data']['notification_popup_title'] === '' && $clearedPopup['data']['notification_popup_image_url'] === '', 'cleared notification popup fields should return empty values');

$categories = new FreshCategoryManagementService($pdo, 'shopxo_');
$initial = $categories->list();
expect($initial['code'] === 'OK', 'category list should succeed');
expect(count($initial['data']) === 1 && $initial['data'][0]['name'] === '叶菜类', 'legacy fresh root should seed once and demo root should stay hidden');
expect(count($initial['data'][0]['children']) === 1, 'legacy child should remain under its fresh root');
expect($initial['data'][0]['product_count'] === 0 && $initial['data'][0]['children'][0]['product_count'] === 0, 'unassociated categories should have a zero product count');

$createdRoot = $categories->create(['name' => '菌菇类', 'sort' => 500, 'is_enabled' => true], '7');
expect($createdRoot['code'] === 'OK', 'root category creation should succeed');
$rootId = $createdRoot['data']['id'];
$createdChild = $categories->create(['name' => '香菇', 'parent_id' => $rootId, 'sort' => 20, 'is_enabled' => true], '7');
expect($createdChild['code'] === 'OK', 'child category creation should succeed');
$childId = $createdChild['data']['id'];
$updatedChild = $categories->update($childId, ['name' => '鲜香菇', 'sort' => 30, 'is_enabled' => false], '7');
expect($updatedChild['code'] === 'OK', 'category rename, reorder, and disable should succeed');
expect($updatedChild['data']['name'] === '鲜香菇' && $updatedChild['data']['sort'] === 30 && $updatedChild['data']['is_enabled'] === false, 'category patch response should contain updated fields');
expect($updatedChild['data']['product_count'] === 0, 'category serialization should default a missing product count to zero');
expect(!FreshCategoryPolicy::isAllowed($pdo, 'shopxo_', $childId), 'disabled category should not be accepted for new/reassigned goods');

$visible = (new CustomerCatalogService($pdo, 'shopxo_'))->categories()['data'];
expect(count($visible) === 2, 'customer catalog should include enabled configured roots');
$visibleRoot = array_values(array_filter($visible, static fn (array $item): bool => $item['id'] === $rootId))[0] ?? null;
expect(is_array($visibleRoot) && $visibleRoot['children'] === [], 'customer catalog should hide disabled children');

$pdo->exec("INSERT INTO shopxo_goods (id, title) VALUES (100, '测试商品')");
$pdo->prepare('INSERT INTO shopxo_goods_category_join (goods_id, category_id, add_time) VALUES (100, :category_id, 1)')
    ->execute(['category_id' => (int) $childId]);
$pdo->prepare('INSERT INTO shopxo_goods_category_join (goods_id, category_id, add_time) VALUES (100, :category_id, 2)')
    ->execute(['category_id' => (int) $childId]);
$listedAfterAssociation = $categories->list();
$listedRoot = array_values(array_filter($listedAfterAssociation['data'], static fn (array $item): bool => $item['id'] === $rootId))[0] ?? null;
$listedChild = is_array($listedRoot)
    ? (array_values(array_filter($listedRoot['children'], static fn (array $item): bool => $item['id'] === $childId))[0] ?? null)
    : null;
expect(is_array($listedRoot) && $listedRoot['product_count'] === 1, 'root category count should include distinct goods assigned to its child categories');
expect(is_array($listedChild) && $listedChild['product_count'] === 1, 'category list should count distinct associated goods');
$merchantPredicate = FreshCategoryPolicy::goodsPredicate('shopxo_', 'g', false);
$customerPredicate = FreshCategoryPolicy::goodsPredicate('shopxo_', 'g', true);
$merchantProduct = $pdo->query("SELECT g.`id` FROM `shopxo_goods` g WHERE g.`id` = 100 AND {$merchantPredicate}")->fetchColumn();
$customerProduct = $pdo->query("SELECT g.`id` FROM `shopxo_goods` g WHERE g.`id` = 100 AND {$customerPredicate}")->fetchColumn();
expect($merchantProduct !== false, 'merchant product lookup should include disabled categories');
expect($customerProduct === false, 'customer product lookup should exclude disabled categories');
$pdo->exec("INSERT INTO shopxo_goods (id, title, is_shelves, inventory, min_price, max_price) VALUES (101, '轮播商品乙', 1, 5, '3.50', '3.50')");
$pdo->exec("UPDATE shopxo_goods SET is_shelves = 1, inventory = 8, min_price = '2.50', max_price = '2.50' WHERE id = 100");
$pdo->exec('INSERT INTO shopxo_goods_category_join (goods_id, category_id, add_time) VALUES (100, 1, 3), (101, 1, 4)');
$customerCatalog = new CustomerCatalogService($pdo, 'shopxo_');
$featured = $customerCatalog->goodsList(null, null, null, 1, 10, '101,100');
expect($featured['code'] === 'OK', 'featured goods query should succeed');
expect(array_column($featured['data']['list'], 'id') === ['101', '100'], 'featured goods should only return public goods in configured order');
expect($customerCatalog->goodsList(null, null, null, 1, 10, '100,nope')['code'] === 'VALIDATION_FAILED', 'featured goods query should reject malformed IDs');
expect($categories->delete($childId, '7')['code'] === 'VALIDATION_FAILED', 'category with a product association must not be deleted');

$pdo->forUpdateCallsInTransaction = 0;
$pdo->forUpdateCallsOutsideTransaction = 0;
$goodsWrite = new GoodsWriteService($pdo, 'shopxo_');
$createdGoods = $goodsWrite->create([
    'title' => '小青菜',
    'price_fen' => 350,
    'inventory' => 20,
    'category_id' => '1',
], '7');
expect($createdGoods['code'] === 'OK', 'goods can be created in an enabled fresh category');
expect($pdo->forUpdateCallsInTransaction > 0, 'goods creation must validate and lock category inside its transaction');
expect($pdo->forUpdateCallsOutsideTransaction === 0, 'goods category validation must not issue a lock query outside a transaction');
$invalidReclassification = $goodsWrite->update($createdGoods['data']['id'], ['category_id' => $childId], '7');
expect($invalidReclassification['code'] === 'VALIDATION_FAILED', 'goods cannot be reclassified into a disabled category');
expect($pdo->forUpdateCallsOutsideTransaction === 0, 'reclassification must validate category inside its transaction');

$pdo->exec('DELETE FROM shopxo_goods_category_join WHERE category_id = ' . (int) $childId);
expect($categories->delete('1', '7')['code'] === 'VALIDATION_FAILED', 'root with child categories must not be deleted');
expect($categories->delete($childId, '7')['code'] === 'OK', 'unused child category should be deletable');
expect($categories->delete($rootId, '7')['code'] === 'OK', 'unused root category should be deletable');

echo "SQLite isolated config/category checks passed ({$assertions} assertions).\n";
