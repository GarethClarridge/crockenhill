<?php

namespace Tests\Unit;

use App\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function test_user_name_is_fillable()
    {
        $user = new User();
        $this->assertTrue($user->isFillable('name'));
    }
}
