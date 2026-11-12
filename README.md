# PerpusKu (PWD 2026)
Monorepo: `backend/` (PHP 8.3 REST API + JWT) dan `frontend/` (React + Vite).

## Backend
1. Jalankan `backend/database/schema.sql` di Supabase (SQL Editor).
2. Buka `backend/.env`, ganti `DB_URL` dengan Connection String Supabase (JWT_SECRET sudah terisi).
3. `php database/seed.php` (membuat akun admin@perpusku.test / Admin12345 + data contoh).
4. `php -S localhost:8000 -t public public/index.php`

Butuh ekstensi PHP `pdo_pgsql`. Tanpa Composer.

## Frontend
`cd frontend && cp .env.example .env && npm install && npm run dev` (http://localhost:5173)
