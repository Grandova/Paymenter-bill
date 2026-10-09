<?php

namespace App\Console\Commands;

use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use PDOException;
use RedisException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class Install extends Command
{
    protected $signature = 'app:install';

    protected $description = '交互式安装 Paymenter（MySQL 8.0 / 8.4）';

    public function handle(): int
    {
        if (file_exists($this->laravel->environmentFilePath()) || $this->laravel->configurationIsCached()) {
            $this->error('已有 .env 或配置缓存，已停止安装，避免覆盖现有站点。');

            return self::FAILURE;
        }

        $this->info('欢迎安装 Paymenter 中文版。请先在面板中创建一个空的 MySQL 数据库。');
        $name = text('站点名称', default: 'Paymenter', required: true);
        $url = rtrim(text('站点网址（含 https:// 或 http://）', required: true, validate: 'url:http,https'), '/');
        $host = text('MySQL 地址', default: '127.0.0.1', required: true, validate: fn ($value) => preg_match('/[;\s]/', $value) ? '请输入主机名或 IP 地址。' : null);
        $port = text('MySQL 端口', default: '3306', required: true, validate: 'integer|min:1|max:65535');
        $database = text('MySQL 数据库名', default: 'paymenter', required: true, validate: 'regex:/^[a-zA-Z0-9_-]+$/');
        $username = text('MySQL 用户名', default: 'paymenter', required: true);
        $dbPassword = password('MySQL 密码');

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => $host,
            'database.connections.mysql.port' => $port,
            'database.connections.mysql.database' => $database,
            'database.connections.mysql.username' => $username,
            'database.connections.mysql.password' => $dbPassword,
            'database.connections.mysql.unix_socket' => '',
            'database.connections.mysql.collation' => 'utf8mb4_0900_ai_ci',
        ]);
        DB::purge('mysql');

        try {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            if (str_contains(strtolower($version), 'mariadb') || version_compare($version, '8.0.0', '<')) {
                $this->error('此安装入口需要 MySQL 8.0 / 8.4，检测到：' . $version);

                return self::FAILURE;
            }
            if (Schema::getTables($database) || Schema::getViews($database)) {
                $this->error('数据库不是空库，已停止安装，不会删除或覆盖已有数据。');

                return self::FAILURE;
            }
        } catch (PDOException $e) {
            $this->error('无法连接 MySQL，请检查地址、端口、数据库名和账号权限。错误代码：' . $e->getCode());

            return self::FAILURE;
        }
        $this->info('MySQL ' . $version . ' 连接正常。');

        $redisHost = text('Redis 地址', default: '127.0.0.1', required: true);
        $redisPort = text('Redis 端口', default: '6379', required: true, validate: 'integer|min:1|max:65535');
        $redisPassword = password('Redis 密码（没有则直接回车）');
        $prefix = 'paymenter_' . substr(hash('sha256', $url . $database), 0, 12) . '_';
        config(['database.redis.client' => 'phpredis', 'database.redis.options.prefix' => $prefix]);
        foreach (['default', 'cache'] as $connection) {
            config(["database.redis.$connection" => [
                'host' => $redisHost,
                'port' => $redisPort,
                'password' => $redisPassword ?: null,
                'database' => $connection === 'cache' ? 1 : 0,
                'timeout' => 5,
            ]]);
        }
        $this->laravel->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        try {
            Redis::connection()->ping();
            Redis::connection('cache')->ping();
        } catch (RedisException $e) {
            $this->error('无法连接 Redis，请检查地址、端口、密码和 Redis 服务状态。');

            return self::FAILURE;
        }

        $email = text('管理员邮箱', required: true, validate: 'email');
        $firstName = text('管理员名字', default: '管理员', required: true);
        $lastName = text('管理员姓氏（可留空）');
        $adminPassword = password('管理员密码（至少 8 位）', required: true, validate: 'min:8');
        password('再次输入管理员密码', required: true, validate: fn ($value) => $value === $adminPassword ? null : '两次密码不一致。');

        $key = 'base64:' . base64_encode(Encrypter::generateKey(config('app.cipher')));
        $values = [
            'APP_NAME' => $name,
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => 'false',
            'APP_URL' => $url,
            'APP_LOCALE' => 'zh',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $dbPassword,
            'DB_COLLATION' => 'utf8mb4_0900_ai_ci',
            'CACHE_STORE' => 'redis',
            'CACHE_PREFIX' => $prefix . 'cache_',
            'QUEUE_CONNECTION' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => $redisHost,
            'REDIS_PORT' => $redisPort,
            'REDIS_PASSWORD' => $redisPassword,
            'REDIS_PREFIX' => $prefix,
        ];
        $env = file_get_contents(base_path('.env.example'));
        foreach ($values as $name => $value) {
            // Dotenv 会展开双引号中的 $，数据库密码必须按字面值保存。
            $line = $name . '="' . str_replace(['\\', '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '\\r', '\\n'], $value) . '"';
            $pattern = '/^' . $name . '=.*$/m';
            $env = preg_match($pattern, $env) ? preg_replace_callback($pattern, fn () => $line, $env) : $env . "\n" . $line . "\n";
        }
        $oldUmask = umask(0077);
        file_put_contents($this->laravel->environmentFilePath(), $env);
        umask($oldUmask);

        config([
            'app.key' => $key,
            'app.url' => $url,
            'app.locale' => 'zh',
            'cache.default' => 'redis',
            'cache.prefix' => $prefix . 'cache_',
            'queue.default' => 'redis',
        ]);
        $this->laravel->forgetInstance('encrypter');
        $this->laravel->make('cache')->forgetDriver('redis');
        app()->setLocale('zh');

        if ($this->call('migrate', ['--force' => true, '--seed' => true]) !== self::SUCCESS) {
            $this->error('安装中断，已保留 .env 和数据库。请按 docs/installation-zh.md 恢复，不要重新生成 APP_KEY。');

            return self::FAILURE;
        }

        $this->callSilent('app:init', ['name' => $values['APP_NAME'], 'url' => $url]);
        if ($this->call('app:user:create', [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $adminPassword,
            'role' => Role::where('name', 'admin')->value('id'),
        ]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->info('初始化完成，管理后台：' . $url . '/admin');

        return self::SUCCESS;
    }
}
