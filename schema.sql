CREATE TABLE users (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nama VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'anggota' CHECK (role IN ('admin','pustakawan','anggota')),
  created_at TIMESTAMP NOT NULL DEFAULT now(), updated_at TIMESTAMP NOT NULL DEFAULT now(), deleted_at TIMESTAMP NULL);
CREATE TABLE categories (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nama VARCHAR(100) NOT NULL UNIQUE, deskripsi TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT now(), updated_at TIMESTAMP NOT NULL DEFAULT now());
CREATE TABLE books (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  category_id BIGINT NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
  created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
  judul VARCHAR(200) NOT NULL, penulis VARCHAR(150) NOT NULL, penerbit VARCHAR(150),
  tahun_terbit SMALLINT CHECK (tahun_terbit BETWEEN 1900 AND 2100),
  isbn VARCHAR(20) NOT NULL UNIQUE, stok INT NOT NULL DEFAULT 0 CHECK (stok >= 0),
  created_at TIMESTAMP NOT NULL DEFAULT now(), updated_at TIMESTAMP NOT NULL DEFAULT now());
CREATE TABLE loans (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  book_id BIGINT NOT NULL REFERENCES books(id) ON DELETE RESTRICT,
  tgl_pinjam DATE NOT NULL DEFAULT CURRENT_DATE, tgl_jatuh_tempo DATE NOT NULL, tgl_kembali DATE,
  status VARCHAR(20) NOT NULL DEFAULT 'dipinjam' CHECK (status IN ('dipinjam','dikembalikan','terlambat')),
  denda INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT now(), updated_at TIMESTAMP NOT NULL DEFAULT now());
CREATE INDEX idx_books_category ON books(category_id);
CREATE INDEX idx_loans_user ON loans(user_id);
CREATE INDEX idx_loans_book ON loans(book_id);
CREATE INDEX idx_loans_status ON loans(status);
