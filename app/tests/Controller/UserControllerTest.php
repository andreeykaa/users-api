<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class UserControllerTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;

    // This method is executed BEFORE EACH test
    protected function setUp(): void
    {
        // Create a test HTTP client
        $this->client = static::createClient();

        // Get the EntityManager to work with the database
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Clear the table before each test
        // so that tests do not affect each other
        $this->em->getConnection()->executeStatement('DELETE FROM users');
        $this->em->getConnection()->executeStatement('ALTER TABLE users AUTO_INCREMENT = 1');
    }

    // =========================================================
    // HELPER METHODS
    // =========================================================

    /**
     * Creates a user directly in the database and returns its data.
     * This method is used to prepare data for a test.
     */
    private function createUserInDb(string $login, string $phone, string $pass, string $role = 'user'): array
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO users (login, phone, pass, role) VALUES (?, ?, ?, ?)',
            [$login, $phone, $pass, $role]
        );

        $id = (int) $this->em->getConnection()->lastInsertId();

        return ['id' => $id, 'login' => $login, 'phone' => $phone, 'pass' => $pass, 'role' => $role];
    }

    /**
     * Gets a JWT token for a user.
     * This method is used before every request that requires authorization.
     */
    private function getToken(string $login, string $pass): string
    {
        $this->client->request(
            'POST',
            '/v1/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['login' => $login, 'pass' => $pass])
        );

        $response = $this->client->getResponse();

        // Check that authorization was successful
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        // Check that the response contains a valid token
        $this->assertIsArray($data);
        $this->assertArrayHasKey('token', $data);
        $this->assertIsString($data['token']);
        $this->assertNotEmpty($data['token']);

        return $data['token'];
    }

    /**
     * Sends an HTTP request with a JWT token.
     */
    private function requestWithToken(string $method, string $url, string $token, array $body = []): Response
    {
        $this->client->request(
            $method,
            $url,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            $body ? json_encode($body) : null
        );

        return $this->client->getResponse();
    }

    // =========================================================
    // TESTS WITHOUT AUTHORIZATION
    // =========================================================

    public function testRequestWithoutTokenReturns401(): void
    {
        $this->client->request('GET', '/v1/api/users?id=1');

        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // =========================================================
    // GET TESTS
    // =========================================================

    public function testGetReturnsUserSuccessfully(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        $user = $this->createUserInDb('john', '99988877', 'pass1234', 'user');

        $response = $this->requestWithToken('GET', '/v1/api/users?id=' . $user['id'], $token);

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('john', $data['login']);
        $this->assertSame('99988877', $data['phone']);
    }

    public function testGetReturns404WhenUserNotFound(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        $response = $this->requestWithToken('GET', '/v1/api/users?id=99999', $token);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testGetReturnsMissingIdError(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        $response = $this->requestWithToken('GET', '/v1/api/users', $token);

        $this->assertSame(400, $response->getStatusCode());
    }

    // =========================================================
    // POST TESTS
    // =========================================================

    public function testPostCreatesUserSuccessfully(): void
    {
        // Preparation: create a root user and get a token
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        // Action: send a POST request
        $response = $this->requestWithToken('POST', '/v1/api/users', $token, [
            'login' => 'john',
            'phone' => '99988877',
            'pass'  => 'pass1234',
        ]);

        // Check: status code is 201 and the response contains correct data
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('john', $data['login']);
        $this->assertSame('99988877', $data['phone']);
        $this->assertSame('pass1234', $data['pass']);
    }

    public function testPostValidationFailsWhenLoginTooLong(): void
    {
        // Create a root user because only root can create users
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        // Send a login value that is longer than the allowed 8 characters
        $response = $this->requestWithToken('POST', '/v1/api/users', $token, [
            'login' => 'toolonglogin', // more than 8 characters
            'phone' => '99988877',
            'pass'  => 'pass1234',
        ]);

        $this->assertSame(422, $response->getStatusCode());

        // Check that the validation error is related to the login field
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('login', $data['error']);
    }

    public function testPostValidationFailsWhenPhoneTooLong(): void
    {
        // Create a root user because only root can create users
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        // Send a phone value that is longer than the allowed 8 characters
        $response = $this->requestWithToken('POST', '/v1/api/users', $token, [
            'login' => 'john',
            'phone' => '999888777', // more than 8 characters
            'pass'  => 'pass1234',
        ]);

        $this->assertSame(422, $response->getStatusCode());

        // Check that the validation error is related to the phone field
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('phone', $data['error']);
    }

    public function testPostValidationFailsWhenPassTooLong(): void
    {
        // Create a root user because only root can create users
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        // Send a password value that is longer than the allowed 8 characters
        $response = $this->requestWithToken('POST', '/v1/api/users', $token, [
            'login' => 'john',
            'phone' => '99988877',
            'pass'  => 'pass12345', // more than 8 characters
        ]);

        $this->assertSame(422, $response->getStatusCode());

        // Check that the validation error is related to the pass field
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('pass', $data['error']);
    }

    public function testPostReturnsDuplicateError(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        // Create the user for the first time
        $this->requestWithToken('POST', '/v1/api/users', $token, [
            'login' => 'john',
            'phone' => '99988877',
            'pass'  => 'pass1234',
        ]);

        // Try to create another user with the same login and password
        $response = $this->requestWithToken('POST', '/v1/api/users', $token, [
            'login' => 'john',
            'phone' => '11111111',
            'pass'  => 'pass1234',
        ]);

        $this->assertSame(409, $response->getStatusCode());
    }

    // =========================================================
    // PUT TESTS
    // =========================================================

    public function testPutUpdatesUserSuccessfully(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        $user = $this->createUserInDb('john', '99988877', 'pass1234', 'user');

        $response = $this->requestWithToken('PUT', '/v1/api/users', $token, [
            'id'    => $user['id'],
            'login' => 'john',
            'phone' => '11122233',
            'pass'  => 'newpass1',
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        // Check that the response contains the correct user id
        $this->assertSame($user['id'], $data['id']);

        // Check that the user was really updated in the database
        $updatedUser = $this->em->getConnection()->fetchAssociative(
            'SELECT id, login, phone, pass FROM users WHERE id = ?',
            [$user['id']]
        );

        $this->assertIsArray($updatedUser);
        $this->assertSame('john', $updatedUser['login']);
        $this->assertSame('11122233', $updatedUser['phone']);
        $this->assertSame('newpass1', $updatedUser['pass']);
    }

    public function testPutReturns400WhenIdIsMissing(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        // Send a PUT request without the required id field
        $response = $this->requestWithToken('PUT', '/v1/api/users', $token, [
            'login' => 'john',
            'phone' => '11122233',
            'pass'  => 'newpass1',
        ]);

        $this->assertSame(400, $response->getStatusCode());

        // Check that the response says which field is missing
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Missing required fields', $data['error']);
        $this->assertContains('id', $data['fields']);
    }

    public function testPutReturns400WhenLoginIsMissing(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        $user = $this->createUserInDb('john', '99988877', 'pass1234', 'user');

        // Send a PUT request without the required login field
        $response = $this->requestWithToken('PUT', '/v1/api/users', $token, [
            'id'    => $user['id'],
            'phone' => '11122233',
            'pass'  => 'newpass1',
        ]);

        $this->assertSame(400, $response->getStatusCode());

        // Check that the response says which field is missing
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Missing required fields', $data['error']);
        $this->assertContains('login', $data['fields']);
    }

    public function testPutReturns400WhenPhoneIsMissing(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        $user = $this->createUserInDb('john', '99988877', 'pass1234', 'user');

        // Send a PUT request without the required phone field
        $response = $this->requestWithToken('PUT', '/v1/api/users', $token, [
            'id'    => $user['id'],
            'login' => 'john',
            'pass'  => 'newpass1',
        ]);

        $this->assertSame(400, $response->getStatusCode());

        // Check that the response says which field is missing
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Missing required fields', $data['error']);
        $this->assertContains('phone', $data['fields']);
    }

    public function testPutReturns400WhenPassIsMissing(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        $user = $this->createUserInDb('john', '99988877', 'pass1234', 'user');

        // Send a PUT request without the required pass field
        $response = $this->requestWithToken('PUT', '/v1/api/users', $token, [
            'id'    => $user['id'],
            'login' => 'john',
            'phone' => '11122233',
        ]);

        $this->assertSame(400, $response->getStatusCode());

        // Check that the response says which field is missing
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Missing required fields', $data['error']);
        $this->assertContains('pass', $data['fields']);
    }

    // =========================================================
    // DELETE TESTS
    // =========================================================

    public function testDeleteRemovesUserSuccessfully(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        $user = $this->createUserInDb('john', '99988877', 'pass1234', 'user');

        $response = $this->requestWithToken('DELETE', '/v1/api/users', $token, [
            'id' => $user['id'],
        ]);

        $this->assertSame(204, $response->getStatusCode());

        // Check that the deleted user is no longer available
        $checkResponse = $this->requestWithToken(
            'GET',
            '/v1/api/users?id=' . $user['id'],
            $token
        );

        $this->assertSame(404, $checkResponse->getStatusCode());
    }

    // =========================================================
    // ACCESS RIGHTS TESTS
    // =========================================================

    public function testUserRoleCannotCreateUser(): void
    {
        // Create a regular user and get their token
        $this->createUserInDb('simple', '11111111', 'pass1111', 'user');
        $userToken = $this->getToken('simple', 'pass1111');

        // Regular users are not allowed to create new users
        $response = $this->requestWithToken('POST', '/v1/api/users', $userToken, [
            'login' => 'john',
            'phone' => '99988877',
            'pass'  => 'pass1234',
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserRoleCannotDelete(): void
    {
        // Create a root user and a regular user
        $root = $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $this->createUserInDb('simple', '11111111', 'pass1111', 'user');
        $userToken = $this->getToken('simple', 'pass1111');

        // The regular user tries to delete the root user and should get 403
        $response = $this->requestWithToken('DELETE', '/v1/api/users', $userToken, [
            'id' => $root['id'],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserRoleCannotReadOtherUser(): void
    {
        $root = $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $this->createUserInDb('simple', '11111111', 'pass1111', 'user');
        $userToken = $this->getToken('simple', 'pass1111');

        // The regular user tries to read the root user's data and should get 403
        $response = $this->requestWithToken('GET', '/v1/api/users?id=' . $root['id'], $userToken);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserRoleCannotUpdateOtherUser(): void
    {
        // Create a root user that will be the target of the update
        $root = $this->createUserInDb('admin', '12345678', 'secret12', 'root');

        // Create a regular user and get their token
        $this->createUserInDb('simple', '11111111', 'pass1111', 'user');
        $userToken = $this->getToken('simple', 'pass1111');

        // The regular user tries to update another user's profile
        $response = $this->requestWithToken('PUT', '/v1/api/users', $userToken, [
            'id'    => $root['id'],
            'login' => 'admin',
            'phone' => '12345678',
            'pass'  => 'secret12',
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserRoleCanReadOwnProfile(): void
    {
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $user = $this->createUserInDb('simple', '11111111', 'pass1111', 'user');
        $userToken = $this->getToken('simple', 'pass1111');

        // The regular user reads their own profile and should get 200
        $response = $this->requestWithToken('GET', '/v1/api/users?id=' . $user['id'], $userToken);

        $this->assertSame(200, $response->getStatusCode());
    }

    // =========================================================
    // JSON ERROR TESTS
    // =========================================================

    public function testUnknownApiRouteReturnsJson404WithoutTrace(): void
    {
        // Send a request to a non-existing API route
        $this->client->request('GET', '/v1/api/unknown-route');

        $response = $this->client->getResponse();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString(
            'application/json',
            $response->headers->get('Content-Type') ?? ''
        );

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertSame('Not Found', $data['error']);
        $this->assertSame(404, $data['status']);

        // The response must not expose technical debug information
        $this->assertArrayNotHasKey('trace', $data);
        $this->assertArrayNotHasKey('exception', $data);
    }

    public function testWrongHttpMethodReturnsJson405WithoutTrace(): void
    {
        // Send a request with an unsupported HTTP method
        $this->client->request('PATCH', '/v1/api/users');

        $response = $this->client->getResponse();

        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString(
            'application/json',
            $response->headers->get('Content-Type') ?? ''
        );

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertSame('Method Not Allowed', $data['error']);
        $this->assertSame(405, $data['status']);

        // The response must not contain a stack trace
        $this->assertArrayNotHasKey('trace', $data);
        $this->assertArrayNotHasKey('exception', $data);
    }

    public function testPostWithInvalidJsonReturns400(): void
    {
        // Create a root user because only root can create users
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        // Send broken JSON in the request body
        $this->client->request(
            'POST',
            '/v1/api/users',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            '{"login": "john", "phone": "99988877", "pass": '
        );

        $response = $this->client->getResponse();

        $this->assertSame(400, $response->getStatusCode());

        // Check that the API returns a clear JSON error
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid JSON body', $data['error']);
    }

    public function testPutWithInvalidJsonReturns400(): void
    {
        // Create a root user because root can update any user
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        // Send broken JSON in the request body
        $this->client->request(
            'PUT',
            '/v1/api/users',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            '{"id": 1, "login": "john", "phone": '
        );

        $response = $this->client->getResponse();

        $this->assertSame(400, $response->getStatusCode());

        // Check that the API returns a clear JSON error
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid JSON body', $data['error']);
    }

    public function testDeleteWithInvalidJsonReturns400(): void
    {
        // Create a root user because only root can delete users
        $this->createUserInDb('admin', '12345678', 'secret12', 'root');
        $token = $this->getToken('admin', 'secret12');

        // Send broken JSON in the request body
        $this->client->request(
            'DELETE',
            '/v1/api/users',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            '{"id": '
        );

        $response = $this->client->getResponse();

        $this->assertSame(400, $response->getStatusCode());

        // Check that the API returns a clear JSON error
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid JSON body', $data['error']);
    }
}
