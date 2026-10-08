# 使用宝塔 / aaPanel 安装 Paymenter

本教程用于全新安装。请先安装宝塔或 aaPanel，将域名解析到服务器 IP，并放行 80、443 端口。

下面以 `bill.example.com` 为例，请替换成自己的域名。程序已包含前端文件，服务器无需安装 Node.js。

## 1.配置运行环境

登录面板，在 **软件商店 / App Store** 中安装：

- [x] Nginx
- [x] MySQL 8.0 或 8.4
- [x] PHP 8.3
- [x] Redis

安装完成后，确认以上服务均已启动。

> 本文命令统一使用 PHP 8.3。如果使用 PHP 8.4，请将网站 PHP 版本选为 8.4，并将命令中的 `/php/83/` 替换为 `/php/84/`。

## 2.安装 PHP 扩展

面板 > 软件商店（App Store）> PHP 8.3 > 设置（Settings）> 安装扩展（Install extensions）。

安装 `redis`、`fileinfo`、`intl`、`mbstring`、`bcmath`、`zip`、`gd`，已安装的不用重复安装。

## 3.解除被禁用的函数

面板 > 软件商店（App Store）> PHP 8.3 > 设置（Settings）> 禁用函数（Disabled functions）。

将 `putenv`、`proc_open`、`symlink`、`pcntl_signal`、`pcntl_alarm` 从禁用列表中删除。列表中没有的不用处理，保存后重启 PHP 8.3。

## 4.添加站点

面板 > 网站（Website）> 添加站点（Add site）。

> 在域名（Domain）填写 `bill.example.com`。
>
> 在根目录填写 `/www/wwwroot/bill.example.com`。
>
> 在数据库（Database）选择 MySQL，编码选择 `utf8mb4`。
>
> 在 PHP 版本（PHP Version）选择 PHP 8.3。

保存数据库名、用户名和密码，下一步安装时需要填写。数据库必须是新建的空库。

## 5.安装 Paymenter

打开面板终端（Terminal），或通过 SSH 登录服务器，进入刚才创建的网站目录：

```sh
cd /www/wwwroot/bill.example.com
```

以下命令均在这个目录中执行。

下载并解压程序：

```sh
curl -fL https://github.com/Grandova/Paymenter-bill/archive/refs/heads/main.tar.gz -o paymenter.tar.gz
tar -xzf paymenter.tar.gz --strip-components=1
rm -f paymenter.tar.gz
```

确认当前目录中能看到 `init.sh` 和 `artisan`，再执行安装命令：

```sh
PHP_BIN=/www/server/php/83/bin/php sh init.sh
```

脚本会自动准备 Composer、安装依赖并初始化网站，根据中文提示填写：

> 网站运行用户填写 `www`。
>
> 站点名称按需填写，站点网址填写 `https://bill.example.com`。
>
> 本机 MySQL 地址填写 `127.0.0.1`，端口填写 `3306`；数据库名、用户名和密码填写上一步保存的信息。
>
> 本机 Redis 地址填写 `127.0.0.1`，端口填写 `6379`；有密码就填写，没有则直接回车。
>
> 设置管理员邮箱、名字和密码，姓氏可留空。

密码输入时不会显示字符，输入后按回车即可。出现 **“安装完成”** 后继续下一步；若出现报错，先处理报错，不要跳过。

## 6.配置站点目录及伪静态

面板 > 网站（Website）> 找到刚才添加的站点 > 设置。

在 **网站目录（Site directory）** 中，将 **运行目录（Running directory）** 选择为 `/public` 并保存。根目录仍为 `/www/wwwroot/bill.example.com`。

取消勾选 **防跨站攻击（open_basedir）** 并保存，避免 PHP 无法读取上一级的程序文件。

在 **伪静态（URL rewrite）** 中填写以下内容并保存：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$query_string;
}
```

在 **SSL > Let's Encrypt** 中为域名申请并部署证书，开启强制 HTTPS。域名应与安装时填写的站点网址一致。

## 7.配置定时任务

面板 > 计划任务（Cron）> 添加任务。

> 在任务类型（Type of Task）选择 Shell 脚本（Shell Script）。
>
> 在任务名称（Name of Task）填写 `Paymenter`。
>
> 在执行周期（Period）选择每 1 分钟执行。
>
> 在执行用户选择 `www`。

脚本内容（Script content）填写：

```sh
/www/server/php/83/bin/php /www/wwwroot/bill.example.com/artisan schedule:run
```

保存后手动执行一次，确认日志没有报错。定时任务用于触发账单等操作，需要保持启用。

## 8.启动队列服务

队列用于处理邮件、通知等后台任务，需要持续运行。

面板 > 软件商店（App Store）> 安装 Supervisor > 设置 > 添加守护进程（Add Daemon）。

> 在名称（Name）填写 `paymenter-worker`。
>
> 在运行用户（Run User）选择 `www`。
>
> 在运行目录（Run Dir）填写 `/www/wwwroot/bill.example.com`。
>
> 在进程数量（Processes）填写 `1`。

启动命令（Start Command）填写：

```sh
/www/server/php/83/bin/php /www/wwwroot/bill.example.com/artisan queue:work
```

保存并启动，确认进程状态为 **运行中（RUNNING）**。

安装至此完成。打开 `https://bill.example.com`，使用刚才设置的管理员邮箱和密码登录，再访问 `https://bill.example.com/admin` 进入后台。前台和后台默认显示简体中文。

### 常见问题

**安装时提示数据库连接失败**

在面板的数据库页面核对数据库名、用户名、密码，确认 MySQL 已启动。这里填写的是网站数据库账号，不是面板登录账号。

**安装时提示 Redis 连接失败**

检查 Redis 服务是否已启动、PHP 8.3 是否已安装 `redis` 扩展，以及填写的 Redis 密码是否正确。

**访问网站出现 404 或 500**

检查运行目录是否为 `/public`、伪静态是否保存、网站 PHP 版本是否正确。500 错误的具体原因可在网站目录的 `storage/logs` 中查看，同时检查 PHP 扩展和 Redis 服务。

**安装中断后如何处理**

尚未生成 `.env` 时，修复终端报错后可重新执行安装命令。若提示已有 `.env`，请保留该文件，根据上次报错继续处理，不要删除配置、更换 `APP_KEY` 或清空数据库来强行重装。

**如何更新中文版**

本教程仅用于全新安装。不要执行原版 `update.sh` 或使用后台的官方升级功能，它们会下载官方文件并覆盖中文版修改。
