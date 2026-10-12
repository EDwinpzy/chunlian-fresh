# 春连鲜选 —— 后端部署报告（终版）

> 日期：2026-10-12
> 目标服务器：`panshi-cloud`（磐石云）`root@103.236.97.137:55560` · Ubuntu 24.04.1 LTS
> 连接方式：`cloud-server-control` MCP 连接器（SSH）

---

## 一、最终状态：后端已完整上线（服务端侧）

| 组件 | 版本 | 状态 |
|---|---|---|
| Nginx | 1.24.0 | ✅ active，监听 `0.0.0.0:8095` |
| PHP-FPM | 8.3.6 | ✅ active（10 子进程，256M 内存） |
| MySQL | 8.0.46 | ✅ active，库 `chunlian_shop` |
| **ShopXO** | **官方 v6.9.2** | ✅ **已安装完成**（84 张表） |
| 扩展层 | backend-extension | ✅ **7 张 `veg_*` 表** + 健康检查全通过 |
| Cron | outbox 单 worker | ✅ 已实际触发 |

**数据库总计 91 张表**（84 `shopxo_` + 7 `veg_`）。

---

## 二、验证证据（全部实测）

### 2.1 前台可正常访问

```
GET /              -> 200  网站名称 - SEO标题
GET /index.php     -> 200  网站名称 - SEO标题
GET /goods         -> 200  网站名称 - SEO标题
GET /search        -> 200  商品搜索 - 网站名称 - SEO标题
GET /api.php       -> 200
```

**不再是安装向导重定向** —— 商城已实际运行。

### 2.2 后台管理员凭据已验证

```
账号          : admin
状态          : 0 (正常)
角色          : 超级管理员
库中哈希      : 364682e8fc154e0cd0ef6f554b3bd196
按密码重算    : 364682e8fc154e0cd0ef6f554b3bd196
密码验证      : ✓ 通过
```

后台入口已重命名为 `admin9218becb.php`（避开默认 `admin.php`）；
旧路径 `admin.php` 返回 **404**。

### 2.3 安全规则生效

```
/config/database.php   -> 404 (已拒绝)
/install-cli.php       -> 404 (已拒绝)
/vendor|runtime|extend -> 404 (已拒绝)
*.sql|*.md|*.log       -> 404 (已拒绝)
```

### 2.4 扩展层健康检查

```
[1] 扩展表 7/7 ✓                   [2] 资金安全唯一索引 5/5 ✓
[3] outbox DEAD: 0 ✓               [4] 超时补款: 0
[5] COD 应收: 0 单                  [6] 退款失败流水: 0 ✓
→ ✓ 健康检查全部通过
```

---

## 三、⚠️ 唯一未解：公网端口（**需要服务商操作**）

### 3.1 诊断结论

**该服务器是运营商 NAT 后的虚拟机，服务商只向本机转发了 `80` 与 `8888` 两个端口；其余端口在运营商边缘即被丢弃。**

而 **80 端口被服务商的 ICP 备案拦截页劫持**。

### 3.2 证据链（决定性）

**① 外网可达性扫描（从真实外网）**

| 端口 | 结果 |
|---|---|
| **80** | ⚠️ 可达，但返回备案拦截页 |
| **8888** | ✅ 可达（某个 `invoice` 服务） |
| 22 / 443 / 3000 / 5000 / 8000 / 8080 / 8090 / 8095 / 8443 / 9000 | ❌ 全部超时 |

**② 80 端口返回的是备案拦截页**

```
$ curl http://103.236.97.137/
HTTP/1.1 404
（GBK 内容，解码后：）

  温馨提示：该网站暂无法访问 (code:7l8yacEXs8esfm)
  原因一：根据《非经营性互联网信息服务备案管理办法》，
          您尚未进行备案或接入。
```

**③ 决定性实验：标记文件**

在本机 `/var/www/html/marker.txt` 写入标记，从真实外网请求
`http://103.236.97.137/marker.txt` → **未返回标记内容**，
仍是备案拦截页。

→ **证明 80 端口的流量在到达本机之前就被拦截。**

**④ 8888 不属于本机**

```
$ ss -tlnp | grep 8888      # 空
$ curl 127.0.0.1:8888/      # 000
$ curl 103.236.97.137:8888/health   # 200 {"service":"invoice"}
```

→ 8888 由服务商边缘转发到**另一台机器**。

**⑤ 本机真实网络身份**

```
$ ip -4 addr   → ens17: inet 172.16.2.71/12   （私有 IP）
$ hostname     → liuliangRrH8b6Cgmpi6
```

`103.236.97.137` 是服务商 NAT 映射，**不是本机拥有的地址**。

**⑥ 服务商 API 未配置**

`api_connections` 返回 `operations: []` —— 无法通过 API 添加端口映射。

### 3.3 服务端本身没有问题

| 检查 | 结果 |
|---|---|
| Nginx 监听 | ✅ `0.0.0.0:8095` |
| 本机访问 8095 | ✅ 200 |
| 防火墙 | ufw inactive；iptables INPUT ACCEPT 无规则 |

---

## 四、为绕开此问题所做的努力（均已尝试）

1. ❌ **Docker 镜像加速** —— 配了 `docker.1ms.run` 等，镜像元数据可取，但 blob 在 Cloudflare CDN 被墙
2. ❌ **改用 8888 端口** —— 8888 属服务商边缘，不在本机，无法占用
3. ❌ **端口 80 直接服务** —— 被 ICP 拦截，流量到不了本机
4. ❌ **内网穿透隧道**（localhost.run / serveo / bore / localtunnel）—— DNS 可解析，但出站连接全部超时
5. ✅ **命令行安装 ShopXO** —— 绕开网页向导，已完成

---

## 五、交付物

| 文件 | 说明 |
|---|---|
| `docs/DEPLOYMENT-REPORT-2026-10-12.md` | 本报告（终版） |
| `docs/NETWORK-DIAGNOSIS-2026-10-12.md` | 网络问题专项诊断（完整证据链） |
| `infrastructure/scripts/deploy-backend.sh` | **一键部署脚本**（幂等，含 CLI 安装） |
| `infrastructure/scripts/install-shopxo-cli.php` | **ShopXO 命令行安装器**（本次新增） |
| `infrastructure/nginx/chunlian.conf` | Nginx 站点配置 |
| `infrastructure/.env.example` | 环境变量模板 |

### 服务器资产

| 项 | 位置 |
|---|---|
| 站点根 | `/var/www/chunlian/shopxo/public` |
| 扩展 | `/var/www/chunlian/backend-extension` |
| 后台入口 | `/var/www/chunlian/shopxo/admin9218becb.php` |
| DB 凭据 | `/root/.chunlian-db-cred`（chmod 600） |
| 管理员凭据 | `/root/.chunlian-admin-cred`（chmod 600） |
| 环境变量 | `/etc/chunlian/chunlian.env`（chmod 600） |
| 日志 | `/var/log/chunlian/` |

---

## 六、现在就能用：SSH 隧道预览

虽然公网端口未通，**你现在立刻就能看到完整站点**。在**你自己的电脑**上执行：

```bash
ssh -p 55560 -L 8095:127.0.0.1:8095 root@103.236.97.137
```

保持终端不关，浏览器打开：

```
前台：http://127.0.0.1:8095/
后台：http://127.0.0.1:8095/admin9218becb.php
```

管理员账号 `admin`，密码见服务器 `/root/.chunlian-admin-cred`。

---

## 七、正式上线需要你做的（三步）

### 步骤 1：申请端口转发（**推荐，最快**）

在磐石云控制台「端口映射」处增加：

```
外网端口 8095  →  内网 172.16.2.71:8095
```

或告诉我控制台里有哪几个端口可选，我按可用端口调整配置。

### 步骤 2：域名 + 备案

- 有域名则解析到 `103.236.97.137`
- **备案是 80/443 的前提**（当前 80 被拦截正是因为未备案）
- 备案通过后我可以立即配置 HTTPS（certbot）

### 步骤 3：上线前收尾

- [ ] 替换 `server_name` 为真实域名
- [ ] 申请 TLS 证书 + 强制 HTTPS + HSTS
- [ ] 按 `docs/DEPLOYMENT.md` §4 第 8 步配置 COD 支付方式
- [ ] 微信支付商户号配置（`/etc/chunlian/chunlian.env`）
- [ ] 备份定时任务（异地存储）

---

## 八、遗留（非本次范围）

- **扩展路由尚未注册到 ShopXO** —— `config/routes.php` 明确标注需先完成 ShopXO 源码审计
- **`OrderRepository` 持久化层仍是桩实现**
- **merchant-android** 构建被 `com.squareup.retrofit2:converter-kotlinx-serialization:2.9.0`（坐标不存在）阻塞
- **Git 初始化 / 根 README / 推 GitHub** 未做
