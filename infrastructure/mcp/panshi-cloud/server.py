#!/usr/bin/env python3
"""磐石云服务器 MCP —— 直接对春连鲜选的线上机器执行命令。

【为什么要有它】
每次改后端都要「打开浏览器 → 登录磐石云 → 开 VNC → 敲命令」，
VNC 里连复制粘贴都别扭，更别说传 27MB 的图片包。
这里把 SSH 包成 MCP 工具，之后一句话就能跑命令、传文件、查库。

【凭据从哪来】
优先读环境变量 PSC_HOST / PSC_PORT / PSC_USER / PSC_PASSWORD，
读不到再读同目录下 `credential.json`（已被 .gitignore 排除，不要提交）。
密码本身不写进仓库 —— 它在磐石云产品详情页「登录信息」里可见，需要时重新取。

【安全边界】
这些工具等价于 root shell，没有二次确认。只在自己信任的会话里启用；
不要把 credential.json 提交到仓库，也不要贴到聊天里。
"""

from __future__ import annotations

import json
import logging
import os
import re
import shlex
import threading
import time
from pathlib import Path

import paramiko
from mcp.server.mcpserver import MCPServer

# paramiko 默认把每次认证、每个 SFTP 通道都打到 stderr，MCP 下会刷屏
logging.getLogger("paramiko").setLevel(logging.WARNING)

DEFAULTS = {"host": "103.236.97.137", "port": 55560, "user": "root", "password": ""}
CRED_FILE = Path(__file__).with_name("credential.json")
SHOPXO_ROOT = "/var/www/chunlian/shopxo"
DB_NAME = "chunlian_shop"

mcp = MCPServer("panshi-cloud")

_lock = threading.Lock()
_client: paramiko.SSHClient | None = None


def _settings() -> dict:
    cfg = dict(DEFAULTS)
    if CRED_FILE.is_file():
        try:
            cfg.update({k: v for k, v in json.loads(CRED_FILE.read_text("utf-8")).items() if v})
        except Exception as exc:  # noqa: BLE001
            raise RuntimeError(f"credential.json 解析失败: {exc}") from exc
    for env_key, cfg_key in (
        ("PSC_HOST", "host"),
        ("PSC_PORT", "port"),
        ("PSC_USER", "user"),
        ("PSC_PASSWORD", "password"),
    ):
        val = os.environ.get(env_key)
        if val:
            cfg[cfg_key] = int(val) if cfg_key == "port" else val
    if not cfg.get("password"):
        raise RuntimeError("缺少 SSH 密码：设置环境变量 PSC_PASSWORD，或在本目录放 credential.json")
    cfg["port"] = int(cfg["port"])
    return cfg


def _connect() -> paramiko.SSHClient:
    """复用连接：每次重连要 1~2 秒，连续查库时很亏。"""
    global _client
    with _lock:
        if _client is not None:
            transport = _client.get_transport()
            if transport is not None and transport.is_active():
                return _client
        cfg = _settings()
        cli = paramiko.SSHClient()
        cli.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        cli.connect(
            cfg["host"],
            port=cfg["port"],
            username=cfg["user"],
            password=cfg["password"],
            timeout=20,
            banner_timeout=30,
            auth_timeout=30,
        )
        transport = cli.get_transport()
        if transport is not None:
            transport.set_keepalive(30)
        _client = cli
        return cli


def _run(command: str, timeout: int = 120) -> tuple[int, str, str]:
    cli = _connect()
    _, stdout, stderr = cli.exec_command(command, timeout=timeout)
    out = stdout.read().decode("utf-8", "replace")
    err = stderr.read().decode("utf-8", "replace")
    return stdout.channel.recv_exit_status(), out, err


def _fmt(code: int, out: str, err: str) -> str:
    parts = [out.rstrip()]
    if err.strip():
        parts.append("[stderr]\n" + err.rstrip())
    parts.append(f"[exit={code}]")
    return "\n".join(p for p in parts if p)


def _sftp_write(remote_path: str, data: bytes) -> int:
    """不用 sftp.put()：paramiko 的 put 会先做远端 stat，在这台机器上会误报 ENOENT。"""
    sftp = _connect().open_sftp()
    try:
        with sftp.open(remote_path, "wb") as fh:
            fh.write(data)
        return sftp.stat(remote_path).st_size
    finally:
        sftp.close()


# PHP 片段：用站点自己的 database.php 取凭据，避免密码出现在命令行里。
# 外层用 shell 单引号包住，所以里面只用双引号。
_PHP_BOOT = (
    '$c=require "' + SHOPXO_ROOT + '/config/database.php";'
    "$d=$c['connections'][$c['default']];"
)


@mcp.tool()
def cloud_exec(command: str, timeout: int = 120) -> str:
    """在线上服务器执行一条 shell 命令（root 权限），返回 stdout/stderr 与退出码。

    Args:
        command: 要执行的命令，例如 "systemctl status nginx --no-pager"
        timeout: 超时秒数；打包、迁移这类长任务给 600
    """
    try:
        code, out, err = _run(command, timeout)
    except Exception as exc:  # noqa: BLE001
        return f"[连接失败] {exc}"
    return _fmt(code, out, err)


@mcp.tool()
def cloud_upload(local_path: str, remote_path: str) -> str:
    """把本机文件上传到服务器（覆盖写），返回字节数与耗时。

    Args:
        local_path: 本机绝对路径
        remote_path: 服务器绝对路径，父目录需已存在
    """
    src = Path(local_path)
    if not src.is_file():
        return f"[失败] 本地文件不存在: {local_path}"
    data = src.read_bytes()
    started = time.time()
    try:
        size = _sftp_write(remote_path, data)
    except Exception as exc:  # noqa: BLE001
        return f"[上传失败] {exc}"
    return f"已上传 {remote_path}（{size} 字节，{time.time() - started:.1f}s）"


@mcp.tool()
def cloud_download(remote_path: str, local_path: str) -> str:
    """从服务器下载文件到本机（覆盖写）。

    Args:
        remote_path: 服务器绝对路径
        local_path: 本机绝对路径
    """
    try:
        sftp = _connect().open_sftp()
        try:
            with sftp.open(remote_path, "rb") as fh:
                data = fh.read()
        finally:
            sftp.close()
    except Exception as exc:  # noqa: BLE001
        return f"[下载失败] {exc}"
    dst = Path(local_path)
    dst.parent.mkdir(parents=True, exist_ok=True)
    dst.write_bytes(data)
    return f"已下载 {local_path}（{len(data)} 字节）"


@mcp.tool()
def cloud_read_file(remote_path: str, max_bytes: int = 200000) -> str:
    """读取服务器上的文本文件。

    Args:
        remote_path: 服务器绝对路径
        max_bytes: 最多读取多少字节，超出截断
    """
    try:
        sftp = _connect().open_sftp()
        try:
            with sftp.open(remote_path, "rb") as fh:
                data = fh.read(max_bytes)
        finally:
            sftp.close()
    except Exception as exc:  # noqa: BLE001
        return f"[读取失败] {exc}"
    text = data.decode("utf-8", "replace")
    if len(data) >= max_bytes:
        text += f"\n…（已截断，只读前 {max_bytes} 字节）"
    return text


@mcp.tool()
def cloud_write_file(remote_path: str, content: str, owner: str = "") -> str:
    """把文本内容写到服务器文件（覆盖写）。

    Args:
        remote_path: 服务器绝对路径
        content: 完整文本内容
        owner: 写入后 chown 给谁，例如 "www-data:www-data"；留空不改属主
    """
    try:
        size = _sftp_write(remote_path, content.encode("utf-8"))
        if owner:
            _run(f"chown {shlex.quote(owner)} {shlex.quote(remote_path)}", 30)
    except Exception as exc:  # noqa: BLE001
        return f"[写入失败] {exc}"
    return f"已写入 {remote_path}（{size} 字节）" + (f"，属主 {owner}" if owner else "")


@mcp.tool()
def cloud_db_query(sql: str, database: str = DB_NAME) -> str:
    """在线上 MySQL 执行一条查询并返回 JSON（凭据取自站点 database.php，不用手输密码）。

    SQL 先落到 /tmp 再读，避免命令行转义把引号吃掉。
    只适合查询与小改动；批量导入请写脚本，别用这个拼长 SQL。

    Args:
        sql: 一条 SELECT / SHOW / UPDATE 语句
        database: 库名，默认 chunlian_shop
    """
    if not re.fullmatch(r"[A-Za-z0-9_]+", database):
        return "[拒绝] 库名不合法"
    script = (
        _PHP_BOOT
        + "$p=new PDO(\"mysql:host={$d['hostname']};port={$d['hostport']};dbname="
        + database
        + "\",$d['username'],$d['password']);"
        "$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);"
        "$s=file_get_contents('/tmp/.psc_query.sql');"
        "$st=$p->query($s);"
        "if($st===false){echo 'query failed';exit(1);}"
        "$r=$st->fetchAll(PDO::FETCH_ASSOC);"
        "echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),\"\\n\";"
    )
    try:
        _sftp_write("/tmp/.psc_query.sql", sql.encode("utf-8"))
        code, out, err = _run("php -r " + shlex.quote(script), 120)
    except Exception as exc:  # noqa: BLE001
        return f"[SQL 失败] {exc}"
    if code != 0:
        return f"[SQL 失败]\n{out}\n{err}"
    return out.strip() or "(无返回行)"


@mcp.tool()
def cloud_service(action: str, name: str) -> str:
    """管理 systemd 服务（nginx / php8.3-fpm / mysql / redis-server 等）。

    Args:
        action: status | start | stop | restart | reload
        name: 服务名
    """
    if action not in {"status", "start", "stop", "restart", "reload"}:
        return "[拒绝] action 只能是 status/start/stop/restart/reload"
    if not re.fullmatch(r"[A-Za-z0-9_.@-]+", name):
        return "[拒绝] 服务名不合法"
    if action == "status":
        code, out, err = _run(f"systemctl status {name} --no-pager -l | head -30", 40)
        return _fmt(code, out, err)
    code, out, err = _run(f"systemctl {action} {name} && echo OK", 90)
    return _fmt(code, out, err)


@mcp.tool()
def cloud_probe() -> str:
    """一次性体检：负载、磁盘、内存、关键服务、商品与订单量。"""
    cmd = (
        "echo '== 负载 =='; uptime; "
        "echo '== 磁盘 =='; df -h / | tail -1; "
        "echo '== 内存 =='; free -m | head -2; "
        "echo '== 服务 =='; for s in nginx php8.3-fpm mysql redis-server; do "
        "printf '%-16s %s\\n' \"$s\" \"$(systemctl is-active $s 2>/dev/null)\"; done; "
        f"echo '== 站点 =='; ls -ld {SHOPXO_ROOT} 2>/dev/null"
    )
    code, out, err = _run(cmd, 90)
    head = _fmt(code, out, err)

    counts = cloud_db_query(
        "SELECT 'goods' AS t, COUNT(*) AS n FROM shopxo_goods WHERE is_delete_time=0 "
        "UNION ALL SELECT 'goods_off', COUNT(*) FROM shopxo_goods WHERE is_delete_time=0 AND is_shelves=0 "
        "UNION ALL SELECT 'orders', COUNT(*) FROM shopxo_order"
    )
    return head + "\n== 数据 ==\n" + counts


if __name__ == "__main__":
    mcp.run()
