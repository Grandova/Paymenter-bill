# 宝塔 / aaPanel 全新安装教程

本文从一台已安装宝塔或 aaPanel 的服务器开始，带你完成 Paymenter 中文版的全新安装。

以下统一使用域名 `bill.example.com`、网站目录 `/www/wwwroot/bill.example.com`、PHP 8.3。操作时将域名和目录替换成你自己的，先将域名解析到服务器 IP，并在服务器防火墙及云平台安全组中放行 80、443 端口。

程序已包含打包好的前端文件，**服务器不需要安装 Node.js，也不需要手动编译前端**。Composer 由安装脚本自动准备。

## 1. 安装运行环境

打开 **软件商店（App Store）**，安装以下软件：

| 软件 | 版本 / 用途 |
| --- | --- |
| Nginx | 网站服务 |
| MySQL | 8.0 或 8.4，二选一 |
| PHP | 8.3 |
| Redis | 缓存和队列服务 |
| Supervisor | 守护队列进程 |

安装完成后，确认 Nginx、MySQL、PHP 和 Redis 均处于运行状态。

## 2. 配置 PHP

### 安装扩展

进入 **软件商店 → PHP 8.3 → 设置（Settings）→ 安装扩展（Install extensions）**。

检查并安装 `redis`、`fileinfo`、`intl`、`mbstring`、`bcmath`、`zip`、`gd`，已安装的不用重复安装。

### 解除禁用函数

进入同一个 PHP 设置页面的 **禁用函数（Disabled functions）**，将以下函数从禁用列表中删除；列表中没有的不用处理：

```text
putenv
proc_open
symlink
pcntl_signal
pcntl_alarm
```

保存后重启 PHP 8.3。安装脚本还会检查其余必要扩展，若提示缺失，按提示补装到 PHP 8.3。

## 3. 创建网站和数据库

进入 **网站（Website）→ 添加站点（Add site）**，按下表填写：

| 项目 | 填写内容 |
| --- | --- |
| 域名 | `bill.example.com` |
| 根目录 | `/www/wwwroot/bill.example.com` |
| 数据库 | 选择 MySQL，编码选择 `utf8mb4` |
| 数据库名、用户名和密码 | 使用面板生成的值，或自行填写；保存下来，安装时要用 |
| PHP 版本 | 选择 PHP 8.3 |

提交后，在 **数据库（Databases）** 中确认数据库已创建。安装使用这个新建的空库，不要选择其他网站正在使用的数据库。

## 4. 下载程序

打开面板的 **终端（Terminal）**，或通过 SSH 登录服务器，依次执行：

```sh
cd /www/wwwroot/bill.example.com
curl -fL https://github.com/Grandova/Paymenter-bill/archive/refs/heads/main.tar.gz -o paymenter.tar.gz
tar -xzf paymenter.tar.gz --strip-components=1
rm -f paymenter.tar.gz
```

上面的命令将程序直接解压到网站目录。进入面板的文件管理，应能在 `/www/wwwroot/bill.example.com` 下看到 `init.sh`、`artisan`、`app`、`public` 等文件和目录。

**如果下载或解压报错，先解决报错，再继续安装。** 不要在已有 Paymenter 站点的目录中执行这组全新安装命令。

## 5. 执行安装

仍在刚才的网站目录中，执行：

```sh
PHP_BIN=/www/server/php/83/bin/php sh init.sh
```

这条命令指定使用宝塔的 PHP 8.3，避免终端调用到其他 PHP 版本。脚本会自动安装依赖，然后提示你填写：

| 安装提示 | 填写内容 |
| --- | --- |
| 网站运行用户 | 宝塔 / aaPanel 通常为 `www` |
| 站点名称 | 你希望显示的网站名称 |
| 站点网址 | `https://bill.example.com` |
| MySQL 地址、端口 | 本机数据库通常为 `127.0.0.1`、`3306` |
| MySQL 数据库名、用户名、密码 | 第 3 步保存的数据库信息 |
| Redis 地址、端口 | 本机 Redis 通常为 `127.0.0.1`、`6379` |
| Redis 密码 | 设置过就填写，没有设置则直接回车 |
| 管理员邮箱、名字、姓氏、密码 | 用于登录网站的管理员信息；姓氏可留空 |

密码输入时不会显示字符，输入后按回车即可。

等待终端出现 **“安装完成”**，再继续下面的设置。数据库初始化、默认中文设置和管理员创建均由脚本完成，无需手动编辑 `.env`。

> 如果你使用 PHP 8.4，网站 PHP 版本也应选择 8.4，并将本文所有命令中的 `/php/83/` 替换为 `/php/84/`。

## 6. 配置运行目录、伪静态和 HTTPS

进入 **网站 → bill.example.com → 设置**。

### 网站目录（Site directory）

- 根目录保持 `/www/wwwroot/bill.example.com`。
- **运行目录（Running directory）** 选择 `/public`，保存。
- 取消勾选 **防跨站攻击（open_basedir）** 并保存，避免 PHP 无法读取 `public` 上一级的程序文件。

### 伪静态（URL rewrite）

填入以下内容并保存：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$query_string;
}
```

### SSL

进入 **SSL → Let's Encrypt**，为域名申请并部署证书，然后开启强制 HTTPS。确保这里的域名与第 5 步填写的站点网址一致。

## 7. 添加计划任务

进入 **计划任务（Cron）→ 添加任务**：

| 项目 | 填写内容 |
| --- | --- |
| 任务类型 | Shell 脚本（Shell Script） |
| 任务名称 | Paymenter 定时任务 |
| 执行周期 | 每 1 分钟 |
| 执行用户 | `www` |

脚本内容填写：

```sh
/www/server/php/83/bin/php /www/wwwroot/bill.example.com/artisan schedule:run
```

保存后手动执行一次，确认任务日志没有报错。这个任务负责触发账单等定时操作，不能省略。

## 8. 启动队列服务

进入 **软件商店 → Supervisor → 设置 → 添加守护进程（Add Daemon）**：

| 项目 | 填写内容 |
| --- | --- |
| 名称（Name） | `paymenter-worker` |
| 运行用户（Run User） | `www` |
| 运行目录（Run Dir） | `/www/wwwroot/bill.example.com` |
| 进程数量（Processes） | `1` |

启动命令（Start Command）填写：

```sh
/www/server/php/83/bin/php /www/wwwroot/bill.example.com/artisan queue:work
```

保存并启动，确认进程状态为 **运行中（RUNNING）**。队列用于处理邮件、通知等后台任务，需要持续运行。

## 9. 登录网站

打开 `https://bill.example.com`，使用安装时设置的管理员邮箱和密码登录，再访问 `https://bill.example.com/admin` 进入管理后台。

前台和后台默认显示简体中文，安装完成。

## 常见问题

**安装时提示数据库连接失败**

到面板的“数据库”页面核对数据库名、用户名、密码，确认 MySQL 已启动。这里需要填写网站数据库账号，不是宝塔登录账号。

**安装时提示 Redis 连接失败**

确认软件商店中的 Redis 服务已启动、PHP 8.3 已安装 `redis` 扩展；如果 Redis 设置了密码，安装时必须填写相同的密码。

**访问网站出现 404 或 500**

先核对第 6 步的运行目录、伪静态及 PHP 版本。500 错误的具体原因可以在网站目录的 `storage/logs` 中查看；同时检查 PHP 扩展和 Redis 服务。

**安装中途报错，可以再运行安装命令吗？**

尚未生成 `.env` 时，修复终端提示的问题后，可以重新运行第 5 步的命令。如果提示已有 `.env`，说明配置已经写入，需根据上次报错继续处理；不要删除 `.env`、更换 `APP_KEY` 或清空数据库来强行重装。依赖下载失败时也不要继续配置网站，先确认安装最终显示“安装完成”。

**后续更新**

本教程只用于全新安装。不要使用原版 `update.sh` 或后台的官方升级功能更新此中文版，它们会下载官方文件并覆盖本仓库的修改。
