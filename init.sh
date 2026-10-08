#!/bin/sh
# PHP 和 Nginx 片段中的 $ 不应由 shell 展开。
# shellcheck disable=SC2016
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
PHP_BIN=${PHP_BIN:-php}

if [ -f .env ]; then
    printf '%s\n' '检测到已有 .env，已停止安装。请保留此文件，并按 docs/installation-zh.md 的常见问题说明检查上次安装的报错。'
    exit 1
fi

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    printf '%s\n' '未找到 PHP。请先安装 PHP 8.3 / 8.4，或通过 PHP_BIN 指定宝塔的 PHP 路径。'
    exit 1
fi

"$PHP_BIN" -r '
if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, "需要 PHP 8.3 或更高版本，当前为 ".PHP_VERSION."。\n");
    exit(1);
}
$missing = array_filter(["bcmath", "ctype", "curl", "dom", "fileinfo", "gd", "intl", "mbstring", "openssl", "pdo_mysql", "redis", "xml", "zip", "pcntl"], fn ($extension) => !extension_loaded($extension));
if ($missing) {
    fwrite(STDERR, "请为当前 PHP 安装扩展：".implode(", ", $missing)."\n");
    exit(1);
}
foreach (["putenv", "proc_open", "symlink", "pcntl_signal", "pcntl_alarm"] as $function) {
    if (!function_exists($function)) {
        fwrite(STDERR, "请在 PHP 的禁用函数列表中移除：".$function."\n");
        exit(1);
    }
}
'

if [ ! -f public/default/manifest.json ]; then
    printf '%s\n' '程序文件不完整。请按 docs/installation-zh.md 重新下载本仓库的完整程序包。'
    exit 1
fi

WEB_USER=$(id -un)
if [ "$(id -u)" -eq 0 ]; then
    if id www >/dev/null 2>&1; then
        WEB_USER=www
    elif id www-data >/dev/null 2>&1; then
        WEB_USER=www-data
    fi
    printf '网站运行用户 [%s]：' "$WEB_USER"
    IFS= read -r input
    WEB_USER=${input:-$WEB_USER}
    id "$WEB_USER" >/dev/null 2>&1 || { printf '%s\n' '该系统用户不存在。'; exit 1; }
fi

printf '%s\n' '正在准备项目专用 Composer 2……'
command -v curl >/dev/null 2>&1 || { printf '%s\n' '请先安装 curl。'; exit 1; }
installer=$(mktemp)
trap 'rm -f "$installer"' EXIT HUP INT TERM
curl -fsSL https://getcomposer.org/installer -o "$installer"
checksum=$(curl -fsSL https://composer.github.io/installer.sig)
"$PHP_BIN" -r 'if (!hash_equals($argv[1], hash_file("sha384", $argv[2]))) { fwrite(STDERR, "Composer 安装器校验失败。\n"); exit(1); }' "$checksum" "$installer"
"$PHP_BIN" "$installer" --2 --install-dir=. --filename=composer.phar
rm -f "$installer"
trap - EXIT HUP INT TERM

printf '%s\n' '正在安装 PHP 依赖……'
export COMPOSER_ALLOW_SUPERUSER=1
"$PHP_BIN" ./composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$PHP_BIN" ./composer.phar check-platform-reqs --no-dev

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/fonts bootstrap/cache
"$PHP_BIN" artisan app:install
"$PHP_BIN" artisan storage:link
"$PHP_BIN" artisan icons:cache
"$PHP_BIN" artisan view:cache

chmod -R u+rwX,g+rwX storage bootstrap/cache
chmod 640 .env
if [ "$(id -u)" -eq 0 ]; then
    chown -R "$WEB_USER:$(id -gn "$WEB_USER")" storage bootstrap/cache extensions
    chown "$WEB_USER:$(id -gn "$WEB_USER")" .env
fi

printf '\n%s\n' '安装完成。请在宝塔/aaPanel 中完成以下配置（详见 docs/installation-zh.md）：'
printf '1. 网站运行目录：%s/public；PHP 版本与本次安装保持一致。\n' "$(pwd)"
printf '%s\n' '2. 伪静态：location / { try_files $uri $uri/ /index.php$is_args$query_string; }'
printf '3. 每分钟计划任务（用户 %s）：%s %s/artisan schedule:run\n' "$WEB_USER" "$PHP_BIN" "$(pwd)"
printf '4. Supervisor 守护进程（用户 %s）：%s %s/artisan queue:work\n' "$WEB_USER" "$PHP_BIN" "$(pwd)"
