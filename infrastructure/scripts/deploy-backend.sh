#!/usr/bin/env bash
# ============================================================
# 春连鲜选 —— 后端部署脚本（Ubuntu 24.04 / apt 原生安装）
#
# 背景：本脚本针对「出网受限」的服务器编写。
#   实测磐石云 103.236.97.137 无法访问 GitHub / Docker Hub /
#   Cloudflare CDN，只能走 mirrors.aliyun.com 与 gitee.com。
#   因此**不使用 Docker 镜像**，改用 apt 原生安装 LAMP。
#
# 用法（在服务器上以 root 执行）：
#   bash deploy-backend.sh
#
# 幂等：可重复执行；已存在的资源会跳过。
# ============================================================
set -euo pipefail

SHOPXO_VERSION="v6.9.2"
SHOPXO_URL="https://gitee.com/zongzhige/shopxo/repository/archive/${SHOPXO_VERSION}.zip"
WEB_ROOT="/var/www/chunlian"
PORT="8095"
DB_NAME="chunlian_shop"
DB_USER="chunlian"
TIMEZONE="Asia/Shanghai"

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()  { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }

if [[ $EUID -ne 0 ]]; then
  echo "请以 root 执行" >&2; exit 1
fi

# ------------------------------------------------------------
# 0. 前置：磁盘检查
# ------------------------------------------------------------
log "0. 磁盘空间检查"
AVAIL_KB=$(df -Pk / | awk 'NR==2{print $4}')
AVAIL_GB=$((AVAIL_KB / 1024 / 1024))
echo "  可用: ${AVAIL_GB}GB"
if (( AVAIL_GB < 3 )); then
  echo "  ⚠ 磁盘不足 3GB，先清理 Docker 构建缓存：" >&2
  echo "    docker builder prune -af" >&2
  exit 1
fi
ok "磁盘充足"

# ------------------------------------------------------------
# 1. 安装依赖（apt，走阿里云源）
# ------------------------------------------------------------
log "1. 安装 Nginx / PHP 8.3 / MySQL 8"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q \
  unzip zip git curl ca-certificates cron \
  nginx mysql-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl \
  php8.3-xml php8.3-gd php8.3-zip php8.3-intl php8.3-bcmath php8.3-opcache
ok "软件包安装完成"

# ------------------------------------------------------------
# 2. 拉取 ShopXO 官方发行版（Gitee，非 GitHub）
# ------------------------------------------------------------
log "2. 获取 ShopXO ${SHOPXO_VERSION}"
mkdir -p "${WEB_ROOT}"
cd "${WEB_ROOT}"

if [[ ! -d "${WEB_ROOT}/shopxo" ]]; then
  if [[ ! -f "${WEB_ROOT}/shopxo-${SHOPXO_VERSION}.zip" ]]; then
    curl -fsSL -o "${WEB_ROOT}/shopxo-${SHOPXO_VERSION}.zip" "${SHOPXO_URL}"
  fi
  unzip -q "${WEB_ROOT}/shopxo-${SHOPXO_VERSION}.zip" -d "${WEB_ROOT}/_tmp"
  mv "${WEB_ROOT}/_tmp/shopxo-${SHOPXO_VERSION}" "${WEB_ROOT}/shopxo"
  rm -rf "${WEB_ROOT}/_tmp"
  ok "ShopXO 已解压到 ${WEB_ROOT}/shopxo"
else
  ok "ShopXO 已存在，跳过"
fi

# ------------------------------------------------------------
# 3. 数据库
# ------------------------------------------------------------
log "3. 创建数据库与账号"
CRED_FILE="/root/.chunlian-db-cred"
if [[ ! -f "${CRED_FILE}" ]]; then
  DBPASS=$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)
  echo "GENERATED_PASS=${DBPASS}" > "${CRED_FILE}"
  chmod 600 "${CRED_FILE}"
else
  # shellcheck disable=SC1090
  . "${CRED_FILE}"
  DBPASS="${GENERATED_PASS}"
fi

mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DBPASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DBPASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "数据库 ${DB_NAME} 就绪（密码存于 ${CRED_FILE}）"

# ------------------------------------------------------------
# 4. PHP 调优
# ------------------------------------------------------------
log "4. PHP 配置"
mkdir -p /var/log/chunlian
cat > /etc/php/8.3/fpm/conf.d/99-chunlian.ini <<'INI'
; 春连鲜选 专用 PHP 配置（见 docs/DEPLOYMENT.md §5）
upload_max_filesize = 5M
post_max_size = 12M
memory_limit = 256M
max_execution_time = 60
max_input_time = 60
date.timezone = Asia/Shanghai
default_charset = UTF-8
display_errors = Off
log_errors = On
error_log = /var/log/chunlian/php-error.log
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
INI
cp /etc/php/8.3/fpm/conf.d/99-chunlian.ini /etc/php/8.3/cli/conf.d/99-chunlian.ini

sed -i 's/^pm.max_children = 5/pm.max_children = 10/' /etc/php/8.3/fpm/pool.d/www.conf
sed -i 's/^;\?pm.start_servers = .*/pm.start_servers = 3/' /etc/php/8.3/fpm/pool.d/www.conf
sed -i 's/^;\?pm.min_spare_servers = .*/pm.min_spare_servers = 2/' /etc/php/8.3/fpm/pool.d/www.conf
sed -i 's/^;\?pm.max_spare_servers = .*/pm.max_spare_servers = 5/' /etc/php/8.3/fpm/pool.d/www.conf
ok "PHP 调优完成"

# ------------------------------------------------------------
# 5. 权限
# ------------------------------------------------------------
log "5. 文件权限"
chown -R www-data:www-data \
  "${WEB_ROOT}/shopxo/runtime" \
  "${WEB_ROOT}/shopxo/public" \
  "${WEB_ROOT}/shopxo/extend" \
  /var/log/chunlian 2>/dev/null || true
chmod -R 755 "${WEB_ROOT}/shopxo/public"
ok "权限设置完成（PHP-FPM 以 www-data 运行）"

# ------------------------------------------------------------
# 6. 扩展迁移
# ------------------------------------------------------------
log "6. 执行 veg_* 表迁移"
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME="${DB_NAME}" \
       DB_USER="${DB_USER}" DB_PASS="${DBPASS}" DB_PREFIX=shopxo_

cd "${WEB_ROOT}/backend-extension"
php scripts/migrate.php --dry-run
php scripts/migrate.php --apply
php scripts/health-check.php
ok "迁移与健康检查通过"

# ------------------------------------------------------------
# 6.5 ShopXO 安装（CLI，用于无公网端口时）
# ------------------------------------------------------------
# 背景：若云服务商未转发端口（本项目实测 8095 不可达），
#       网页安装向导 install.php 无法访问。
#       install-shopxo-cli.php 直接落地向导的等价操作。
#
# 已有 config/database.php 说明已安装，脚本会自行跳过。
log "6.5 ShopXO 安装（CLI）"
if [[ -f "${WEB_ROOT}/shopxo/config/database.php" ]]; then
  ok "ShopXO 已安装，跳过"
else
  ADMINPWD=$(openssl rand -base64 18 | tr -d '/+=' | head -c 16)
  echo "ADMIN_USER=admin" > /root/.chunlian-admin-cred
  echo "ADMIN_PWD=${ADMINPWD}" >> /root/.chunlian-admin-cred
  chmod 600 /root/.chunlian-admin-cred

  export ADMIN_USERNAME=admin ADMIN_PWD="${ADMINPWD}"
  php "${WEB_ROOT}/shopxo/install-cli.php" --apply

  # 重命名后台入口（官方安全实践）
  NEWADMIN="admin$(openssl rand -hex 4).php"
  for d in "${WEB_ROOT}/shopxo" "${WEB_ROOT}/shopxo/public"; do
    [[ -f "${d}/admin.php" ]] && mv "${d}/admin.php" "${d}/${NEWADMIN}"
  done
  echo "ADMIN_ENTRY=${NEWADMIN}" >> /root/.chunlian-admin-cred
  ok "ShopXO 安装完成（后台入口 ${NEWADMIN}，凭据 /root/.chunlian-admin-cred）"
fi

# ------------------------------------------------------------
# 7. cron（outbox 单 worker）
# ------------------------------------------------------------
log "7. 安装定时任务"
cat > /usr/local/bin/chunlian-outbox <<'SH'
#!/bin/bash
set -euo pipefail
set -a; . /etc/chunlian/chunlian.env; set +a
cd /var/www/chunlian/backend-extension
exec /usr/bin/php scripts/outbox-worker.php --once
SH
chmod 755 /usr/local/bin/chunlian-outbox

cat > /etc/cron.d/chunlian <<'CRON'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * root /usr/local/bin/chunlian-outbox >> /var/log/chunlian/outbox.log 2>&1
CRON
chmod 644 /etc/cron.d/chunlian
ok "cron 已安装（每分钟 outbox）"

# ------------------------------------------------------------
# 8. 启动
# ------------------------------------------------------------
log "8. 启动服务"
systemctl enable --now nginx php8.3-fpm mysql cron
systemctl reload nginx
systemctl restart php8.3-fpm
ok "服务已启动"

# ------------------------------------------------------------
# 汇总
# ------------------------------------------------------------
log "部署完成"
cat <<EOF

  站点根目录 : ${WEB_ROOT}/shopxo/public
  扩展目录   : ${WEB_ROOT}/backend-extension
  监听端口   : ${PORT}
  数据库     : ${DB_NAME} @ 127.0.0.1（用户 ${DB_USER}）
  环境变量   : /etc/chunlian/chunlian.env
  日志       : /var/log/chunlian/

  下一步：
    1. 浏览器打开 http://<服务器IP>:${PORT}/ 完成 ShopXO 安装向导
       - 数据库：${DB_NAME} / ${DB_USER} / 见 ${CRED_FILE}
    2. 域名解析后配置 HTTPS：
         apt install certbot python3-certbot-nginx
         certbot --nginx -d <你的域名>
    3. 安全组仅放行 22/80/443/8095，3306 不对公网开放

EOF
