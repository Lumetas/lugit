<?php

namespace Tests\Unit;

use Lugit\Password;
use PHPUnit\Framework\TestCase;

class PasswordTest extends TestCase
{
    public function testHashReturnsArgon2idString(): void
    {
        $hash = Password::hash('test123');
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testVerifyWithArgon2idHash(): void
    {
        $hash = Password::hash('correct');
        $this->assertTrue(Password::verify('correct', $hash));
        $this->assertFalse(Password::verify('wrong', $hash));
    }

    public function testVerifyWithLegacySha256Hash(): void
    {
        $password = 'legacy_pass';
        $shaHash = hash('sha256', $password);
        $this->assertTrue(Password::verify($password, $shaHash));
        $this->assertFalse(Password::verify('wrong', $shaHash));
    }

    public function testNeedsRehashReturnsFalseForFreshHash(): void
    {
        $hash = Password::hash('test');
        $this->assertFalse(Password::needsRehash($hash));
    }

    public function testNeedsRehashReturnsTrueForSha256(): void
    {
        $shaHash = hash('sha256', 'test');
        $result = Password::needsRehash($shaHash);

        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertTrue($result);
        }
    }

    public function testVerifyRejectsGibberishHash(): void
    {
        $this->assertFalse(Password::verify('test', 'not_a_hash_either'));
    }

    public function testVerifyEmptyPassword(): void
    {
        $hash = Password::hash('');
        $this->assertTrue(Password::verify('', $hash));
    }
}
