<?php

declare(strict_types=1);

namespace DuxEncrypt;

final class Runtime
{
    private static array $manifestCache = [];
    private static array $programCache = [];
    private static array $payloadKeyCache = [];
    private static array $licenseCache = [];
    private static array $staticSlots = [];
    private static array $jitLoaded = [];
    private static array $jitFunctionCache = [];
    private static array $jitLicenseFreeCache = [];
    private static array $functionInvokerCache = [];
    private static array $staticInvokerCache = [];
    private static array $methodInvokerCache = [];

    public static function execute(string $manifestPath, string $payloadId, string $currentFile)
    {
        $manifest = self::manifest($manifestPath);
        self::verifyLicense($manifestPath, $manifest);
        $program = self::loadProgram($manifest, $manifestPath, $payloadId);
        self::hydrateVmProgram($program, $payloadId);
        if (($program['engine'] ?: 'source') == 'vm') {
            if (($program['vm_ir']['generator'] ?? false) === true) {
                $scope = [];
                return self::evaluateVmGenerator($program['vm_ir'] ?: [], $scope);
            }
            $scope = [];
            return self::finalizeVmResult(self::evaluateVm($program['vm_ir'] ?: [], $scope));
        }
        if (($program['engine'] ?: 'source') == 'jitphp') {
            return self::executeJitProgram($program, $manifestPath, $payloadId, []);
        }
        $source = self::interpretSource($program, $payloadId);
        return eval('?>' . $source);
    }

    public static function functionInvoker(string $manifestPath, string $payloadId)
    {
        $cacheKey = $manifestPath . '::' . $payloadId;
        if (isset(self::$functionInvokerCache[$cacheKey])) {
            return self::$functionInvokerCache[$cacheKey];
        }

        $manifest = self::manifest($manifestPath);
        self::verifyLicense($manifestPath, $manifest);
        $program = self::loadProgram($manifest, $manifestPath, $payloadId);
        self::hydrateVmProgram($program, $payloadId);

        $inner = self::buildFunctionInvoker($program, $manifestPath, $payloadId);
        if (($manifest['__has_license'] ?? false) === true) {
            $invoker = function (array $scope = []) use ($manifestPath, $manifest, $inner) {
                self::verifyLicense($manifestPath, $manifest);
                return $inner($scope);
            };
        } else {
            $invoker = $inner;
        }
        self::$functionInvokerCache[$cacheKey] = $invoker;
        return $invoker;
    }

    public static function invokeFunctionSegment(string $manifestPath, string $payloadId, array $scope = [])
    {
        $invoker = self::functionInvoker($manifestPath, $payloadId);
        return $invoker($scope);
    }
    private static function buildFunctionInvoker(array $program, string $manifestPath, string $payloadId)
    {
        if (($program['engine'] ?: 'source') == 'vm') {
            $vm = $program['vm_ir'] ?: [];
            if (($vm['generator'] ?? false) === true) {
                return function (array $scope = []) use ($vm) {
                    return self::evaluateVmGenerator($vm, $scope);
                };
            }
            return function (array $scope = []) use ($vm) {
                return self::finalizeVmResult(self::evaluateVm($vm, $scope));
            };
        }
        if (($program['engine'] ?: 'source') == 'jitphp') {
            return self::ensureJitFunction($program, $manifestPath, $payloadId);
        }

        $source = self::interpretSource($program, $payloadId);
        return function (array $scope = []) use ($source) {
            extract($scope, EXTR_SKIP);
            return eval($source);
        };
    }

    public static function staticInvoker(string $manifestPath, string $payloadId, string $scopeClass)
    {
        $cacheKey = $manifestPath . '::' . $payloadId . '::static::' . $scopeClass;
        if (isset(self::$staticInvokerCache[$cacheKey])) {
            return self::$staticInvokerCache[$cacheKey];
        }

        $manifest = self::manifest($manifestPath);
        self::verifyLicense($manifestPath, $manifest);
        $program = self::loadProgram($manifest, $manifestPath, $payloadId);
        self::hydrateVmProgram($program, $payloadId);

        if (($program['engine'] ?: 'source') == 'vm') {
            $vm = $program['vm_ir'] ?: [];
            $inner = function (array $scope = []) use ($vm) {
                if (($vm['generator'] ?? false) === true) {
                    return self::evaluateVmGenerator($vm, $scope);
                }
                return self::finalizeVmResult(self::evaluateVm($vm, $scope));
            };
        } elseif (($program['engine'] ?: 'source') == 'jitphp') {
            $function = self::ensureJitFunction($program, $manifestPath, $payloadId);
            $inner = function (array $scope = []) use ($function, $scopeClass) {
                return $function($scope, $scopeClass);
            };
        } else {
            $source = self::interpretSource($program, $payloadId);
            $inner = function (array $scope = []) use ($source, $scopeClass) {
                $runner = function (string $__source, array $__scope) {
                    extract($__scope, EXTR_SKIP);
                    return eval($__source);
                };
                $runner = \Closure::bind($runner, null, $scopeClass);
                return $runner($source, $scope);
            };
        }

        if (($manifest['__has_license'] ?? false) === true) {
            $invoker = function (array $scope = []) use ($manifestPath, $manifest, $inner) {
                self::verifyLicense($manifestPath, $manifest);
                return $inner($scope);
            };
        } else {
            $invoker = $inner;
        }
        self::$staticInvokerCache[$cacheKey] = $invoker;
        return $invoker;
    }

    public static function methodInvoker(string $manifestPath, string $payloadId, string $scopeClass)
    {
        $cacheKey = $manifestPath . '::' . $payloadId . '::method::' . $scopeClass;
        if (isset(self::$methodInvokerCache[$cacheKey])) {
            return self::$methodInvokerCache[$cacheKey];
        }

        $manifest = self::manifest($manifestPath);
        self::verifyLicense($manifestPath, $manifest);
        $program = self::loadProgram($manifest, $manifestPath, $payloadId);
        self::hydrateVmProgram($program, $payloadId);

        if (($program['engine'] ?: 'source') == 'vm') {
            $vm = $program['vm_ir'] ?: [];
            $inner = function (array $scope = []) use ($vm) {
                if (($vm['generator'] ?? false) === true) {
                    return self::evaluateVmGenerator($vm, $scope);
                }
                return self::finalizeVmResult(self::evaluateVm($vm, $scope));
            };
        } elseif (($program['engine'] ?: 'source') == 'jitphp') {
            $function = self::ensureJitFunction($program, $manifestPath, $payloadId);
            $inner = function (array $scope = []) use ($function, $scopeClass) {
                $boundObject = $scope['__dux_bound_object'] ?? null;
                return $function($scope, $scopeClass, is_object($boundObject) ? $boundObject : null);
            };
        } else {
            $source = self::interpretSource($program, $payloadId);
            $inner = function (array $scope = []) use ($source, $scopeClass) {
                $boundObject = $scope['__dux_bound_object'] ?? null;
                $runner = function (string $__source, array $__scope) {
                    extract($__scope, EXTR_SKIP);
                    return eval($__source);
                };
                $runner = $runner->bindTo(is_object($boundObject) ? $boundObject : null, $scopeClass);
                return $runner($source, $scope);
            };
        }

        if (($manifest['__has_license'] ?? false) === true) {
            $invoker = function (array $scope = []) use ($manifestPath, $manifest, $inner) {
                self::verifyLicense($manifestPath, $manifest);
                return $inner($scope);
            };
        } else {
            $invoker = $inner;
        }
        self::$methodInvokerCache[$cacheKey] = $invoker;
        return $invoker;
    }

    public static function invokeStaticSegment(string $manifestPath, string $payloadId, array $scope = [], string $scopeClass = '', string $lateStaticClass = '')
    {
        $scope['__dux_scope_class'] = $scopeClass;
        $scope['__dux_lsb_class'] = $lateStaticClass ?: $scopeClass;
        $scope['__dux_parent_class'] = $scopeClass && get_parent_class($scopeClass) ? get_parent_class($scopeClass) : '';

        $functionCacheKey = $manifestPath . '::' . $payloadId;
        if (isset(self::$jitFunctionCache[$functionCacheKey])) {
            if (!(self::$jitLicenseFreeCache[$functionCacheKey] ?? false)) {
                self::verifyLicense($manifestPath, self::manifest($manifestPath));
            }
            return self::invokeLoadedJitFunction(self::$jitFunctionCache[$functionCacheKey], $scope, $scopeClass);
        }

        $manifest = self::manifest($manifestPath);
        self::verifyLicense($manifestPath, $manifest);
        $program = self::loadProgram($manifest, $manifestPath, $payloadId);
        self::hydrateVmProgram($program, $payloadId);

        if (($program['engine'] ?: 'source') == 'vm') {
            if (($program['vm_ir']['generator'] ?? false) === true) {
                return self::evaluateVmGenerator($program['vm_ir'] ?: [], $scope);
            }
            return self::finalizeVmResult(self::evaluateVm($program['vm_ir'] ?: [], $scope));
        }
        if (($program['engine'] ?: 'source') == 'jitphp') {
            return self::executeJitProgram($program, $manifestPath, $payloadId, $scope, $scopeClass);
        }

        $source = self::interpretSource($program, $payloadId);
        $runner = function (string $__source, array $__scope) {
            extract($__scope, EXTR_SKIP);
            return eval($__source);
        };
        $runner = \Closure::bind($runner, null, $scopeClass);
        return $runner($source, $scope);
    }

    public static function invokeSegment(string $manifestPath, string $payloadId, array $scope = [], string $scopeClass = '', ?object $boundObject = null, string $lateStaticClass = '')
    {
        $scope['__dux_bound_object'] = $boundObject;
        $scope['__dux_scope_class'] = $scopeClass;
        $scope['__dux_lsb_class'] = $lateStaticClass ?: ($boundObject ? get_class($boundObject) : $scopeClass);
        $scope['__dux_parent_class'] = $scopeClass && get_parent_class($scopeClass) ? get_parent_class($scopeClass) : '';
        unset($scope['this']);

        $functionCacheKey = $manifestPath . '::' . $payloadId;
        if (isset(self::$jitFunctionCache[$functionCacheKey])) {
            if (!(self::$jitLicenseFreeCache[$functionCacheKey] ?? false)) {
                self::verifyLicense($manifestPath, self::manifest($manifestPath));
            }
            return self::invokeLoadedJitFunction(self::$jitFunctionCache[$functionCacheKey], $scope, $scopeClass, $boundObject);
        }

        $manifest = self::manifest($manifestPath);
        self::verifyLicense($manifestPath, $manifest);
        $program = self::loadProgram($manifest, $manifestPath, $payloadId);
        self::hydrateVmProgram($program, $payloadId);

        if (($program['engine'] ?: 'source') == 'vm') {
            if (($program['vm_ir']['generator'] ?? false) === true) {
                return self::evaluateVmGenerator($program['vm_ir'] ?: [], $scope);
            }
            return self::finalizeVmResult(self::evaluateVm($program['vm_ir'] ?: [], $scope));
        }
        if (($program['engine'] ?: 'source') == 'jitphp') {
            return self::executeJitProgram($program, $manifestPath, $payloadId, $scope, $scopeClass, $boundObject);
        }

        $source = self::interpretSource($program, $payloadId);

        $runner = function (string $__source, array $__scope) {
            extract($__scope, EXTR_SKIP);
            return eval($__source);
        };

        if ($boundObject) {
            $runner = $runner->bindTo($boundObject, $scopeClass !== '' ? $scopeClass : get_class($boundObject));
        } elseif ($scopeClass !== '') {
            $runner = \Closure::bind($runner, null, $scopeClass);
        }

        return $runner($source, $scope);
    }

    private static function loadProgram(array $manifest, string $manifestPath, string $payloadId): array
    {
        $cacheKey = $manifestPath . '::' . $payloadId;
        if (isset(self::$programCache[$cacheKey])) {
            $program = self::$programCache[$cacheKey];
            $program['__cache_key'] = $cacheKey;
            $program['__payload_key'] = self::resolvePayloadKey($manifest);
            return $program;
        }
        $payloadPath = dirname($manifestPath) . '/payloads/' . $payloadId . '.bin';
        $ciphertext = trim((string)file_get_contents($payloadPath));
        if ($ciphertext === '') {
            throw new \RuntimeException('Encrypted payload not found: ' . $payloadId);
        }
        $raw = base64_decode($ciphertext, true);
        if ($raw === false) {
            throw new \RuntimeException('Invalid ciphertext payload');
        }
        $program = json_decode($raw, true);
        if (!is_array($program)) {
            throw new \RuntimeException('Invalid program payload');
        }
        $program = self::normalizeProgram($program);
        self::$programCache[$cacheKey] = $program;
        $program['__cache_key'] = $cacheKey;
        $program['__payload_key'] = self::resolvePayloadKey($manifest);
        if (!$program['__payload_key']) {
            throw new \RuntimeException('Payload key missing');
        }
        return $program;
    }

    private static function resolvePayloadKey(array $manifest): string
    {
        $plain = base64_decode((string)($manifest['payload_key'] ?? ''), true);
        if ($plain) {
            return $plain;
        }
        $ciphertext = (string)($manifest['payload_key_ciphertext'] ?? '');
        $salt = (string)($manifest['payload_key_salt'] ?? '');
        if ($ciphertext == '' || $salt == '') {
            return '';
        }
        $secret = (string)($_SERVER['DUX_ENCRYPT_RUNTIME_SECRET'] ?? getenv('DUX_ENCRYPT_RUNTIME_SECRET') ?: '');
        if ($secret == '') {
            throw new \RuntimeException('Runtime secret missing');
        }
        $cacheKey = $salt . ':' . sha1($secret);
        if (isset(self::$payloadKeyCache[$cacheKey])) {
            return self::$payloadKeyCache[$cacheKey];
        }
        $key = self::decryptChunk($ciphertext, $secret, 'manifest:' . $salt);
        self::$payloadKeyCache[$cacheKey] = $key;
        return $key;
    }

    private static function hydrateVmProgram(array &$program, string $payloadId): void
    {
        if (($program['engine'] ?: 'source') != 'vm') {
            return;
        }
        if (is_array($program['vm_ir'] ?? null)) {
            return;
        }
        $ciphertext = (string)($program['vm_ciphertext'] ?? '');
        if ($ciphertext == '') {
            throw new \RuntimeException('VM payload missing');
        }
        $json = self::decryptChunk($ciphertext, $program['__payload_key'], $payloadId . ':vm');
        $checksum = (string)($program['vm_checksum'] ?? '');
        if ($checksum != '' && !hash_equals($checksum, hash('sha256', $json))) {
            throw new \RuntimeException('VM payload checksum mismatch');
        }
        $vm = json_decode($json, true);
        if (!is_array($vm)) {
            throw new \RuntimeException('Invalid VM payload');
        }
        $program['vm_ir'] = $vm;
        unset($program['vm_ciphertext']);
        if (isset($program['__payload_key'], $program['__cache_key'])) {
            unset($program['__payload_key']);
            self::$programCache[$program['__cache_key']] = $program;
        }
    }

    private static function executeJitProgram(array $program, string $manifestPath, string $payloadId, array $scope = [], string $scopeClass = '', ?object $boundObject = null)
    {
        $function = self::ensureJitFunction($program, $manifestPath, $payloadId);
        return self::invokeLoadedJitFunction($function, $scope, $scopeClass, $boundObject);
    }

    private static function ensureJitFunction(array $program, string $manifestPath, string $payloadId): string
    {
        $function = '__dux_exec_' . $payloadId;
        $cachePath = self::jitCachePath($manifestPath, $payloadId, $program);
        if (!isset(self::$jitLoaded[$cachePath])) {
            $ciphertext = (string)($program['jit_ciphertext'] ?? '');
            if ($ciphertext == '') {
                throw new \RuntimeException('JIT payload missing');
            }
            $code = self::decryptChunk($ciphertext, $program['__payload_key'], $payloadId . ':jit');
            $checksum = (string)($program['jit_checksum'] ?? '');
            if ($checksum != '' && !hash_equals($checksum, hash('sha256', $code))) {
                throw new \RuntimeException('JIT payload checksum mismatch');
            }
            self::writeJitCache($cachePath, $code);
            if (self::canCompileJitCache()) {
                @opcache_compile_file($cachePath);
            }
            require_once $cachePath;
            if ((string)($_SERVER['DUX_ENCRYPT_EPHEMERAL_CACHE'] ?? getenv('DUX_ENCRYPT_EPHEMERAL_CACHE') ?: '') !== '') {
                @unlink($cachePath);
            }
            self::$jitLoaded[$cachePath] = true;
        }
        $functionCacheKey = $manifestPath . '::' . $payloadId;
        self::$jitFunctionCache[$functionCacheKey] = $function;
        self::$jitLicenseFreeCache[$functionCacheKey] = ((self::manifest($manifestPath)['__has_license'] ?? false) !== true);
        return $function;
    }

    private static function invokeLoadedJitFunction(string $function, array $scope, string $scopeClass = '', ?object $boundObject = null)
    {
        if (!$boundObject && $scopeClass === '') {
            return $function($scope);
        }
        return $function($scope, $scopeClass, $boundObject);
    }

    private static function normalizeProgram(array $program): array
    {
        if (isset($program['e'])) {
            $program['engine'] = $program['e'];
        }
        if (isset($program['m'])) {
            $program['opcode_map'] = $program['m'];
        }
        if (isset($program['c'])) {
            $program['constants'] = $program['c'];
        }
        if (isset($program['i'])) {
            $program['instructions'] = $program['i'];
        }
        if (isset($program['s'])) {
            $program['checksum'] = $program['s'];
        }
        if (isset($program['x'])) {
            $program['vm_checksum'] = $program['x'];
        }
        if (isset($program['y'])) {
            $program['vm_ciphertext'] = $program['y'];
        }
        if (isset($program['z'])) {
            $program['vm_ir'] = $program['z'];
        }
        if (isset($program['p'])) {
            $program['jit_args_mode'] = $program['p'];
        }
        if (isset($program['j'])) {
            $program['jit_checksum'] = $program['j'];
        }
        if (isset($program['k'])) {
            $program['jit_ciphertext'] = $program['k'];
        }
        return $program;
    }

    private static function jitCachePath(string $manifestPath, string $payloadId, array $program): string
    {
        $base = (string)($_SERVER['DUX_ENCRYPT_CACHE_DIR'] ?? getenv('DUX_ENCRYPT_CACHE_DIR') ?: sys_get_temp_dir() . '/dux-encrypt-cache');
        if (!is_dir($base)) {
            @mkdir($base, 0700, true);
        }
        $digest = hash('sha256', $manifestPath . ':' . $payloadId . ':' . (string)($program['jit_checksum'] ?? ''));
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . substr($digest, 0, 40) . '.php';
    }

    private static function canCompileJitCache(): bool
    {
        if (!function_exists('opcache_compile_file')) {
            return false;
        }
        $enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === false) {
            return false;
        }
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            $cliEnabled = filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $cliEnabled === true;
        }
        return true;
    }

    private static function writeJitCache(string $path, string $code): void
    {
        if (is_file($path)) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        file_put_contents($path, $code, LOCK_EX);
        @chmod($path, 0600);
    }

    public static function scopeArgs(array $scope): array
    {
        $args = $scope['__dux_args'] ?? [];
        return is_array($args) ? array_values($args) : [];
    }

    public static function scopeArg(array $scope, int $index)
    {
        $args = self::scopeArgs($scope);
        if ($index < 0 || !array_key_exists($index, $args)) {
            throw new \ValueError('func_get_arg(): Argument #1 ($position) must be less than the number of the arguments passed to the currently executed function');
        }
        return $args[$index];
    }

    private static function sourceOpcodeMap(string $payloadId): array
    {
        return [
            'LOAD_CONST' => self::opcodeToken($payloadId, 'LOAD_CONST'),
            'APPEND' => self::opcodeToken($payloadId, 'APPEND'),
            'RETURN' => self::opcodeToken($payloadId, 'RETURN'),
        ];
    }

    private static function opcodeToken(string $payloadId, string $name): string
    {
        return substr(strtoupper(hash('sha256', $payloadId . ':' . $name)), 0, 12);
    }

    public static function parentCall(string $parentClass, ?object $boundObject, string $method, ...$args)
    {
        if (!$parentClass) {
            throw new \RuntimeException('Parent class not found');
        }
        $ref = new \ReflectionMethod($parentClass, $method);
        if ($boundObject) {
            return $ref->invoke($boundObject, ...$args);
        }
        return $ref->invoke(null, ...$args);
    }

    private static function manifest(string $manifestPath): array
    {
        if (!isset(self::$manifestCache[$manifestPath])) {
            $manifest = require $manifestPath;
            if (!is_array($manifest)) {
                throw new \RuntimeException('Invalid manifest');
            }
            self::verifyManifestIntegrity($manifest);
            $manifest['__license_cache_seed'] = sha1(json_encode($manifest['license'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $manifest['__has_license'] = is_array($manifest['license'] ?? null) && is_array(($manifest['license']['payload'] ?? null));
            self::$manifestCache[$manifestPath] = $manifest;
        }
        return self::$manifestCache[$manifestPath];
    }

    private static function verifyManifestIntegrity(array $manifest): void
    {
        $hmac = (string)($manifest['manifest_hmac'] ?? '');
        $salt = (string)($manifest['manifest_hmac_salt'] ?? '');
        if ($hmac === '' || $salt === '') {
            return;
        }
        $secret = (string)($_SERVER['DUX_ENCRYPT_RUNTIME_SECRET'] ?? getenv('DUX_ENCRYPT_RUNTIME_SECRET') ?: '');
        if ($secret === '') {
            throw new \RuntimeException('Runtime secret missing');
        }
        $payload = $manifest;
        unset($payload['manifest_hmac'], $payload['manifest_hmac_salt']);
        $expected = base64_encode(hash_hmac('sha256', self::canonicalJson($payload), $secret . ':' . $salt, true));
        if (!hash_equals($hmac, $expected)) {
            throw new \RuntimeException('Manifest integrity check failed');
        }
    }

    private static function canonicalJson(array $data): string
    {
        $normalize = function ($value) use (&$normalize) {
            if (!is_array($value)) {
                return $value;
            }
            $assoc = array_keys($value) !== range(0, count($value) - 1);
            if ($assoc) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }
            return $value;
        };
        return (string)json_encode($normalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function interpretSource(array $program, string $payloadId): string
    {
		$reverseMap = array_flip(self::sourceOpcodeMap($payloadId));
		$constants = is_array($program['constants'] ?? null) ? $program['constants'] : [];
        $instructions = is_array($program['instructions'] ?? null) ? $program['instructions'] : [];
        $buffer = '';
        $register = '';
        $key = $program['__payload_key'];

        foreach ($instructions as $instruction) {
            if (!is_array($instruction) || !isset($instruction[0])) {
                throw new \RuntimeException('Invalid instruction');
            }
            $opcode = $reverseMap[(string)$instruction[0]] ?? null;
            if ($opcode === 'LOAD_CONST') {
                $index = (int)($instruction[1] ?? -1);
                $seedIndex = (int)($instruction[2] ?? $index);
                if (!array_key_exists($index, $constants)) {
                    throw new \RuntimeException('Constant index out of range');
                }
                $register = self::decryptChunk((string)$constants[$index], $key, sprintf('%s:%d', $payloadId, $seedIndex));
                continue;
            }
            if ($opcode === 'APPEND') {
                $buffer .= $register;
                continue;
            }
            if ($opcode === 'RETURN') {
                $checksum = (string)($program['checksum'] ?? '');
                if ($checksum !== '' && !hash_equals($checksum, hash('sha256', $buffer))) {
                    throw new \RuntimeException('Program checksum mismatch');
                }
                return $buffer;
            }
            throw new \RuntimeException('Unknown opcode');
        }

        throw new \RuntimeException('Program terminated without return');
    }

    private static function evaluateVm(array $node, array &$scope)
    {
        $type = $node['type'] ?: '';
        if ($type == 'block') {
            foreach ($node['instructions'] ?: [] as $instruction) {
                $result = self::evaluateVm($instruction, $scope);
                if (is_array($result) && ($result['__dux_ctrl'] ?? '') != '') {
                    return $result;
                }
            }
            return null;
        }
        if ($type == 'return') {
            return ['__dux_ctrl' => 'return', 'value' => self::evaluateVmExpr($node['expr'] ?: [], $scope)];
        }
        if ($type == 'noop') {
            return null;
        }
        if ($type == 'assign') {
            self::writeScopeVar($node['target'], self::evaluateVmExpr($node['expr'] ?: [], $scope), $scope);
            return null;
        }
        if ($type == 'static_init') {
            $payloadId = (string)self::evaluateVmExpr($node['payload'] ?: [], $scope);
            $slot = (string)self::evaluateVmExpr($node['slot'] ?: [], $scope);
            $default = self::evaluateVmExpr($node['default'] ?: [], $scope);
            $scope['__dux_static_bindings'] ??= [];
            $scope['__dux_static_bindings'][$node['target']] = ['payload' => $payloadId, 'slot' => $slot];
            self::writeScopeVar($node['target'], self::staticSlotValue($payloadId, $slot, $default), $scope);
            return null;
        }
        if ($type == 'destructure_assign') {
            $source = self::evaluateVmExpr($node['source'] ?: [], $scope);
            foreach ($node['items'] ?: [] as $item) {
                $key = self::evaluateVmExpr($item['key'] ?: [], $scope);
                $value = null;
                if (is_array($source) && array_key_exists($key, $source)) {
                    $value = $source[$key];
                }
                self::writeScopeVar($item['target'], $value, $scope);
            }
            return null;
        }
        if ($type == 'array_assign') {
            $target = $node['target'];
            $dims = [];
            foreach ($node['dims'] ?: [] as $dim) {
                $dims[] = self::evaluateVmExpr($dim, $scope);
            }
            $value = self::evaluateVmExpr($node['expr'] ?: [], $scope);
            $current = array_key_exists($target, $scope) ? $scope[$target] : [];
            if (!is_array($current)) {
                $current = [];
            }
            $current = self::writeArrayPath($current, $dims, $value);
            self::writeScopeVar($target, $current, $scope);
            return null;
        }
        if ($type == 'static_assign') {
            $class = self::resolveVmClassRef($node['class'], $scope);
            self::writeStaticProperty($class, $node['name'], self::evaluateVmExpr($node['expr'] ?: [], $scope));
            return null;
        }
        if ($type == 'static_array_assign') {
            $class = self::resolveVmClassRef($node['class'], $scope);
            $dims = [];
            foreach ($node['dims'] ?: [] as $dim) {
                $dims[] = self::evaluateVmExpr($dim, $scope);
            }
            $current = self::readStaticProperty($class, $node['name']);
            if (!is_array($current)) {
                $current = [];
            }
            $current = self::writeArrayPath($current, $dims, self::evaluateVmExpr($node['expr'] ?: [], $scope));
            self::writeStaticProperty($class, $node['name'], $current);
            return null;
        }
        if ($type == 'property_array_assign') {
            $object = $node['object'] == 'this' ? ($scope['__dux_bound_object'] ?? null) : self::evaluateVmExpr($node['object'], $scope);
            if (!is_object($object)) {
                throw new \RuntimeException('Property assignment target is not object');
            }
            $dims = [];
            foreach ($node['dims'] ?: [] as $dim) {
                $dims[] = self::evaluateVmExpr($dim, $scope);
            }
            $current = self::readObjectProperty($object, $node['name']);
            if (!is_array($current)) {
                $current = [];
            }
            $current = self::writeArrayPath($current, $dims, self::evaluateVmExpr($node['expr'] ?: [], $scope));
            self::writeObjectProperty($object, $node['name'], $current);
            return null;
        }
        if ($type == 'property_assign') {
            $object = $node['object'] == 'this' ? ($scope['__dux_bound_object'] ?? null) : self::evaluateVmExpr($node['object'], $scope);
            if (!is_object($object)) {
                throw new \RuntimeException('Property assignment target is not object');
            }
            self::writeObjectProperty($object, $node['name'], self::evaluateVmExpr($node['expr'] ?: [], $scope));
            return null;
        }
        if ($type == 'expr') {
            self::evaluateVmExpr($node['expr'] ?: [], $scope);
            return null;
        }
        if ($type == 'if') {
            $cond = self::evaluateVmExpr($node['cond'] ?: [], $scope);
            if ($cond) {
                return self::evaluateVm($node['then'] ?: [], $scope);
            }
            if ($node['else']) {
                return self::evaluateVm($node['else'], $scope);
            }
            return null;
        }
        if ($type == 'switch') {
            $cond = self::evaluateVmExpr($node['cond'] ?: [], $scope);
            $matched = false;
            foreach ($node['cases'] ?: [] as $case) {
                if (!$matched) {
                    if ($case['cond'] == null) {
                        $matched = true;
                    }
                    elseif ($cond === self::evaluateVmExpr($case['cond'], $scope)) {
                        $matched = true;
                    }
                }
                if (!$matched) {
                    continue;
                }
                $result = self::evaluateVm($case['body'] ?: [], $scope);
                if (!is_array($result)) {
                    continue;
                }
                if (($result['__dux_ctrl'] ?? '') == 'break') {
                    return null;
                }
                return $result;
            }
            return null;
        }
        if ($type == 'while') {
            while (self::evaluateVmExpr($node['cond'] ?: [], $scope)) {
                $result = self::evaluateVm($node['body'] ?: [], $scope);
                if (!is_array($result)) {
                    continue;
                }
                $ctrl = $result['__dux_ctrl'] ?? '';
                if ($ctrl == 'continue') {
                    continue;
                }
                if ($ctrl == 'break') {
                    return null;
                }
                return $result;
            }
            return null;
        }
        if ($type == 'foreach') {
            $iterable = self::evaluateVmExpr($node['iterable'] ?: [], $scope);
            if (!is_iterable($iterable)) {
                return null;
            }
            foreach ($iterable as $key => $value) {
                if ($node['key']) {
                    $scope[$node['key']] = $key;
                }
                $scope[$node['value']] = $value;
                $result = self::evaluateVm($node['body'] ?: [], $scope);
                if (!is_array($result)) {
                    continue;
                }
                $ctrl = $result['__dux_ctrl'] ?? '';
                if ($ctrl == 'continue') {
                    continue;
                }
                if ($ctrl == 'break') {
                    return null;
                }
                return $result;
            }
            return null;
        }
        if ($type == 'for') {
            self::evaluateVm($node['init'] ?: ['type' => 'block', 'instructions' => []], $scope);
            while ($node['cond'] == null || self::evaluateVmExpr($node['cond'], $scope)) {
                $result = self::evaluateVm($node['body'] ?: [], $scope);
                if (is_array($result)) {
                    $ctrl = $result['__dux_ctrl'] ?? '';
                    if ($ctrl == 'break') {
                        return null;
                    }
                    if ($ctrl != 'continue') {
                        return $result;
                    }
                }
                self::evaluateVm($node['loop'] ?: ['type' => 'block', 'instructions' => []], $scope);
            }
            return null;
        }
        if ($type == 'try') {
            $result = null;
            $error = null;
            try {
                $result = self::evaluateVm($node['body'] ?: [], $scope);
            }
            catch (\Throwable $exception) {
                $handled = false;
                foreach ($node['catches'] ?: [] as $catch) {
                    if (!self::matchCatchType($exception, $catch['types'] ?: [], $scope)) {
                        continue;
                    }
                    if ($catch['var']) {
                        self::writeScopeVar($catch['var'], $exception, $scope);
                    }
                    $result = self::evaluateVm($catch['body'] ?: [], $scope);
                    $handled = true;
                    break;
                }
                if (!$handled) {
                    $error = $exception;
                }
            }
            if ($node['finally']) {
                $finalResult = self::evaluateVm($node['finally'], $scope);
                if (is_array($finalResult) && ($finalResult['__dux_ctrl'] ?? '') != '') {
                    return $finalResult;
                }
            }
            if ($error) {
                throw $error;
            }
            return $result;
        }
        if ($type == 'throw') {
            $error = self::evaluateVmExpr($node['expr'] ?: [], $scope);
            if (!$error instanceof \Throwable) {
                throw new \RuntimeException('Throw target must implement Throwable');
            }
            throw $error;
        }
        if ($type == 'break') {
            return ['__dux_ctrl' => 'break'];
        }
        if ($type == 'continue') {
            return ['__dux_ctrl' => 'continue'];
        }
        if ($type == 'unset') {
            foreach ($node['targets'] ?: [] as $target) {
                $kind = $target['kind'] ?? '';
                if ($kind == 'var') {
                    unset($scope[$target['name']]);
                    continue;
                }
                if ($kind == 'array') {
                    $dims = [];
                    foreach ($target['dims'] ?: [] as $dim) {
                        $dims[] = self::evaluateVmExpr($dim, $scope);
                    }
                    $value = self::readScopeVar($target['target'], $scope);
                    if (is_array($value)) {
                        self::writeScopeVar($target['target'], self::unsetArrayPath($value, $dims), $scope);
                    }
                    continue;
                }
                if ($kind == 'static_array') {
                    $dims = [];
                    foreach ($target['dims'] ?: [] as $dim) {
                        $dims[] = self::evaluateVmExpr($dim, $scope);
                    }
                    $class = self::resolveVmClassRef($target['class'], $scope);
                    $value = self::readStaticProperty($class, $target['name']);
                    if (is_array($value)) {
                        self::writeStaticProperty($class, $target['name'], self::unsetArrayPath($value, $dims));
                    }
                    continue;
                }
                if ($kind == 'property_array') {
                    $dims = [];
                    foreach ($target['dims'] ?: [] as $dim) {
                        $dims[] = self::evaluateVmExpr($dim, $scope);
                    }
                    $object = $target['object'] == 'this' ? ($scope['__dux_bound_object'] ?? null) : self::evaluateVmExpr($target['object'], $scope);
                    if (is_object($object)) {
                        $value = self::readObjectProperty($object, $target['name']);
                        if (is_array($value)) {
                            self::writeObjectProperty($object, $target['name'], self::unsetArrayPath($value, $dims));
                        }
                    }
                }
            }
            return null;
        }
        throw new \RuntimeException('Unsupported vm node');
    }

    private static function evaluateVmGenerator(array $node, array $scope): \Generator
    {
        return (function () use ($node, &$scope) {
            yield from self::iterateVmGenerator($node, $scope);
        })();
    }

    private static function iterateVmGenerator(array $node, array &$scope): \Generator
    {
        $type = $node['type'] ?: '';
        if ($type == 'block') {
            foreach ($node['instructions'] ?: [] as $instruction) {
                $result = yield from self::iterateVmGenerator($instruction, $scope);
                if (is_array($result) && ($result['__dux_ctrl'] ?? '') != '') {
                    return $result;
                }
            }
            return null;
        }
        if ($type == 'yield') {
            yield self::evaluateVmExpr($node['expr'] ?: [], $scope);
            return null;
        }
        if ($type == 'yield_from') {
            $iterable = self::evaluateVmExpr($node['expr'] ?: [], $scope);
            if (is_iterable($iterable)) {
                foreach ($iterable as $item) {
                    yield $item;
                }
            }
            return null;
        }
        if ($type == 'if') {
            $cond = self::evaluateVmExpr($node['cond'] ?: [], $scope);
            if ($cond) {
                return yield from self::iterateVmGenerator($node['then'] ?: [], $scope);
            }
            if ($node['else']) {
                return yield from self::iterateVmGenerator($node['else'], $scope);
            }
            return null;
        }
        if ($type == 'while') {
            while (self::evaluateVmExpr($node['cond'] ?: [], $scope)) {
                $result = yield from self::iterateVmGenerator($node['body'] ?: [], $scope);
                if (is_array($result)) {
                    $ctrl = $result['__dux_ctrl'] ?? '';
                    if ($ctrl == 'continue') {
                        continue;
                    }
                    if ($ctrl == 'break') {
                        return null;
                    }
                    return $result;
                }
            }
            return null;
        }
        if ($type == 'foreach') {
            $iterable = self::evaluateVmExpr($node['iterable'] ?: [], $scope);
            if (!is_iterable($iterable)) {
                return null;
            }
            foreach ($iterable as $key => $value) {
                if ($node['key']) {
                    self::writeScopeVar($node['key'], $key, $scope);
                }
                self::writeScopeVar($node['value'], $value, $scope);
                $result = yield from self::iterateVmGenerator($node['body'] ?: [], $scope);
                if (is_array($result)) {
                    $ctrl = $result['__dux_ctrl'] ?? '';
                    if ($ctrl == 'continue') {
                        continue;
                    }
                    if ($ctrl == 'break') {
                        return null;
                    }
                    return $result;
                }
            }
            return null;
        }
        if ($type == 'for') {
            self::evaluateVm($node['init'] ?: ['type' => 'block', 'instructions' => []], $scope);
            while ($node['cond'] == null || self::evaluateVmExpr($node['cond'], $scope)) {
                $result = yield from self::iterateVmGenerator($node['body'] ?: [], $scope);
                if (is_array($result)) {
                    $ctrl = $result['__dux_ctrl'] ?? '';
                    if ($ctrl == 'break') {
                        return null;
                    }
                    if ($ctrl != 'continue') {
                        return $result;
                    }
                }
                self::evaluateVm($node['loop'] ?: ['type' => 'block', 'instructions' => []], $scope);
            }
            return null;
        }
        if ($type == 'switch') {
            $cond = self::evaluateVmExpr($node['cond'] ?: [], $scope);
            $matched = false;
            foreach ($node['cases'] ?: [] as $case) {
                if (!$matched) {
                    if ($case['cond'] == null) {
                        $matched = true;
                    } elseif ($cond === self::evaluateVmExpr($case['cond'], $scope)) {
                        $matched = true;
                    }
                }
                if (!$matched) {
                    continue;
                }
                $result = yield from self::iterateVmGenerator($case['body'] ?: [], $scope);
                if (!is_array($result)) {
                    continue;
                }
                if (($result['__dux_ctrl'] ?? '') == 'break') {
                    return null;
                }
                return $result;
            }
            return null;
        }
        if ($type == 'try') {
            $result = null;
            $error = null;
            try {
                $result = yield from self::iterateVmGenerator($node['body'] ?: [], $scope);
            } catch (\Throwable $exception) {
                $handled = false;
                foreach ($node['catches'] ?: [] as $catch) {
                    if (!self::matchCatchType($exception, $catch['types'] ?: [], $scope)) {
                        continue;
                    }
                    if ($catch['var']) {
                        self::writeScopeVar($catch['var'], $exception, $scope);
                    }
                    $result = yield from self::iterateVmGenerator($catch['body'] ?: [], $scope);
                    $handled = true;
                    break;
                }
                if (!$handled) {
                    $error = $exception;
                }
            }
            if ($node['finally']) {
                $finalResult = yield from self::iterateVmGenerator($node['finally'], $scope);
                if (is_array($finalResult) && ($finalResult['__dux_ctrl'] ?? '') != '') {
                    return $finalResult;
                }
            }
            if ($error) {
                throw $error;
            }
            return $result;
        }
        $result = self::evaluateVm($node, $scope);
        return $result;
    }

    private static function evaluateVmExpr(array $expr, array &$scope)
    {
        $type = $expr['type'] ?: '';
        if ($type == 'null') {
            return null;
        }
        if ($type == 'string' || $type == 'int' || $type == 'float' || $type == 'bool') {
            return $expr['value'];
        }
        if ($type == 'const_fetch') {
            return constant($expr['name']);
        }
        if ($type == 'var') {
            $name = $expr['name'];
            if ($name == 'this') {
                return $scope['__dux_bound_object'] ?? null;
            }
            return self::readScopeVar($name, $scope);
        }
        if ($type == 'cast_string') {
            return (string)self::evaluateVmExpr($expr['expr'], $scope);
        }
        if ($type == 'cast_int') {
            return (int)self::evaluateVmExpr($expr['expr'], $scope);
        }
        if ($type == 'cast_bool') {
            return (bool)self::evaluateVmExpr($expr['expr'], $scope);
        }
        if ($type == 'cast_array') {
            return (array)self::evaluateVmExpr($expr['expr'], $scope);
        }
        if ($type == 'cast_object') {
            return (object)self::evaluateVmExpr($expr['expr'], $scope);
        }
        if ($type == 'cast_float') {
            return (float)self::evaluateVmExpr($expr['expr'], $scope);
        }
        if ($type == 'suppress') {
            return self::evaluateVmExpr($expr['expr'], $scope);
        }
        if ($type == 'throw_expr') {
            $error = self::evaluateVmExpr($expr['expr'], $scope);
            if (!$error instanceof \Throwable) {
                throw new \RuntimeException('Throw target must implement Throwable');
            }
            throw $error;
        }
        if ($type == 'assign_var') {
            $value = self::evaluateVmExpr($expr['expr'], $scope);
            self::writeScopeVar($expr['target'], $value, $scope);
            return $value;
        }
        if ($type == 'coalesce_assign_var') {
            $current = self::readScopeVar($expr['target'], $scope);
            if ($current !== null) {
                return $current;
            }
            $value = self::evaluateVmExpr($expr['expr'], $scope);
            self::writeScopeVar($expr['target'], $value, $scope);
            return $value;
        }
        if ($type == 'static_assign_expr') {
            $value = self::evaluateVmExpr($expr['expr'], $scope);
            $class = self::resolveVmClassRef($expr['class'], $scope);
            self::writeStaticProperty($class, $expr['name'], $value);
            return $value;
        }
        if ($type == 'coalesce_assign_static') {
            $class = self::resolveVmClassRef($expr['class'], $scope);
            $current = self::readStaticProperty($class, $expr['name']);
            if ($current !== null) {
                return $current;
            }
            $value = self::evaluateVmExpr($expr['expr'], $scope);
            self::writeStaticProperty($class, $expr['name'], $value);
            return $value;
        }
        if ($type == 'property_assign_expr') {
            $value = self::evaluateVmExpr($expr['expr'], $scope);
            $object = $expr['object'] == 'this' ? ($scope['__dux_bound_object'] ?? null) : self::evaluateVmExpr($expr['object'], $scope);
            if (!is_object($object)) {
                throw new \RuntimeException('Property assignment target is not object');
            }
            self::writeObjectProperty($object, $expr['name'], $value);
            return $value;
        }
        if ($type == 'coalesce_assign_property') {
            $object = $expr['object'] == 'this' ? ($scope['__dux_bound_object'] ?? null) : self::evaluateVmExpr($expr['object'], $scope);
            if (!is_object($object)) {
                throw new \RuntimeException('Property assignment target is not object');
            }
            $current = self::readObjectProperty($object, $expr['name']);
            if ($current !== null) {
                return $current;
            }
            $value = self::evaluateVmExpr($expr['expr'], $scope);
            self::writeObjectProperty($object, $expr['name'], $value);
            return $value;
        }
        if ($type == 'pre_inc') {
            $value = self::readScopeVar($expr['target'], $scope) + 1;
            self::writeScopeVar($expr['target'], $value, $scope);
            return $value;
        }
        if ($type == 'post_inc') {
            $value = self::readScopeVar($expr['target'], $scope);
            self::writeScopeVar($expr['target'], $value + 1, $scope);
            return $value;
        }
        if ($type == 'pre_dec') {
            $value = self::readScopeVar($expr['target'], $scope) - 1;
            self::writeScopeVar($expr['target'], $value, $scope);
            return $value;
        }
        if ($type == 'post_dec') {
            $value = self::readScopeVar($expr['target'], $scope);
            self::writeScopeVar($expr['target'], $value - 1, $scope);
            return $value;
        }
        if ($type == 'property_fetch' && $expr['object'] == 'this') {
            $object = $scope['__dux_bound_object'] ?? null;
            if (!$object) {
                throw new \RuntimeException('Missing bound object');
            }
            $prop = $expr['name'];
            return (function () use ($object, $prop) {
                return $object->$prop;
            })->bindTo($object, get_class($object))();
        }
        if ($type == 'property_fetch_expr') {
            $object = self::evaluateVmExpr($expr['object'], $scope);
            if (!is_object($object)) {
                throw new \RuntimeException('Property fetch target is not object');
            }
            return self::readObjectProperty($object, $expr['name']);
        }
        if ($type == 'nullsafe_property_fetch') {
            $object = self::evaluateVmExpr($expr['object'], $scope);
            if ($object === null) {
                return null;
            }
            if (!is_object($object)) {
                throw new \RuntimeException('Nullsafe property target is not object');
            }
            return self::readObjectProperty($object, $expr['name']);
        }
        if ($type == 'concat') {
            return (string)self::evaluateVmExpr($expr['left'], $scope) . (string)self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'plus') {
            return self::evaluateVmExpr($expr['left'], $scope) + self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'minus') {
            return self::evaluateVmExpr($expr['left'], $scope) - self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'multiply') {
            return self::evaluateVmExpr($expr['left'], $scope) * self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'greater') {
            return self::evaluateVmExpr($expr['left'], $scope) > self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'less') {
            return self::evaluateVmExpr($expr['left'], $scope) < self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'greater_or_equal') {
            return self::evaluateVmExpr($expr['left'], $scope) >= self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'less_or_equal') {
            return self::evaluateVmExpr($expr['left'], $scope) <= self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'identical') {
            return self::evaluateVmExpr($expr['left'], $scope) === self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'not_identical') {
            return self::evaluateVmExpr($expr['left'], $scope) !== self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'not_equal') {
            return self::evaluateVmExpr($expr['left'], $scope) != self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'spaceship') {
            return self::evaluateVmExpr($expr['left'], $scope) <=> self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'instanceof') {
            $target = self::evaluateVmExpr($expr['expr'], $scope);
            if (!is_object($target)) {
                return false;
            }
            $class = self::resolveVmClassRef($expr['class'], $scope);
            return is_a($target, $class);
        }
        if ($type == 'boolean_and') {
            return self::evaluateVmExpr($expr['left'], $scope) && self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'boolean_or') {
            return self::evaluateVmExpr($expr['left'], $scope) || self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'boolean_not') {
            return !self::evaluateVmExpr($expr['expr'], $scope);
        }
        if ($type == 'unary_minus') {
            return -self::evaluateVmExpr($expr['expr'], $scope);
        }
        if ($type == 'coalesce') {
            $left = self::evaluateVmExpr($expr['left'], $scope);
            return $left ?? self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'array_fetch') {
            $target = $expr['target'];
            $value = array_key_exists($target, $scope) ? $scope[$target] : null;
            if (!is_array($value)) {
                return null;
            }
            $dims = [];
            foreach ($expr['dims'] as $dim) {
                $dims[] = self::evaluateVmExpr($dim, $scope);
            }
            return self::readArrayPath($value, $dims);
        }
        if ($type == 'offset_fetch') {
            $value = self::evaluateVmExpr($expr['target'], $scope);
            $dims = [];
            foreach ($expr['dims'] as $dim) {
                $dims[] = self::evaluateVmExpr($dim, $scope);
            }
            return self::readOffsetPath($value, $dims);
        }
        if ($type == 'static_array_fetch') {
            $class = self::resolveVmClassRef($expr['class'], $scope);
            $value = self::readStaticProperty($class, $expr['name']);
            if (!is_array($value)) {
                return null;
            }
            $dims = [];
            foreach ($expr['dims'] as $dim) {
                $dims[] = self::evaluateVmExpr($dim, $scope);
            }
            return self::readArrayPath($value, $dims);
        }
        if ($type == 'property_array_fetch') {
            $object = $expr['object'] == 'this' ? ($scope['__dux_bound_object'] ?? null) : self::evaluateVmExpr($expr['object'], $scope);
            if (!is_object($object)) {
                return null;
            }
            $value = self::readObjectProperty($object, $expr['name']);
            if (!is_array($value)) {
                return null;
            }
            $dims = [];
            foreach ($expr['dims'] as $dim) {
                $dims[] = self::evaluateVmExpr($dim, $scope);
            }
            return self::readArrayPath($value, $dims);
        }
        if ($type == 'array_literal') {
            $items = [];
            foreach ($expr['items'] as $item) {
                if ($item['unpack'] ?? false) {
                    $value = self::evaluateVmExpr($item['value'], $scope);
                    if (is_array($value)) {
                        foreach ($value as $unpackValue) {
                            $items[] = $unpackValue;
                        }
                    }
                    continue;
                }
                $value = self::evaluateVmExpr($item['value'], $scope);
                if ($item['key']) {
                    $key = self::evaluateVmExpr($item['key'], $scope);
                    $items[$key] = $value;
                    continue;
                }
                $items[] = $value;
            }
            return $items;
        }
        if ($type == 'interpolated_string') {
            $result = '';
            foreach ($expr['parts'] as $part) {
                $result .= (string)self::evaluateVmExpr($part, $scope);
            }
            return $result;
        }
        if ($type == 'isset_expr') {
            foreach ($expr['exprs'] ?: [] as $item) {
                if (self::evaluateVmExpr($item, $scope) === null) {
                    return false;
                }
            }
            return true;
        }
        if ($type == 'empty_expr') {
            return empty(self::evaluateVmExpr($expr['expr'], $scope));
        }
        if ($type == 'closure_literal') {
            $closureScope = $scope;
            $body = $expr['body'];
            $params = $expr['params'] ?: [];
            if ($expr['capture_by_ref'] ?? false) {
                return function (...$args) use (&$scope, $body, $params) {
                    foreach ($params as $index => $param) {
                        $value = $args[$index] ?? null;
                        if (!array_key_exists($index, $args) && array_key_exists('default', $param) && $param['default']) {
                            $value = self::evaluateVmExpr($param['default'], $scope);
                        }
                        self::writeScopeVar($param['name'], $value, $scope);
                    }
                    return self::finalizeVmResult(self::evaluateVm($body, $scope));
                };
            }
            return function (...$args) use ($closureScope, $body, $params) {
                $callScope = $closureScope;
                foreach ($params as $index => $param) {
                    $value = $args[$index] ?? null;
                    if (!array_key_exists($index, $args) && array_key_exists('default', $param) && $param['default']) {
                        $value = self::evaluateVmExpr($param['default'], $callScope);
                    }
                    self::writeScopeVar($param['name'], $value, $callScope);
                }
                return self::finalizeVmResult(self::evaluateVm($body, $callScope));
            };
        }
        if ($type == 'func_call') {
            $args = self::evaluateCallArgs($expr['args'], $scope);
            return $expr['name'](...$args);
        }
        if ($type == 'callable_call') {
            $target = self::evaluateVmExpr($expr['target'], $scope);
            if (!is_callable($target)) {
                throw new \RuntimeException('Call target is not callable');
            }
            $args = self::evaluateCallArgs($expr['args'], $scope);
            return $target(...$args);
        }
        if ($type == 'new_object') {
            $args = self::evaluateCallArgs($expr['args'], $scope);
            $class = self::resolveVmClassRef($expr['class'], $scope);
            return new $class(...$args);
        }
        if ($type == 'divide') {
            return self::evaluateVmExpr($expr['left'], $scope) / self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'modulo') {
            return self::evaluateVmExpr($expr['left'], $scope) % self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'bitwise_or') {
            return self::evaluateVmExpr($expr['left'], $scope) | self::evaluateVmExpr($expr['right'], $scope);
        }
        if ($type == 'method_call') {
            $args = self::evaluateCallArgs($expr['args'], $scope);
            $target = $expr['target'] == 'this' ? ($scope['__dux_bound_object'] ?? null) : (array_key_exists($expr['target'], $scope) ? $scope[$expr['target']] : null);
            if (!is_object($target)) {
                throw new \RuntimeException('Method call target is not object');
            }
            $method = $expr['method'];
            return self::invokeObjectMethod($target, $method, $args);
        }
        if ($type == 'method_call_expr') {
            $target = self::evaluateVmExpr($expr['target'], $scope);
            if (!is_object($target)) {
                throw new \RuntimeException('Method call target is not object');
            }
            $args = self::evaluateCallArgs($expr['args'], $scope);
            return self::invokeObjectMethod($target, $expr['method'], $args);
        }
        if ($type == 'nullsafe_method_call') {
            $target = self::evaluateVmExpr($expr['target'], $scope);
            if ($target === null) {
                return null;
            }
            if (!is_object($target)) {
                throw new \RuntimeException('Nullsafe method target is not object');
            }
            $args = self::evaluateCallArgs($expr['args'], $scope);
            return self::invokeObjectMethod($target, $expr['method'], $args);
        }
        if ($type == 'static_call') {
            $args = self::evaluateCallArgs($expr['args'], $scope);
            $class = self::resolveVmClassRef($expr['class'], $scope);
            return $class::{$expr['method']}(...$args);
        }
        if ($type == 'static_property_fetch') {
            $class = self::resolveVmClassRef($expr['class'], $scope);
            $name = $expr['name'];
            return self::readStaticProperty($class, $name);
        }
        if ($type == 'class_const_fetch') {
            $class = self::resolveVmClassRef($expr['class'], $scope);
            return self::readClassConst($class, $expr['name']);
        }
        if ($type == 'class_name') {
            return self::resolveVmClassRef($expr['class'], $scope);
        }
        if ($type == 'ternary') {
            return self::evaluateVmExpr($expr['cond'], $scope)
                ? self::evaluateVmExpr($expr['if'], $scope)
                : self::evaluateVmExpr($expr['else'], $scope);
        }
        if ($type == 'match') {
            $cond = self::evaluateVmExpr($expr['cond'], $scope);
            foreach ($expr['arms'] as $arm) {
                if ($arm['conds'] == []) {
                    return self::evaluateVmExpr($arm['body'], $scope);
                }
                foreach ($arm['conds'] as $armCond) {
                    if ($cond === self::evaluateVmExpr($armCond, $scope)) {
                        return self::evaluateVmExpr($arm['body'], $scope);
                    }
                }
            }
            throw new \RuntimeException('Unhandled match arm');
        }
        throw new \RuntimeException('Unsupported vm expr');
    }

    private static function finalizeVmResult($result)
    {
        if (!is_array($result)) {
            return $result;
        }
        if (($result['__dux_ctrl'] ?? '') == 'return') {
            return $result['value'];
        }
        return null;
    }

    private static function matchCatchType(\Throwable $exception, array $types, array $scope): bool
    {
        if ($types == []) {
            return false;
        }
        foreach ($types as $type) {
            $class = self::resolveVmClassRef($type, $scope);
            if (is_a($exception, $class)) {
                return true;
            }
        }
        return false;
    }

    private static function evaluateCallArgs(array $args, array &$scope): array
    {
        $items = [];
        foreach ($args as $arg) {
            $valueExpr = is_array($arg) && array_key_exists('value', $arg) ? $arg['value'] : $arg;
            $value = self::evaluateVmExpr($valueExpr, $scope);
            $name = is_array($arg) ? ($arg['name'] ?? null) : null;
            if ($name) {
                $items[$name] = $value;
            } else {
                $items[] = $value;
            }
        }
        return $items;
    }

    private static function readScopeVar(string $name, array $scope)
    {
        if (array_key_exists($name, $scope)) {
            return $scope[$name];
        }
        return null;
    }

    private static function writeScopeVar(string $name, $value, array &$scope): void
    {
        $scope[$name] = $value;
        if (($scope['__dux_static_bindings'][$name] ?? null) && is_array($scope['__dux_static_bindings'][$name])) {
            $binding = $scope['__dux_static_bindings'][$name];
            self::staticSlotSet((string)$binding['payload'], (string)$binding['slot'], $value);
        }
    }

    private static function invokeObjectMethod(object $target, string $method, array $args)
    {
        $ref = new \ReflectionMethod($target, $method);
        if ($ref->isPublic() || $ref->getDeclaringClass()->isInternal()) {
            return $target->{$method}(...$args);
        }
        $call = function () use ($target, $method, $args) {
            return $target->{$method}(...$args);
        };
        return $call->bindTo($target, get_class($target))();
    }

    private static function readObjectProperty(object $object, string $name)
    {
        $reader = function () use ($object, $name) {
            return $object->$name;
        };
        return $reader->bindTo($object, get_class($object))();
    }

    private static function writeObjectProperty(object $object, string $name, $value): void
    {
        $writer = function () use ($object, $name, $value) {
            $object->$name = $value;
        };
        $writer->bindTo($object, get_class($object))();
    }

    private static function readArrayPath(array $value, array $dims)
    {
        $current = $value;
        foreach ($dims as $dim) {
            if (!is_array($current) || !array_key_exists($dim, $current)) {
                return null;
            }
            $current = $current[$dim];
        }
        return $current;
    }

    private static function readOffsetPath($value, array $dims)
    {
        $current = $value;
        foreach ($dims as $dim) {
            if (is_array($current)) {
                if (!array_key_exists($dim, $current)) {
                    return null;
                }
                $current = $current[$dim];
                continue;
            }
            if (is_string($current)) {
                if (!isset($current[$dim])) {
                    return null;
                }
                $current = $current[$dim];
                continue;
            }
            return null;
        }
        return $current;
    }

    private static function writeArrayPath(array $value, array $dims, $payload): array
    {
        if ($dims == []) {
            return $value;
        }
        $dim = array_shift($dims);
        if ($dims == []) {
            if ($dim === null) {
                $value[] = $payload;
                return $value;
            }
            $value[$dim] = $payload;
            return $value;
        }
        $next = [];
        if ($dim !== null && array_key_exists($dim, $value) && is_array($value[$dim])) {
            $next = $value[$dim];
        }
        $next = self::writeArrayPath($next, $dims, $payload);
        if ($dim === null) {
            $value[] = $next;
            return $value;
        }
        $value[$dim] = $next;
        return $value;
    }

    private static function unsetArrayPath(array $value, array $dims): array
    {
        if ($dims == []) {
            return $value;
        }
        $dim = array_shift($dims);
        if ($dims == []) {
            unset($value[$dim]);
            return $value;
        }
        if (!array_key_exists($dim, $value) || !is_array($value[$dim])) {
            return $value;
        }
        $value[$dim] = self::unsetArrayPath($value[$dim], $dims);
        return $value;
    }

    private static function resolveVmClass(string $class, array $scope): string
    {
        $lower = strtolower($class);
        if ($lower == 'self') {
            return (string)($scope['__dux_scope_class'] ?? '');
        }
        if ($lower == 'static') {
            return (string)($scope['__dux_lsb_class'] ?? '');
        }
        if ($lower == 'parent') {
            return (string)($scope['__dux_parent_class'] ?? '');
        }
        return $class;
    }

    private static function resolveVmClassRef(string|array $class, array $scope): string
    {
        if (is_array($class)) {
            return (string)self::evaluateVmExpr($class, $scope);
        }
        return self::resolveVmClass($class, $scope);
    }

    private static function readStaticProperty(string $class, string $name)
    {
        $reader = function () use ($name) {
            return self::$$name;
        };
        return \Closure::bind($reader, null, $class)();
    }

    private static function writeStaticProperty(string $class, string $name, $value): void
    {
        $writer = function () use ($name, $value) {
            self::$$name = $value;
        };
        \Closure::bind($writer, null, $class)();
    }

    private static function readClassConst(string $class, string $name)
    {
        $reader = function () use ($name) {
            return constant('self::' . $name);
        };
        return \Closure::bind($reader, null, $class)();
    }

    private static function decryptChunk(string $ciphertext, string $key, string $seed): string
    {
        $data = base64_decode($ciphertext, true);
        if ($data === false) {
            throw new \RuntimeException('Invalid ciphertext chunk');
        }
        $stream = '';
        for ($counter = 0; strlen($stream) < strlen($data); $counter++) {
            $stream .= hash('sha256', base64_encode($key) . ':' . $seed . ':' . $counter, true);
        }
        $stream = substr($stream, 0, strlen($data));
        return $data ^ $stream;
    }

    private static function verifyLicense(string $manifestPath, array $manifest): void
    {
        if (($manifest['__has_license'] ?? false) !== true) {
            return;
        }

        $projectId = (string)($_SERVER['DUX_ENCRYPT_PROJECT_ID'] ?? getenv('DUX_ENCRYPT_PROJECT_ID') ?: '');
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $cacheKey = $manifestPath . '|' . $projectId . '|' . $host . '|' . (string)($manifest['__license_cache_seed'] ?? '');
        $now = time();
        if (isset(self::$licenseCache[$cacheKey]) && self::$licenseCache[$cacheKey] >= $now) {
            return;
        }

        $manifest += [
			'license' => [],
		];
        $license = $manifest['license'] ?: [];
        if (!$license || !is_array($license) || !is_array($license['payload'])) {
            return;
        }

        $license += [
			'public_key' => '',
			'signature' => '',
		];

        $payload = $license['payload'];
        $payload += [
			'expires_at' => '',
			'project_id' => '',
			'domain_whitelist' => [],
		];

        if ($payload['expires_at']) {
            $expiresAt = strtotime((string)$payload['expires_at']);
            if ($expiresAt !== false && $expiresAt < time()) {
                throw new \RuntimeException('License expired');
            }
        }

        if ($payload['project_id']) {
            if ($projectId && $projectId != (string)$payload['project_id']) {
                throw new \RuntimeException('License project binding mismatch');
            }
        }

        if ($payload['domain_whitelist'] && is_array($payload['domain_whitelist'])) {
            if ($host && !in_array($host, $payload['domain_whitelist'], true)) {
                throw new \RuntimeException('License domain binding mismatch');
            }
        }

        if ($license['public_key'] && $license['signature'] && function_exists('sodium_crypto_sign_verify_detached')) {
            $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $signature = base64_decode((string)$license['signature'], true);
            $publicKey = base64_decode((string)$license['public_key'], true);
            if (!$message || !$signature || !$publicKey || !sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                throw new \RuntimeException('License signature invalid');
            }
        }

        $expiresAt = strtotime((string)$payload['expires_at']);
        if ($expiresAt !== false && $expiresAt > 0) {
            self::$licenseCache[$cacheKey] = min($expiresAt, $now + 60);
            return;
        }
        self::$licenseCache[$cacheKey] = $now + 60;
    }

    public static function &staticSlot(string $payloadId, string $name, $default = null)
    {
        if (!isset(self::$staticSlots[$payloadId])) {
            self::$staticSlots[$payloadId] = [];
        }
        if (!array_key_exists($name, self::$staticSlots[$payloadId])) {
            self::$staticSlots[$payloadId][$name] = $default;
        }
        return self::$staticSlots[$payloadId][$name];
    }

    public static function staticSlotValue(string $payloadId, string $name, $default = null)
    {
        $slot =& self::staticSlot($payloadId, $name, $default);
        return $slot;
    }

    public static function staticSlotSet(string $payloadId, string $name, $value): void
    {
        $slot =& self::staticSlot($payloadId, $name);
        $slot = $value;
    }
}
