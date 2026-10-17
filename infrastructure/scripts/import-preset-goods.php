<?php

declare(strict_types=1);

/**
 * 春连鲜选 · 预设商品库批量导入器
 *
 * 【为什么需要它】
 * 店主端 App 里的「选择农产品」只能一次上架一个，150 条预设要手工点 150 遍，
 * 还得逐条挑图。演示/开业阶段需要货架一次铺满，所以做这个命令行导入器。
 *
 * 【它做什么】
 *   1. 建分类（按 preset-categories.json），并把分类登记进 `veg_fresh_category_root`
 *      —— 这一步不能省：顾客端只展示登记过的根分类，
 *         漏登记的表现是「商品导进去了但顾客端一个都看不到」。
 *   2. 建商品 + 单 SKU + 规格值 + 分类关联（结构与 seed-fresh-demo.sql 一致）。
 *   3. 把 preset-images/P-xx.jpg 拷进 public/static/upload/images/goods/<年>/<月>/<日>/，
 *      并把相对路径写进 `shopxo_goods.images`（该列是逗号分隔的相对路径，
 *      顾客端在 CustomerCatalogService::assetUrl() 里拼域名）。
 *
 * 【幂等】
 *   按 title 判重（未软删的记录），已存在的商品整体跳过，不覆盖店主改过的价格与图片。
 *   重复执行只补缺，不会产生重复商品。
 *
 * 【价格从哪来】
 *   预设库里**故意没有价格**（见 docs/design/常见商品库-清单.md §1.1）——
 *   预填价格有「店主没注意就按错价上架」的风险。
 *   批量导入必须有价格，否则顾客端是 0 元商品。这里用「品类基准价 + 稳定抖动」
 *   生成演示价：抖动取 crc32(名称)，同一条商品每次算出来都一样，重跑不会跳价。
 *   价格只是占位，店主在商家端逐条改即可。
 *
 * 【默认不上架】
 *   导入的商品 `is_shelves = 0`（仓库里备货，顾客端看不到）。
 *   店主确认名称/价格/图片没问题后，在商家端逐条上架，或加 `--shelves` 一次性上架。
 *   这样做的原因：批量导入的价格是算出来的演示价，
 *   直接上架等于把没核对过的价格暴露给顾客。
 *
 * 用法（在服务器上，root 或 www-data）：
 *   php import-preset-goods.php --check            # 只读：统计会新增多少、跳过多少
 *   php import-preset-goods.php --apply            # 真正写入，全部不上架
 *   php import-preset-goods.php --apply --limit=3  # 只导前 3 条（冒烟验证）
 *   php import-preset-goods.php --apply --shelves  # 直接上架（确认过价格再用）
 *
 * 目录约定（默认值，可用参数覆盖）：
 *   ShopXO 根目录 : /var/www/chunlian/shopxo
 *   预设数据目录  : /opt/chunlian/preset
 *                   ├── preset-goods.json
 *                   ├── preset-categories.json
 *                   └── preset-images/P-01.jpg ...
 */

$argvList = $argv;
array_shift($argvList);

$apply = in_array('--apply', $argvList, true);
$checkOnly = in_array('--check', $argvList, true) || !$apply;
$shelves = in_array('--shelves', $argvList, true);

$root = '/var/www/chunlian/shopxo';
$presetDir = '/opt/chunlian/preset';
$limit = 0;

foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--preset-dir=')) {
        $presetDir = substr($arg, strlen('--preset-dir='));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    } elseif (!str_starts_with($arg, '--') && $arg !== '') {
        $root = $arg;
    }
}

$fail = static function (string $msg): never {
    fwrite(STDERR, "✗ {$msg}\n");
    exit(1);
};

echo "春连鲜选 · 预设商品库导入器\n";
echo str_repeat('=', 60), "\n";
echo 'ShopXO 根目录 : ', $root, "\n";
echo '预设数据目录  : ', $presetDir, "\n";
echo '模式          : ', $apply ? 'APPLY（会写入数据库与磁盘）' : 'CHECK（只读）', "\n";
echo '上架状态      : ', $shelves ? '导入即上架' : '导入不上架（顾客端不可见）', "\n";
echo '条数上限      : ', $limit > 0 ? (string) $limit : '不限', "\n\n";

// ------------------------------------------------------------
// 1. 读预设数据
// ------------------------------------------------------------
$goodsFile = $presetDir . '/preset-goods.json';
$catFile = $presetDir . '/preset-categories.json';
$imageDir = $presetDir . '/preset-images';

foreach ([$goodsFile, $catFile] as $f) {
    if (!is_file($f)) {
        $fail("找不到预设文件：{$f}");
    }
}
if (!is_dir($imageDir)) {
    $fail("找不到预设图片目录：{$imageDir}");
}

$goodsJson = json_decode((string) file_get_contents($goodsFile), true, 512, JSON_THROW_ON_ERROR);
$cats = json_decode((string) file_get_contents($catFile), true, 512, JSON_THROW_ON_ERROR);

if (($goodsJson['version'] ?? null) !== 1) {
    $fail('preset-goods.json 的 version 不是 1，本导入器只认 v1');
}
$products = $goodsJson['products'] ?? [];
if (!is_array($products) || $products === []) {
    $fail('preset-goods.json 里没有 products');
}
if (!is_array($cats) || $cats === []) {
    $fail('preset-categories.json 为空');
}
echo '✓ 预设商品 ', count($products), ' 条，分类 ', count($cats), " 个\n";

// ------------------------------------------------------------
// 2. 数据库
// ------------------------------------------------------------
$configFile = $root . '/config/database.php';
if (!is_file($configFile)) {
    $fail("找不到数据库配置：{$configFile}");
}

/** @var array<string,mixed> $dbConfig */
$dbConfig = require $configFile;
$default = (string) ($dbConfig['default'] ?? 'mysql');
$conn = $dbConfig['connections'][$default] ?? $dbConfig;
$prefix = (string) ($conn['prefix'] ?? 'shopxo_');

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) ($conn['hostname'] ?? '127.0.0.1'),
            (int) ($conn['hostport'] ?? 3306),
            (string) ($conn['database'] ?? '')
        ),
        (string) ($conn['username'] ?? ''),
        (string) ($conn['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    $fail('数据库连接失败：' . $e->getMessage());
}
echo "✓ 数据库连接成功（表前缀 {$prefix}）\n";

// 扩展表必须在位，否则顾客端看不见分类
$hasRootTable = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'veg_fresh_category_root'"
)->fetchColumn();
if (!$hasRootTable) {
    $fail('缺少 veg_fresh_category_root 表，请先运行扩展迁移');
}
echo "✓ 分类登记表在位\n";

$uploadRoot = $root . '/public/static/upload/images';
if (!is_dir($uploadRoot)) {
    $fail("找不到上传目录：{$uploadRoot}");
}
echo "✓ 上传目录在位：{$uploadRoot}\n\n";

// ------------------------------------------------------------
// 3. 品类基准价（演示价，店主可逐条改）
// ------------------------------------------------------------
$basePrice = [
    '叶菜类' => 3.5,
    '根茎类' => 3.0,
    '瓜果类' => 5.5,
    '花菜豆荚类' => 4.5,
    '菌菇类' => 6.0,
    '水果类' => 9.0,
    '粮食杂粮类' => 6.5,
    '蛋豆类' => 8.0,
    '豆类坚果类' => 12.0,
    '干货类' => 15.0,
    '肉禽类' => 26.0,
    '水产类' => 22.0,
    '调味配菜' => 2.5,
];

/** 稳定抖动：同一名称永远得到同一价位，重跑不跳价。 */
$priceOf = static function (string $name, string $cat) use ($basePrice): string {
    $base = $basePrice[$cat] ?? 5.0;
    $steps = crc32($name) % 5;              // 0..4
    $value = $base * (0.8 + 0.1 * $steps);  // 0.8x ~ 1.2x
    return number_format(round($value, 1), 2, '.', '');
};

$inventoryOf = static fn (string $name): int => 40 + (int) (crc32($name) % 7) * 10; // 40..100

// ------------------------------------------------------------
// 4. 分类：建 + 登记根分类
// ------------------------------------------------------------
$now = time();
$todayPath = date('Y/m/d');
$imageTargetDir = $uploadRoot . '/goods/' . $todayPath;

$findCat = $pdo->prepare("SELECT `id` FROM `{$prefix}goods_category` WHERE `pid` = 0 AND `name` = :n LIMIT 1");
$insertCat = $pdo->prepare(
    "INSERT INTO `{$prefix}goods_category`
        (`pid`, `name`, `vice_name`, `icon`, `icon_active`, `describe`, `bg_color`,
         `is_home_recommended`, `sort`, `is_enable`, `add_time`, `upd_time`)
     VALUES (0, :name, '', '', '', :descr, :bg, 1, :sort, 1, :t, :t)"
);
$registerRoot = $pdo->prepare(
    'INSERT IGNORE INTO `veg_fresh_category_root` (`category_id`, `created_at`) VALUES (:id, NOW())'
);

$catIdByName = [];
$catNew = 0;
$catReused = 0;

foreach ($cats as $cat) {
    $name = trim((string) ($cat['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $findCat->execute(['n' => $name]);
    $id = $findCat->fetchColumn();

    if ($id === false) {
        $catNew++;
        if ($apply) {
            $insertCat->execute([
                'name' => $name,
                'descr' => '',
                'bg' => '#EAF3E9',
                'sort' => (int) ($cat['sort'] ?? 100),
                't' => $now,
            ]);
            $id = (int) $pdo->lastInsertId();
        }
    } else {
        $catReused++;
        $id = (int) $id;
    }

    if ($apply && $id) {
        // 关键：不登记进这里，顾客端分类树里就没有它，商品等于隐身
        $registerRoot->execute(['id' => $id]);
        $catIdByName[$name] = $id;
    }
}
echo "分类：新建 {$catNew}，复用 {$catReused}\n";

if ($checkOnly) {
    // 只读模式下分类 ID 还没落地，用已有映射估算跳过数
    $existingTitles = $pdo->query(
        "SELECT `title` FROM `{$prefix}goods` WHERE `is_delete_time` = 0"
    )->fetchAll(PDO::FETCH_COLUMN);
    $existingTitles = array_fill_keys(array_map('strval', $existingTitles), true);
    $skip = 0;
    foreach ($products as $p) {
        if (isset($existingTitles[(string) ($p['name'] ?? '')])) {
            $skip++;
        }
    }
    echo '商品：将新增 ', count($products) - $skip, '，已存在跳过 ', $skip, "\n";
    echo "\n只读校验通过。加 --apply 执行导入。\n";
    exit(0);
}

// ------------------------------------------------------------
// 5. 商品：主表 + SKU + 规格值 + 分类关联
// ------------------------------------------------------------
$findGoods = $pdo->prepare(
    "SELECT `id` FROM `{$prefix}goods` WHERE `title` = :t AND `is_delete_time` = 0 LIMIT 1"
);

$insertGoods = $pdo->prepare(
    "INSERT INTO `{$prefix}goods`
        (`title`, `title_color`, `simple_desc`, `spec_desc`, `images`,
         `original_price`, `min_original_price`, `max_original_price`,
         `price`, `min_price`, `max_price`, `inventory`, `inventory_unit`,
         `give_integral`, `buy_min_number`, `buy_max_number`, `is_deduction_inventory`,
         `is_shelves`, `content_web`, `photo_count`, `sales_count`, `access_count`,
         `video`, `is_exist_many_spec`, `spec_base`, `fictitious_goods_value`,
         `use_guide`, `sort_level`, `share_images`, `seo_title`, `seo_keywords`, `seo_desc`,
         `is_delete_time`, `add_time`, `upd_time`)
     VALUES
        (:title, '', '', :spec_desc, :images,
         '0.00', '0.00', '0.00',
         :price, :price2, :price3, :inventory, :unit,
         0, 1, 0, 1,
         :is_shelves, '', 1, 0, 0,
         '', 0, '', '',
         '', :sort_level, '', '', '', '',
         0, :t, :t2)"
);

$insertSku = $pdo->prepare(
    "INSERT INTO `{$prefix}goods_spec_base`
        (`goods_id`, `price`, `original_price`, `inventory`, `buy_min_number`, `buy_max_number`,
         `weight`, `volume`, `coding`, `barcode`, `inventory_unit`, `extends`, `add_time`)
     VALUES (:gid, :price, '0.00', :inventory, 1, 0, 0, 0, '', '', :unit, '[]', :t)"
);

$insertSpecValue = $pdo->prepare(
    "INSERT INTO `{$prefix}goods_spec_value` (`goods_id`, `goods_spec_base_id`, `value`)
     VALUES (:gid, :bid, :value)"
);

$insertJoin = $pdo->prepare(
    "INSERT INTO `{$prefix}goods_category_join` (`goods_id`, `category_id`, `add_time`)
     VALUES (:gid, :cid, :t)"
);

if (!is_dir($imageTargetDir)) {
    if (!@mkdir($imageTargetDir, 0755, true) && !is_dir($imageTargetDir)) {
        $fail("创建上传目录失败：{$imageTargetDir}");
    }
}

$created = 0;
$skipped = 0;
$noImage = 0;
$failed = 0;
$index = 0;

$pdo->beginTransaction();
try {
    foreach ($products as $p) {
        $index++;
        if ($limit > 0 && $created >= $limit) {
            break;
        }

        $title = trim((string) ($p['name'] ?? ''));
        if ($title === '') {
            continue;
        }

        $findGoods->execute(['t' => $title]);
        if ($findGoods->fetchColumn() !== false) {
            $skipped++;
            continue;
        }

        $catName = trim((string) ($p['suggestedCategory'] ?? ''));
        $catId = $catIdByName[$catName] ?? null;
        if ($catId === null) {
            // 分类不在预设清单里就跳过，避免产生「无分类」的孤儿商品
            fwrite(STDERR, "  ! 跳过「{$title}」：分类「{$catName}」未登记\n");
            $failed++;
            continue;
        }

        $specText = trim((string) ($p['specText'] ?? ''));
        $unit = trim((string) ($p['unit'] ?? ''));
        if ($unit === '') {
            $unit = '份';
        }
        $price = $priceOf($title, $catName);
        $inventory = $inventoryOf($title);

        // 图片：拷一份到上传目录，images 列写相对路径
        $imagesRel = '';
        $imageAsset = trim((string) ($p['imageAsset'] ?? ''));
        if ($imageAsset !== '') {
            $src = $imageDir . '/' . basename($imageAsset);
            if (is_file($src)) {
                $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($p['id'] ?? $title));
                $targetName = 'preset-' . $slug . '.jpg';
                $target = $imageTargetDir . '/' . $targetName;
                if (!is_file($target)) {
                    if (!@copy($src, $target)) {
                        fwrite(STDERR, "  ! 「{$title}」图片拷贝失败：{$src}\n");
                        $noImage++;
                    } else {
                        @chmod($target, 0644);
                    }
                }
                $imagesRel = 'static/upload/images/goods/' . $todayPath . '/' . $targetName;
            } else {
                $noImage++;
            }
        }

        $sortLevel = 500 - $index; // 与预设清单顺序一致，越靠前 sort 越大

        $insertGoods->execute([
            'title' => $title,
            'spec_desc' => $specText,
            'images' => $imagesRel,
            'price' => $price,
            'price2' => $price,
            'price3' => $price,
            'inventory' => $inventory,
            'unit' => $unit,
            'is_shelves' => $shelves ? 1 : 0,
            'sort_level' => $sortLevel,
            't' => $now,
            't2' => $now,
        ]);
        $goodsId = (int) $pdo->lastInsertId();

        $insertSku->execute([
            'gid' => $goodsId,
            'price' => $price,
            'inventory' => $inventory,
            'unit' => $unit,
            't' => $now,
        ]);
        $skuId = (int) $pdo->lastInsertId();

        if ($specText !== '') {
            $insertSpecValue->execute(['gid' => $goodsId, 'bid' => $skuId, 'value' => $specText]);
        }

        $insertJoin->execute(['gid' => $goodsId, 'cid' => $catId, 't' => $now]);

        $created++;
        echo "  + {$title}（{$catName} / {$specText} / ¥{$price} / 库存 {$inventory}）\n";
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $fail('导入失败已回滚：' . $e->getMessage());
}

// ------------------------------------------------------------
// 6. 属主修正（root 跑的时候必须做，否则 FPM 读不到图）
// ------------------------------------------------------------
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    @exec('chown -R www-data:www-data ' . escapeshellarg($imageTargetDir));
    @exec('chown -R www-data:www-data ' . escapeshellarg($uploadRoot . '/goods'));
    echo "\n✓ 已把上传目录属主改回 www-data\n";
}

echo "\n", str_repeat('=', 60), "\n";
echo "新增商品 {$created}，跳过已存在 {$skipped}，无图 {$noImage}，失败 {$failed}\n";
echo "上架状态：" . ($shelves ? '已上架（顾客端可见）' : '未上架（顾客端不可见，需在商家端逐条上架）') . "\n";
echo "图片目录：{$imageTargetDir}\n";
echo "提示：价格是按品类生成的演示价，上架前请在商家端逐条核对。\n";
