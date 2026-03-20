# dux-encrypt-runtime

`dux-encrypt-runtime` 是 `dux-encrypt` 生成产物的 PHP 运行库。

用于在目标项目中加载并执行已经加密的 PHP 代码包，不依赖额外扩展。

## 安装

```bash
composer require duxweb/dux-encrypt-runtime
```

安装后会通过 Composer 自动加载，无需额外引导。

## 用途

适用于：

- 已加密的 PHP 模块运行
- 应用市场分发后的加密包运行
- 私有项目中的受保护代码运行

## 使用方式

正常情况下，只需要：

1. 安装本运行库
2. 部署加密后的代码文件
3. 如果产物包含授权文件，按产物要求放到对应位置

运行时会自动读取加密包内的 manifest 和 payload 文件。

## 说明

- 本仓库只提供运行库
- 不包含加密编译器
- 不包含加密打包功能

如果你已经拿到 `dux-encrypt` 生成的产物，安装本包即可运行。
