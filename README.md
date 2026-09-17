# 便签系统

PHP + SQLite。依赖扩展：`pdo_sqlite` / `sodium` / `gd` / `mbstring`
（不依赖 `fileinfo`，图片类型用 `getimagesize()` 判断。）

## 目录结构

```
index.php           主页 = 便签列表（直接访问站点根即可）
view.php            便签详情；管理员可在此单张删除照片
edit.php            新建/编辑便签 + 上传照片（带进度条）
login.php           登录；首次访问时初始化管理员账号
photo.php           照片输出（密码便签的照片在这里解密）
delete.php          删除整条便签（含其全部照片）
photo_delete.php    只删除某一张照片
logout.php
router.php          内置服务器入口（同时拦截内部目录）
style.css
app/                内部库，web 不可达
  lib.php           数据库 / 会话 / CSRF / 访问限速
  crypto.php        sodium 加解密
data/app.db         SQLite（便签、用户、照片元数据）
photos/*.bin        照片文件（密码便签的为密文）
```

## 本地启动

```bash
php -S 0.0.0.0:8080 router.php
```

必须带 `router.php`，它会拦截 `data/` `photos/` `app/` 的直接访问。
浏览器打开 http://localhost:8080/ 即主页。

## 部署（重要）

线上是 **nginx**（宝塔 / ESA，响应头 `server: ESA`）。
**nginx 不读 `.htaccess`** —— 根目录那个 `.htaccess` 只在 Apache 下生效。

> 实测（nginx 1.27.4）：只放 `.htaccess`、不给 nginx 加规则时，
> `/test/data/app.db`、`/test/photos/*.bin`、`备份.zip` 全部返回 **200 可直接下载**。
> 所以线上**必须**把下面的 nginx 规则填进宝塔，否则数据库等于公开。

### 宝塔伪静态（复制这一段）

宝塔 → 网站 → 设置 → 伪静态：

```nginx
# ACME 证书校验必须放行，否则 HTTPS 续签会失败
location ^~ /.well-known/acme-challenge/ { allow all; }

# 内部目录。边界用 (/|$) 锚定，避免 datax / myapp 被误伤
if ($uri ~* "/(app|data|photos|_bak|hexstrike_envs|hexstrike_data)(/|$)") { return 403; }

# router.php 只给 php -S 内置服务器用，线上必须拒绝（否则会被当脚本执行）
if ($uri ~* "(^|/)router\.php$") { return 403; }

# 隐藏文件（.env/.htaccess/.git），但放过 .well-known
if ($uri ~* "(^|/)\.(?!well-known)") { return 403; }

# 危险后缀（备份包不要放在网站根目录）
if ($uri ~* "\.(bak|orig|old|db|db-wal|db-shm|sqlite|sqlite3|ini|log|md|txt|bin|zip|tar|gz|7z|rar|sql)$") { return 403; }
```

**为什么用 server 级 `if` 而不是 `location ^~`：**

1. **不受子目录影响。** 本项目线上在 `/test/` 子目录，而 `location ^~ /data/` 匹配的是
   URI 全路径 —— `/test/data/app.db` 不匹配 `/data/`，规则形同虚设。
   `if ($uri ~ ...)` 是正则搜全路径，放哪层目录都生效，**不用改前缀**。
2. **不受规则顺序影响。** `if` 在 location 匹配**之前**求值，所以不会被宝塔
   `enable-php` 的 `location ~ \.php` 按定义顺序抢走。
   （用 `location ~ ^/(app|data|...)` 时实测 `/app/lib.php`、`/app/crypto.php`、
   `/router.php` 会被 PHP **执行**。）

### 验证是否生效

浏览器直接打开下面两个地址，**都应该是 403 Forbidden**：

```
https://你的域名/test/data/app.db
https://你的域名/test/app/lib.php
```

若返回 200 或下载到文件，说明规则没生效，数据库正在被公开下载。

### 更彻底的做法

把站点根目录直接指向项目根（而不是 `/test/` 子目录），规则就不必考虑层级。
宝塔 → 网站 → 设置 → 网站目录。

### PHP 上传限制

宝塔 → 软件商店 → PHP → 配置修改 → 重载：

```ini
upload_max_filesize = 20M
post_max_size = 25M
```

> 若 `post_max_size` 太小，上传会在 PHP 层被丢弃。此时页面会提示
> “上传内容 X MB 超过服务器 post_max_size”，而不是含糊的 CSRF 报错。

### 静态资源缓存

`style.css` 带 `Cache-Control: max-age=43200`（12 小时），`<link>` 若没有版本号，
改完 CSS 后浏览器/CDN 会继续用旧文件最长 12 小时（表现为「改了没生效」）。
改样式后清一次 CDN 缓存，或给 `<link>` 加 `?v=文件mtime`。

### 目录权限

```bash
chown -R www:www data photos
chmod 750 data photos
```

### 备份包别放根目录

`便签.zip` 这类备份请**移出**网站目录（规则能挡住，但少一层依赖更稳）。

## 数据模型

```sql
users   (id, username UNIQUE, password_hash, created_at)
notes   (id, title, body, visibility, encrypted, salt, created_at, updated_at)
photos  (id, note_id → notes.id ON DELETE CASCADE, orig_name, mime,
         stored_name UNIQUE, size, encrypted)
throttle(k, fails, last)          -- 登录/解锁失败计数（按 IP）
```

`visibility` 取值：`public`（公开）/ `password`（密码保护）/ `private`（仅登录）。

## 功能与规则

- **主页**：`index.php`，公开可见；列表按会话是否解锁显示正文预览。
- **权限**：只有管理员登录后才能新建/编辑/删除便签。
- **三种可见性**
  - 公开：任何人可看。
  - 密码：正文与照片都用查看密码派生的密钥加密落盘；
    管理员也必须输一次密码才能查看（服务端不保存密钥，无法绕过）。
  - 私密：仅登录后可见。
- **照片**：放 `photos/`，数据库中只存元数据。可单张删除（查看页每张右上角 ✕），
  也可随整条便签一起删除 —— 两者是独立操作。
- **上传**：白名单 jpg/png/webp/gif，单张 ≤ 8MB、一次 ≤ 20 张；
  统一经 GD 重编码以剥离 EXIF 与潜在载荷。
- **改密**：编辑页填入新密码即重置，正文与已有照片会同步换成新密钥重新加密；
  留空则继续沿用原密码。密码不可找回，忘记只能用新密码重置。

## 安全要点

- 管理员密码用 `password_hash`（bcrypt）存储。
- 便签/照片用 `sodium_crypto_pwhash`（Argon2id）派生密钥 + `crypto_secretbox` 加密。
- 所有写操作校验 CSRF token。
- 登录与解锁失败按 IP 计数限速（超过 5 次退避 1→64 秒，15 分钟无动作清零）。
- 会话 Cookie：`HttpOnly` + `SameSite=Lax`，HTTPS 下自动加 `Secure`。
- 生产环境关闭错误输出（`display_errors=0`）。

## 备份

拷贝两样东西即可：`data/app.db` 与 `photos/` 目录。
（照片内容在文件里，数据库只存索引。）