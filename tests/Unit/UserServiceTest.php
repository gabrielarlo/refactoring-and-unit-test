<?php

namespace Tests\Unit;

use Tests\TestCase;
use DTApi\Models\User;
use App\Enums\UserTypeEnum;
use DTApi\Repository\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testCreateUser()
    {
        $request = [
            'role' => UserTypeEnum::CUSTOMER,
            'name' => 'John Doe',
            'company_id' => '',
            'department_id' => '',
            'email' => 'john@example.com',
            'dob_or_orgid' => '1990-01-01',
            'phone' => '1234567890',
            'mobile' => '0987654321',
            'password' => 'password123',
            'consumer_type' => 'paid',
            'username' => 'johndoe',
        ];

        $user = new User;
        $user_repository = new UserRepository($user);
        $user = $user_repository->createOrUpdate(null, $request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
        // Ensure password is hashed
        $this->assertNotEmpty($user->password);
    }

    public function testUpdateUser()
    {
        // Create a user first
        $user = User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $request = [
            'role' => UserTypeEnum::TRANSLATOR,
            'name' => 'Jane Smith',
            'company_id' => '',
            'department_id' => '',
            'email' => 'jane@example.com',
            'dob_or_orgid' => '',
            'phone' => '',
            'mobile' => '',
        ];

        $user_repository = new UserRepository($user);
        $updatedUser = $user_repository->createOrUpdate($user->id, $request);

        $this->assertInstanceOf(User::class, $updatedUser);
        $this->assertEquals('Jane Smith', $updatedUser->name);
        $this->assertEquals('jane@example.com', $updatedUser->email);
    }

    public function testCreateCompanyAndDepartmentForPaidConsumer()
    {
        // Create a user as a customer with no company_id
        $request = [
            'role' => UserTypeEnum::CUSTOMER,
            'name' => 'Alice Johnson',
            'company_id' => '',
            'consumer_type' => 'paid',
        ];

        $user = new User;
        $user_repository = new UserRepository($user);
        $user = $user_repository->createOrUpdate(null, $request);

        $this->assertDatabaseHas('companies', ['name' => 'Alice Johnson']);
        $this->assertDatabaseHas('departments', ['name' => 'Alice Johnson']);
    }

    public function testAttachTranslatorToUser()
    {
        // Create a user first
        $user = User::factory()->create();

        $request = [
            'role' => UserTypeEnum::TRANSLATOR,
        ];

        $user_repository = new UserRepository($user);
        $updatedUser = $user_repository->createOrUpdate($user->id, $request);

        $this->assertInstanceOf(User::class, $updatedUser);
        $this->assertEquals(UserTypeEnum::TRANSLATOR, $updatedUser->user_type);
        $this->assertEquals($user->id, $updatedUser->translator_id);
        $this->assertEquals($user->name, $updatedUser->translator->name);
        $this->assertEquals($user->email, $updatedUser->translator->email);
    }

    public function testDisableUser()
    {
        // Create a user first
        $user = User::factory()->create(['status' => 1]);

        $request = [
            'role' => UserTypeEnum::CUSTOMER,
            'status' => 0,
        ];

        $user_repository = new UserRepository($user);
        $updatedUser = $user_repository->createOrUpdate($user->id, $request);

        $this->assertEquals(0, $updatedUser->status);
    }
}
