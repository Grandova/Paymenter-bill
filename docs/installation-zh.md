# 宝塔 / aaPanel 安装（MySQL）

此入口用于安装本仓库的中文版，使用 `sh init.sh` 完成依赖安装、数据库初始化和管理员创建。不会下载官方安装包覆盖当前汉化代码。

## 1. 面板中准备环境

- Nginx、PHP 8.3 或 8.4、MySQL 8.0 或 8.4、Redis。
- PHP 扩展：bcmath、ctype、curl、dom、fileinfo、gd、intl、mbstring、openssl、pdo_mysql、redis、xml、zip、pcntl。
- PHP 禁用函数列表中移除：putenv、proc_open、symlink、pcntl_signal、pcntl_alarm。网站 PHP 和命令行 PHP 都需要检查。
- 创建网站和一个空的 MySQL 数据库，记录数据库名、用户名、密码。
- 上传本项目到网站目录。源码包没有 `public/default/manifest.json` 时，需要 Node.js 22.12+ 和 npm；已带构建资源的安装包不需要 Node.js。

## 2. 运行安装

```sh
cd /www/wwwroot/你的域名
sh init.sh
```

如果终端的 `php -v` 与网站使用的 PHP 不一致，请指定 PHP 路径：

```sh
PHP_BIN=/www/server/php/83/bin/php sh init.sh
```

按提示填写网站运行用户（宝塔通常为 `www`）、站点名称、网址、MySQL、Redis 和管理员信息。密码输入不回显。Composer 2 不存在时会自动下载并校验安装器；依赖按锁文件安装。

安装器固定使用 `DB_CONNECTION=mysql`、`utf8mb4` 和 `utf8mb4_0900_ai_ci`，支持中文和 Emoji。默认语言为简体中文。Redis 用于缓存、会话和队列，每个站点使用独立的键前缀。

## 3. 面板中配置网站

1. 网站运行目录设为 `/public`，PHP 版本与安装时一致。
2. 添加 Nginx 伪静态：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$query_string;
}
```

3. 添加每分钟执行的 Shell 计划任务，运行用户为 `www`，路径按实际情况修改：

```sh
/www/server/php/83/bin/php /www/wwwroot/你的域名/artisan schedule:run
```

4. 在 Supervisor 中添加一个守护进程：运行用户 `www`，运行目录为网站目录，启动命令为：

```sh
/www/server/php/83/bin/php /www/wwwroot/你的域名/artisan queue:work
```

访问网站登录，后台地址为 `/admin`。生产站点请在面板中配置 HTTPS，并让安装时填写的网址与实际访问地址一致。

## MySQL 注意事项

- 不要将 MySQL 配置成 `DB_CONNECTION=mariadb`；MariaDB 的 `utf8mb4_uca1400_ai_ci` 不能用于 MySQL。
- MySQL 8.4 可使用默认的 `caching_sha2_password` 认证，PHP 8.3/8.4 的 `pdo_mysql` 支持它，无需开启旧的 `mysql_native_password`。
- 数据库用户需要对该库拥有建表、修改表、索引、外键及读写权限；不需要全局管理权限。
- 不关闭严格模式，不修改现有财务字段或业务数据。安装器不支持 MySQL 5.7，也不会自动升级数据库服务。

已在 PHP 8.3、MySQL 8.0.46 和 8.4.6 的独立测试环境中完成数据库迁移、初始化及全部 70 项测试（各 1325 个断言）。两个版本均通过 `sh init.sh` 完整安装演练，包括管理员创建、Redis 读写、中文默认语言及配置特殊字符检查；8.4 演练还包含 Node.js 22.12 源码构建。已有 `.env` 和非空数据库的安装保护也已验证。

## 安装中断与已有站点

已有 `.env` 或非空数据库时，安装器会停止，不会清库或覆盖配置。依赖安装、前端构建、数据库及 Redis 连接检查失败且尚未生成 `.env` 时，修复问题后可以重新执行 `sh init.sh`。

如果已经生成 `.env`，保留原文件和 `APP_KEY`，根据报错修复原因后继续未完成的步骤：

```sh
php artisan migrate --force --seed
php artisan db:seed --class=CustomPropertySeeder --force
php artisan app:init
php artisan app:user:create
php artisan storage:link
php artisan icons:cache
php artisan view:cache
```

已经创建管理员则跳过 `app:user:create`，不要重复创建。已有站点更新不应执行本安装器。若中断发生在权限设置前，确认 `.env` 可由网站用户读取，`storage`、`bootstrap/cache` 和 `extensions` 可由网站用户写入；不要设置为 `777`。

本中文版不应直接运行原版 `update.sh` 或后台官方升级功能，否则官方文件会覆盖汉化和安装器修改。
