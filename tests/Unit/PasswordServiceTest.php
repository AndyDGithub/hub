<?php

namespace Tests\Unit;

use App\Services\PasswordService;
use RuntimeException;
use Tests\TestCase;

class PasswordServiceTest extends TestCase
{
    public function test_hash_and_verify_round_trip(): void
    {
        $service = new PasswordService;
        $hash = $service->hash('secret-password');

        $this->assertTrue($service->verify('secret-password', $hash));
        $this->assertFalse($service->verify('wrong-password', $hash));
    }

    public function test_long_passwords_differing_after_72_bytes_are_distinguished(): void
    {
        // Sans le HMAC, bcrypt ignorerait tout au-delà de 72 octets.
        $service = new PasswordService;
        $base = str_repeat('a', 80);

        $this->assertFalse($service->verify($base.'X', $service->hash($base.'Y')));
    }

    public function test_changing_the_pepper_invalidates_hashes(): void
    {
        $service = new PasswordService;
        $hash = $service->hash('secret-password');

        config(['hub.passwords.pepper' => str_repeat('z', 64)]);
        $this->assertFalse($service->verify('secret-password', $hash));
    }

    public function test_refuses_to_hash_without_a_pepper(): void
    {
        config(['hub.passwords.pepper' => '']);
        $this->expectException(RuntimeException::class);

        (new PasswordService)->hash('secret-password');
    }
}
