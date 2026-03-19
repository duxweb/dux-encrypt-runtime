# dux-encrypt-runtime

`dux-encrypt-runtime` 是 `dux-encrypt` 的公开运行库，用于在目标项目中运行已经加密过的 PHP 代码包。

这个仓库只负责“运行”，不负责“加密”。

当前公开版本：`0.0.1`

## 适用场景

当业务代码已经通过内部 `dux-encrypt` 编译后，目标项目只需要安装这个运行库，就可以加载和执行加密后的代码包，不依赖额外 PHP 扩展。

适合：

- 应用市场发布后的加密包运行
- 私有项目中的受保护模块运行
- 交付给第三方服务器后的运行环境接入

## 仓库范围

本仓库只包含公开运行时所需的最小内容：

- `bootstrap.php`
- `src/Runtime.php`
- `composer.json`

本仓库不包含：

- 加密编译器实现
- 解析与转换流程
- 打包命令
- 内部测试夹具
- 最终加密产物生成逻辑

也就是说：

- `dux-encrypt-runtime` 是公开的
- `Encrypt` 编译与最终加密实现是内部的，不在本仓库公开

## 安装

```bash
composer require duxweb/dux-encrypt-runtime:0.0.1
```

运行库通过 Composer `files` 自动加载，正常启用 Composer 自动加载即可，不需要额外手动引导。

## 运行方式

加密后的代码包会在文件内调用运行库，运行时按 `.dux-encrypt/manifest.php` 和 `payloads/*.bin` 读取加密元数据与载荷。

运行库当前负责：

- 读取并缓存 manifest
- 校验 manifest 完整性
- 解密 payload key
- 加载 JIT / VM / source 三类执行载荷
- 按 manifest 读取并校验外部授权文件

运行库当前不负责：

- 编译源码
- 生成授权文件
- 注入授权信息
- 打包发布流程

## 运行配置

当前只支持代码配置。

```php
\DuxEncrypt\Runtime::configure([
    'runtime_secret' => 'your-secret',
    'cache_dir' => '/var/lib/dux-encrypt-cache',
    'ephemeral_cache' => true,
    'project_id' => 'your-project-id',
]);
```

支持的配置项：

- `runtime_secret`
  - `release` 构建必需
  - 用于解开 `payload_key_ciphertext` 和校验 `manifest_hmac`
- `runtime_secrets`
  - 可选的多项目密钥映射
  - 可按 `project_name` 或 `build_id` 提供不同 secret
- `runtime_secret_resolver`
  - 可选回调
  - 由业务侧按 manifest 动态返回 secret
- `cache_dir`
  - `jitphp` 模式的缓存目录
  - 不设置时默认使用 `sys_get_temp_dir() . '/dux-encrypt-cache'`
- `ephemeral_cache`
  - 设为 `true` 后，JIT 文件载入后会立即删除
- `project_id`
  - 只有授权信息里存在 `project_id` 绑定时才需要
- `license_base_dir`
  - 当 manifest 中的授权路径不是 `./` 或 `../` 相对路径时，用于解析外部授权目录
- `license_path_resolver`
  - 可选回调
  - 由业务侧完全接管授权文件路径解析

## 授权校验

当前运行库的授权校验优先基于 manifest 中声明的授权配置。

如果 manifest 里已经直接带 `license.payload`，运行库会直接校验。

如果 manifest 只带：

- `license.path`
- `license.public_key`
- `license.required`

运行库会按该路径读取外部 `.license` 文件并校验。

运行时会按以下规则校验：

- `not_before`：未到生效时间即拒绝执行
- `expires_at`：过期即拒绝执行
- `project_id`：若授权中存在项目绑定，则与 `project_id` 配置比对
- `domain_whitelist` / `domains`：若授权中存在域名绑定，则与当前 `HTTP_HOST` 比对
- `constraint.type = domain`：与当前域名比对
- `constraint.type = ip`：与当前服务器 IP 比对
- `public_key + signature`：若环境支持 `sodium_crypto_sign_verify_detached()`，则校验授权签名

如果当前加密包没有授权负载，运行库不会做授权拦截。

## 缓存行为

`jitphp` 模式下，运行库会把解密后的 JIT 代码写入缓存目录，再 `require_once` 载入。

缓存行为如下：

- 默认落盘到 `cache_dir` 或系统临时目录
- 若启用了 OPcache 且当前运行环境允许，会尝试 `opcache_compile_file()`
- 若设置了 `ephemeral_cache`，载入后会删除缓存文件

## 使用建议

生产环境建议：

```php
\DuxEncrypt\Runtime::configure([
    'runtime_secret' => 'your-secret',
    'cache_dir' => '/var/lib/dux-encrypt-cache',
    'ephemeral_cache' => true,
]);
```

- 使用 `release` 构建时，务必提供 `runtime_secret`
- `cache_dir` 放在 Web 根目录之外
- 若没有持久化 JIT 缓存需求，建议开启 `ephemeral_cache`
- 若存在项目级授权绑定，再额外提供 `project_id`

## 安全说明

- `release` 构建使用包裹后的 `payload_key`，不会直接暴露明文密钥
- 运行清单包含 `manifest HMAC` 完整性校验
- 该运行库的目标是提升逆向成本，不等同于原生二进制级保护

## 发布边界

- 对外公开发布的只有 `duxweb/dux-encrypt-runtime`
- 加密编译器、打包流程和最终加密实现保持内部维护
- 外部开发者只需要安装本运行库，即可运行已经交付的加密代码
