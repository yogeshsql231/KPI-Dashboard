<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the global env() helper defined in config/config.php.
 *
 * The helper reads the real process environment first (which "wins" over the
 * .env file), so these tests drive it through putenv() without depending on a
 * .env file being present.
 */
final class EnvTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/config/config.php';
    }

    private array $touched = [];

    private function setEnv(string $key, string $value): void
    {
        $this->touched[] = $key;
        putenv("$key=$value");
    }

    protected function tearDown(): void
    {
        foreach ($this->touched as $key) {
            putenv($key);
        }
        $this->touched = [];
    }

    public function testReturnsDefaultWhenKeyMissing(): void
    {
        $this->assertSame('fallback', env('DEVIN_TEST_MISSING_KEY', 'fallback'));
        $this->assertNull(env('DEVIN_TEST_MISSING_KEY_2'));
    }

    public function testReadsValueFromEnvironment(): void
    {
        $this->setEnv('DEVIN_TEST_HOST', 'db.internal');
        $this->assertSame('db.internal', env('DEVIN_TEST_HOST'));
    }

    public function testNormalisesTrueLiteral(): void
    {
        $this->setEnv('DEVIN_TEST_FLAG', 'true');
        $this->assertTrue(env('DEVIN_TEST_FLAG'));
    }

    public function testNormalisesFalseLiteralCaseInsensitively(): void
    {
        $this->setEnv('DEVIN_TEST_FLAG', 'FALSE');
        $this->assertFalse(env('DEVIN_TEST_FLAG'));
    }

    public function testNormalisesNullLiteral(): void
    {
        $this->setEnv('DEVIN_TEST_NULL', 'null');
        $this->assertNull(env('DEVIN_TEST_NULL'));
    }

    public function testEmptyStringFallsBackToDefault(): void
    {
        $this->setEnv('DEVIN_TEST_EMPTY', '');
        $this->assertSame('def', env('DEVIN_TEST_EMPTY', 'def'));
    }

    public function testPlainStringPassesThroughUnchanged(): void
    {
        $this->setEnv('DEVIN_TEST_PLAIN', '3306');
        $this->assertSame('3306', env('DEVIN_TEST_PLAIN'));
    }
}
