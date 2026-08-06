# Travel Agency MVP — RESTful API Backend

This repository contains the currently implemented backend API for a travel agency platform. It is a framework-less PHP 8 application using PDO, JWT authentication, and a front-controller router.

## What is implemented now

The current codebase includes the core modules for:

- health checks
- user registration and login
- JWT-protected profile access
- public package listing and detail views
- admin/agent package creation
- schedule and itinerary creation
- transactional bookings with seat locking
- secure image upload handling

## Tech stack

- PHP 8+
- MySQL / MariaDB with PDO
- Firebase JWT for authentication
- Apache URL rewriting via `.htaccess`
- JSON responses handled by a custom response helper

## Project structure

```text
travel-agency-backend/
├── config/
│   ├── cors.php
│   ├── database.php
│   └── env.php
├── controllers/
│   ├── AuthController.php
│   ├── BookingController.php
│   ├── HealthController.php
│   ├── PackageController.php
│   └── PhotoController.php
├── helpers/
│   ├── JWT.php
│   └── Response.php
├── middleware/
│   └── auth.php
├── uploads/
├── .env
├── .htaccess
├── composer.json
├── index.php
└── PROJECT_MANIFEST.md
```

## Requirements

- PHP 8+
- Composer
- MySQL server
- PDO MySQL extension enabled
- Fileinfo extension enabled for upload validation
- Apache with URL rewriting enabled if you are serving through Apache

## Installation

1. Install PHP dependencies:

   ```bash
   composer install
   ```

2. Create a `.env` file in the project root.

3. Configure your database and JWT settings.

4. Create the required MySQL tables.

5. Start the project through Apache or your local PHP server setup.

## Environment variables

Example `.env`:

```ini
APP_ENV=development
APP_URL=http://localhost/travel-agency-backend

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=travel_agency_db
DB_USER=root
DB_PASS=secret

JWT_SECRET=super_secret_jwt_key_must_be_32_bytes_long_minimum!
JWT_EXPIRATION=86400
```

## Response format

The API returns JSON responses in this form:

```json
{
  "timestamp": "2026-08-06T14:00:00+00:00",
  "status": "success",
  "message": "Operation response message",
  "data": {},
  "errors": null,
  "meta": null
}
```

Note: the current implementation does not return a top-level `code` field.

## API endpoints

### Health

- `GET /api/health`
  - Public
  - Checks API and database connectivity

### Authentication

- `POST /api/auth/register`
  - Public
  - Body: `full_name`, `email`, `password`, `phone`

- `POST /api/auth/login`
  - Public
  - Body: `email`, `password`

- `GET /api/auth/me`
  - Protected
  - Requires `Authorization: Bearer <token>`

### Packages

- `GET /api/packages`
  - Public
  - Query params: `destination_id`, `search`, `min_price`, `max_price`, `page`, `limit`

- `GET /api/packages/{id}`
  - Public
  - Returns package detail, schedules, itineraries, and approved photos

### Admin / Agent package management

- `POST /api/admin/packages`
  - Protected for `admin` and `agent`

- `POST /api/admin/packages/{id}/schedules`
  - Protected for `admin` and `agent`

- `POST /api/admin/packages/{id}/itineraries`
  - Protected for `admin` and `agent`

### Bookings

- `POST /api/bookings`
  - Protected
  - Creates a booking transaction with passenger details

- `GET /api/bookings`
  - Protected
  - Travelers see their own bookings; admins/agents see all bookings

- `GET /api/bookings/{id}`
  - Protected
  - Travelers can view their own booking; admins/agents can view any booking

### Photo upload

- `POST /api/photos/upload`
  - Protected
  - Multipart form fields: `photo`, `package_id`, `caption`, `photo_type`

## Example curl requests

### Health check

```bash
curl -X GET http://localhost/travel-agency-backend/api/health
```

### Register a user

```bash
curl -X POST http://localhost/travel-agency-backend/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"full_name":"Jane Doe","email":"jane@example.com","password":"Password123!","phone":"+1234567890"}'
```

### Login

```bash
curl -X POST http://localhost/travel-agency-backend/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","password":"Password123!"}'
```

### Get authenticated profile

```bash
curl -X GET http://localhost/travel-agency-backend/api/auth/me \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### List packages

```bash
curl -X GET "http://localhost/travel-agency-backend/api/packages?page=1&limit=10"
```

### Upload a photo

```bash
curl -X POST http://localhost/travel-agency-backend/api/photos/upload \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -F "photo=@/path/to/image.jpg" \
  -F "package_id=1" \
  -F "caption=Sample photo" \
  -F "photo_type=gallery"
```

## Database schema

The backend expects tables such as:

- `users`
- `destinations`
- `packages`
- `package_schedules`
- `package_itineraries`
- `package_photos`
- `bookings`
- `booking_passengers`
- `payments`

A basic SQL schema is expected for these entities. The project manifest contains additional planned modules, but the current repository only includes the core API implemented above.

## Notes

- Uploads are stored in the local `uploads/` directory and the controller will create the folder if needed.
- Protected routes require a valid JWT bearer token.
- The router currently contains a duplicate `POST /api/bookings` route entry in `index.php`; the endpoint still works, but this can be cleaned up later.
