<?php
/**
 * Invitations — standalone pure-logic test runner (no PHPUnit needed).
 *
 * Provides a minimal PHPUnit\Framework\TestCase shim, loads every *Test.php in
 * this directory and runs each public test* method:
 *   php tests/run.php
 * On a box with PHPUnit installed, `phpunit --no-configuration tests/` runs the
 * same files — they are genuine TestCase subclasses.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        class AssertionFailed extends \RuntimeException {}

        abstract class TestCase
        {
            public static function assertTrue($c, string $m = ''): void
            { if ($c !== true) { throw new AssertionFailed($m ?: 'assertTrue failed'); } }

            public static function assertFalse($c, string $m = ''): void
            { if ($c !== false) { throw new AssertionFailed($m ?: 'assertFalse failed'); } }

            public static function assertSame($e, $a, string $m = ''): void
            { if ($e !== $a) { throw new AssertionFailed($m ?: 'assertSame failed: expected '
                . var_export($e, true) . ' got ' . var_export($a, true)); } }

            public static function assertNotSame($e, $a, string $m = ''): void
            { if ($e === $a) { throw new AssertionFailed($m ?: 'assertNotSame failed'); } }

            public static function assertStringContainsString(string $n, string $h, string $m = ''): void
            { if (!str_contains($h, $n)) { throw new AssertionFailed($m ?: "expected to find '{$n}' in '{$h}'"); } }

            public static function assertStringNotContainsString(string $n, string $h, string $m = ''): void
            { if (str_contains($h, $n)) { throw new AssertionFailed($m ?: "did NOT expect '{$n}' in '{$h}'"); } }

            public static function assertMatchesRegularExpression(string $re, string $s, string $m = ''): void
            { if (!preg_match($re, $s)) { throw new AssertionFailed($m ?: "expected {$s} to match {$re}"); } }

            public static function assertNull($c, string $m = ''): void
            { if ($c !== null) { throw new AssertionFailed($m ?: 'assertNull failed: ' . var_export($c, true)); } }

            public static function assertNotNull($c, string $m = ''): void
            { if ($c === null) { throw new AssertionFailed($m ?: 'assertNotNull failed'); } }

            public static function assertCount(int $n, $a, string $m = ''): void
            { if (\count($a) !== $n) { throw new AssertionFailed($m ?: "assertCount failed: expected {$n} got " . \count($a)); } }

            public static function assertEquals($e, $a, string $m = ''): void
            { if ($e != $a) { throw new AssertionFailed($m ?: 'assertEquals failed: expected '
                . var_export($e, true) . ' got ' . var_export($a, true)); } }

            public static function assertArrayHasKey($k, array $a, string $m = ''): void
            { if (!\array_key_exists($k, $a)) { throw new AssertionFailed($m ?: "missing key {$k}"); } }
        }
    }
}

namespace {
    $dir   = __DIR__;
    $files = glob($dir . '/*Test.php') ?: [];
    foreach ($files as $f) { require_once $f; }

    $pass = 0; $fail = 0; $failures = [];
    foreach (get_declared_classes() as $class) {
        if (!is_subclass_of($class, \PHPUnit\Framework\TestCase::class)) { continue; }
        $rc = new ReflectionClass($class);
        if ($rc->isAbstract() || dirname((string) $rc->getFileName()) !== $dir) { continue; }
        echo "\n" . $rc->getShortName() . "\n";
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'test')) { continue; }
            $instance = $rc->newInstance();
            try {
                $method->invoke($instance);
                echo "  ok   " . $method->getName() . "\n";
                $pass++;
            } catch (Throwable $e) {
                echo "  FAIL " . $method->getName() . " — " . $e->getMessage() . "\n";
                $failures[] = $rc->getShortName() . '::' . $method->getName() . ' — ' . $e->getMessage();
                $fail++;
            }
        }
    }
    echo "\nTests: " . ($pass + $fail) . "  Passed: {$pass}  Failed: {$fail}\n";
    exit($fail === 0 ? 0 : 1);
}
