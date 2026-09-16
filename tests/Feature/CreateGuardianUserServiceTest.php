<?php

namespace Tests\Feature;

use App\Services\Auth\CreateGuardianUserService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class CreateGuardianUserServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->timestamps();
        });
    }

    public function test_it_creates_guardian_user_with_name_and_email_in_the_correct_columns(): void
    {
        $user = (new CreateGuardianUserService)->execute(
            name: 'Guardian Test',
            email: 'guardian@example.com',
            phone: '081234567890'
        );

        $this->assertSame('Guardian Test', $user->name);
        $this->assertSame('guardian@example.com', $user->email);
        $this->assertSame('guardian', $user->role);
        $this->assertTrue(Hash::check('081234567890', $user->password));
    }

    public function test_it_rejects_reversed_name_and_email_arguments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CreateGuardianUserService)->execute(
            name: 'guardian@example.com',
            email: 'Guardian Test',
            phone: '081234567890'
        );
    }
}
