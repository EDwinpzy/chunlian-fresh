# 春连鲜选顾客站 HTTPS 网关

> **状态：已放弃（2026-10-17）。** 服务器出站到 Cloudflare 全段不通、`workers.dev` 在国内被 DNS 污染，
> 此方案无法给国内顾客提供可用入口。顾客端继续使用 `http://103.236.97.137:36965/`。
> 本目录仅作排查记录保留，不要重新部署；磐石云工单 #607498 也已不再跟进。
> 真 HTTPS 的唯一路径见文末「服务器出站到 Cloudflare 整体不通」一节的结论。

此 Worker 通过 Cloudflare Workers VPC 转发下载页和顾客网页版，不把商家端或管理端暴露到 `workers.dev`。

## 公开路由

- `/downloads/`：下载页、宣传图片和 Android 安装包
- `/app/`：顾客网页版
- `/api/v1/customer/*`：顾客端 API
- `/static/upload/images/*`：顾客目录所用商品图片

其他路径返回 404。顾客 API 只允许网页使用的读写方法，API 响应禁止缓存。商品图片 API 响应中原有的服务器 IP 会被替换为当前 `workers.dev` 地址。

## Cloudflare 配置

1. 在 Workers VPC 创建远程管理的 Tunnel，并在 `panshi-cloud` 上安装 `cloudflared`。Tunnel token 只通过受保护的服务安装命令提供，不要提交到仓库。
   - Workers VPC 要求 Tunnel 使用 QUIC；服务器必须允许向 Cloudflare Tunnel 网络出站 UDP `7844`，无需开放入站端口。TCP/HTTP2 回退不能用于 Workers VPC。部署前确认 Cloudflare 控制台显示 Tunnel 已连接；仅看到 `cloudflared` 服务正在运行不代表 Tunnel 可用。[官方配置要求](https://developers.cloudflare.com/workers-vpc/configuration/tunnel/) · [故障排查](https://developers.cloudflare.com/workers-vpc/reference/troubleshooting/)
2. 在 Nginx 的顾客站 HTTPS server 中增加仅监听 `127.0.0.1:8097` 的 HTTP 入口，然后创建 VPC Service：目标主机 `127.0.0.1`、HTTP 端口 `8097`。此端口只接受服务器本机连接；Cloudflare Tunnel 到源站的链路由 Cloudflare 加密，本机回环上的 HTTP 不经过外部网络。保留相同的路径规则和维护开关。
3. 将新 Service ID 填入 `wrangler.jsonc` 的 `vpc_services[0].service_id`。
4. 使用 Wrangler 部署。账号子域名是 `pengzhiyuan0724.workers.dev`，预期入口为 `https://chunlian-fresh.pengzhiyuan0724.workers.dev`。

## 临时 NAT 回退

如果 Cloudflare Tunnel 的 UDP `7844` 暂时不可用，Nginx 可额外监听内部 TCP `8098`。`infrastructure/nginx/chunlian-customer-nat.conf` 只转发下载页、顾客 H5、顾客 API 和商品图片，其他路径返回 404；在磐石云 NAT 页面将外部端口留空，让平台分配，再把生成端口设为 `NAT_ORIGIN_BASE`。Worker 仍优先使用 VPC，连接失败时才使用该回退。

此回退经公网 NAT 使用 HTTP 连接源站，只在等待隧道恢复期间使用；客户访问仍应使用 `workers.dev` 的 HTTPS 入口。Tunnel 可用并验证后，应移除 NAT 回退配置和转发规则，再重新部署 Worker。

### NAT 回退必须用域名，不能用 IP 字面量

`NAT_ORIGIN_BASE` 写成 `http://103.236.97.137:36965` 时，`workers.dev` 上的页面返回 `403`、响应体只有 `error code: 1003`。这是 Cloudflare 对 Worker 出站子请求的限制：不允许直接以 IP 字面量访问非标准端口，与源站无关（同一地址从浏览器直连返回 `200`）。

改用解析到同一台机器的域名即可，例如免费通配解析 `http://103-236-97-137.sslip.io:36965`。Nginx 的 `8098` 入口使用 `server_name _`，对 Host 不做限制，换域名不需要改服务器配置，只改 Cloudflare 里 Worker 的 Production 变量 `NAT_ORIGIN_BASE`，保存即生效，无需重新部署代码。

### 服务器出站到 Cloudflare 整体不通（2026-10-17 实测）

`panshi-cloud` 上对 Cloudflare 任意地址的 TCP `443` 全部超时（`www.cloudflare.com`、`1.1.1.1`、`104.16.x.x`），UDP `7844` 同样超时；同机访问 `baidu.com` 的 `80/443` 正常，`acme-v02.api.letsencrypt.org` 超时。即故障不是「只缺 UDP 7844」这一条，而是整段 Cloudflare 路由不可达，Workers VPC 隧道和 Let's Encrypt 签发都无法在这台机器上完成。

同一台机器的 DNS 把 `*.workers.dev` 解析成 `31.13.94.7`（Facebook 的地址段），属于典型的 DNS 污染。这意味着**国内终端很可能也无法解析 `workers.dev`**，此入口不能当作面向国内顾客的正式地址。

Workers VPC 目前处于免费 Beta。`workers.dev` 官方定位为个人或兴趣项目，业务关键服务建议后续迁到自有域名。

## 本地路由检查

```powershell
node --test infrastructure/cloudflare-customer-gateway/test/routing.test.mjs
```
