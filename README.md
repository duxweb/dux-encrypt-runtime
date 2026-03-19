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

## 运行环境建议

建议在生产环境设置：

```bash
export DUX_ENCRYPT_RUNTIME_SECRET='your-secret'
export DUX_ENCRYPT_CACHE_DIR=/var/lib/dux-encrypt-cache
export DUX_ENCRYPT_EPHEMERAL_CACHE=1
```

建议说明：

- 生产环境使用编译器的 `--release` 构建
- 正式环境始终设置 `DUX_ENCRYPT_RUNTIME_SECRET`
- `DUX_ENCRYPT_CACHE_DIR` 放在 Web 根目录之外
- 除非确实需要持久化 JIT 缓存，否则保持 `DUX_ENCRYPT_EPHEMERAL_CACHE=1`

## 安全说明

- `release` 构建使用包裹后的 `payload_key`，不会直接暴露明文密钥
- 运行清单包含 `manifest HMAC` 完整性校验
- 该运行库的目标是提升逆向成本，不等同于原生二进制级保护

## 发布边界

- 对外公开发布的只有 `duxweb/dux-encrypt-runtime`
- 加密编译器、打包流程和最终加密实现保持内部维护
- 外部开发者只需要安装本运行库，即可运行已经交付的加密代码
