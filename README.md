# Users API

REST API for user management built with PHP 8.4, Symfony 7.2, MySQL 8.4, and JWT authentication.

## Stack

- PHP 8.4
- Symfony 7.2
- MySQL 8.4
- LexikJWTAuthenticationBundle
- Docker / Docker Compose
- PHPUnit

---

## Features

- JWT Bearer authentication
- CRUD operations for users
- Role-based access control
- Global JSON error handling
- Functional API tests

### Roles

| Role | Permissions |
|---|---|
| `root` | Full access |
| `user` | Read and update only own profile |

---

## Requirements

- Docker
- Docker Compose
- Git

---

## Quick Start

### Clone repository

```bash
git clone https://github.com/andreeykaa/users-api.git
cd users-api
```

### Create environment file

```bash
cp app/.env.example app/.env
```

Set values in `app/.env`:

```env
APP_SECRET=secret
JWT_PASSPHRASE=secret
```

### Start containers

```bash
docker compose up -d --build
```

### Install dependencies

```bash
docker compose exec php composer install
```

### Generate JWT keys

```bash
docker compose exec php php bin/console lexik:jwt:generate-keypair --overwrite
```

### Run migrations

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

### Prepare demo data

To test the API manually, the database must contain at least one `root` user.

You can create it manually:

```bash
docker compose exec db mysql -u symfony -psymfony users_api -e "INSERT INTO users (login, phone, pass, role) VALUES ('admin', '12345678', 'secret12', 'root');"
```

Or use the prepared `dump.sql` file:

```bash
docker compose exec -T db mysql -u symfony -psymfony users_api < dump.sql
```

The dump contains demo users:

```text
root user:
login: admin
pass: secret12

regular user:
login: simple
pass: pass1111
```

Use only one option: either create the root user manually or import the dump.

API:

```text
http://localhost:8081
```

---

## Authentication

Public route:

```text
POST /v1/api/auth/login
```

### Request

```bash
curl -X POST http://localhost:8081/v1/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"login":"admin","pass":"secret12"}'
```

### Success Response `200`

```json
{
  "token": "jwt_token"
}
```

Save token to a shell variable:

```bash
export TOKEN="jwt_token"
```

Or directly from the login request:

```bash
export TOKEN=$(curl -s -X POST http://localhost:8081/v1/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"login":"admin","pass":"secret12"}' | jq -r .token)
```

Use token:

```text
Authorization: Bearer jwt_token
```

---

## Manual Testing

### Create user

```bash
curl -X POST http://localhost:8081/v1/api/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"login":"john","phone":"99988877","pass":"pass1234"}'
```

### Success Response `201`

```json
{
  "id": 2,
  "login": "john",
  "phone": "99988877",
  "pass": "pass1234"
}
```

---

### Get user

```bash
curl -X GET "http://localhost:8081/v1/api/users?id=2" \
  -H "Authorization: Bearer $TOKEN"
```

### Success Response `200`

```json
{
  "login": "john",
  "phone": "99988877",
  "pass": "pass1234"
}
```

---

### Update user

```bash
curl -X PUT http://localhost:8081/v1/api/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":2,"login":"john","phone":"11112222","pass":"newpass"}'
```

### Success Response `200`

```json
{
  "id": 2
}
```

---

### Delete user

```bash
curl -X DELETE http://localhost:8081/v1/api/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":2}'
```

### Success Response `204`

```text
Empty response body
```

---

## API

Base route:

```text
/v1/api/users
```

### Validation Rules

Fields:

- `login`
- `phone`
- `pass`

Rules:

- required
- max length: 8
- unique pair: `login + pass`

---

### Endpoints

| Method | Route | Access |
|---|---|---|
| POST | `/v1/api/users` | `root` |
| GET | `/v1/api/users?id={id}` | `root`, own profile for `user` |
| PUT | `/v1/api/users` | `root`, own profile for `user` |
| DELETE | `/v1/api/users` | `root` |

---

## Error Responses

| Code | Description |
|---|---|
| 400 | Invalid request / JSON |
| 401 | Unauthorized |
| 403 | Access denied |
| 404 | Not found |
| 405 | Method not allowed |
| 409 | Duplicate `login + pass` |
| 422 | Validation error |
| 500 | Internal server error |

### Access Denied Example

```json
{
  "error": "Access denied"
}
```

### Validation Error Example

```json
{
  "error": {
    "login": "This value is too long. It should have 8 characters or less."
  }
}
```

### Missing Required Fields Example

```json
{
  "error": "Missing required fields",
  "fields": ["login"]
}
```

### Invalid JSON Example

```json
{
  "error": "Invalid JSON body"
}
```

---

## Tests

The tests use a separate database:

```text
users_api_test
```

### Create test database

```bash
docker compose exec db mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS users_api_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON users_api_test.* TO 'symfony'@'%'; FLUSH PRIVILEGES;"
```

### Configure test environment

```bash
cat > app/.env.test.local <<'EOF'
DATABASE_URL="mysql://symfony:symfony@db:3306/users_api_test?serverVersion=8.4&charset=utf8mb4"
EOF
```

### Run migrations

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

### Run tests

```bash
docker compose exec php php bin/phpunit
```

Expected result:

```text
OK (25 tests, 173 assertions)
```

### Covered Scenarios

- authentication without token
- successful CRUD operations
- validation errors
- duplicate `login + pass`
- invalid JSON body
- missing required fields
- role restrictions
- access denial for чужих users
- global JSON exception handling
- unsupported HTTP methods

---

## Project Structure

```text
users-api/
├── app/
│   ├── src/
│   │   ├── Controller/
│   │   ├── Entity/
│   │   ├── EventSubscriber/
│   │   └── Repository/
│   ├── tests/
│   ├── config/
│   └── migrations/
├── docker/
├── docker-compose.yml
├── Dockerfile
├── dump.sql
└── README.md
```

---

## Notes

This project was created for a test assignment.

Assignment-specific simplifications:

- passwords are stored as plain text
- passwords are returned in API responses
- authentication uses plain text passwords
- unique constraint uses `login + pass`
