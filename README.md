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
- 校验嵌入式授权信息

运行库当前不负责：

- 编译源码
- 生成授权文件
- 注入授权信息
- 打包发布流程

## 环境变量

以下环境变量是否需要配置，取决于你的加密构建方式。

- `DUX_ENCRYPT_RUNTIME_SECRET`
  - 当构建结果包含 `payload_key_ciphertext` 或 `manifest_hmac` 时必填
  - 缺失时运行库会直接抛出 `Runtime secret missing`
- `DUX_ENCRYPT_CACHE_DIR`
  - `jitphp` 模式下用于写入 JIT 缓存文件
  - 不设置时默认使用 `sys_get_temp_dir() . '/dux-encrypt-cache'`
- `DUX_ENCRYPT_EPHEMERAL_CACHE`
  - 设为任意非空值后，JIT 文件在载入后会立即删除
- `DUX_ENCRYPT_PROJECT_ID`
  - 只有授权信息里存在 `project_id` 绑定时才需要
  - 不匹配时会抛出 `License project binding mismatch`

## 授权校验

当前运行库的授权校验基于加密包内嵌的授权负载，不是靠运行库主动去读某个外部授权文件路径。

运行时会按以下规则校验：

- `expires_at`：过期即拒绝执行
- `project_id`：若授权中存在项目绑定，则与 `DUX_ENCRYPT_PROJECT_ID` 比对
- `domain_whitelist`：若授权中存在域名白名单，则与当前 `HTTP_HOST` 比对
- `public_key + signature`：若环境支持 `sodium_crypto_sign_verify_detached()`，则校验授权签名

如果当前加密包没有授权负载，运行库不会做授权拦截。

## 缓存行为

`jitphp` 模式下，运行库会把解密后的 JIT 代码写入缓存目录，再 `require_once` 载入。

缓存行为如下：

- 默认落盘到 `DUX_ENCRYPT_CACHE_DIR` 或系统临时目录
- 若启用了 OPcache 且当前运行环境允许，会尝试 `opcache_compile_file()`
- 若设置了 `DUX_ENCRYPT_EPHEMERAL_CACHE`，载入后会删除缓存文件

## 使用建议

生产环境建议：

```bash
export DUX_ENCRYPT_RUNTIME_SECRET='your-secret'
export DUX_ENCRYPT_CACHE_DIR=/var/lib/dux-encrypt-cache
export DUX_ENCRYPT_EPHEMERAL_CACHE=1
```

- 使用 `release` 构建时，务必提供 `DUX_ENCRYPT_RUNTIME_SECRET`
- `DUX_ENCRYPT_CACHE_DIR` 放在 Web 根目录之外
- 若没有持久化 JIT 缓存需求，建议开启 `DUX_ENCRYPT_EPHEMERAL_CACHE`
- 若存在项目级授权绑定，再额外设置 `DUX_ENCRYPT_PROJECT_ID`

## 安全说明

- `release` 构建使用包裹后的 `payload_key`，不会直接暴露明文密钥
- 运行清单包含 `manifest HMAC` 完整性校验
- 该运行库的目标是提升逆向成本，不等同于原生二进制级保护

## 发布边界

- 对外公开发布的只有 `duxweb/dux-encrypt-runtime`
- 加密编译器、打包流程和最终加密实现保持内部维护
- 外部开发者只需要安装本运行库，即可运行已经交付的加密代码
