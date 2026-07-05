# CV Maker

A web-based CV builder that lets you fill in your details, preview your CV, and download it as a PDF or DOCX file.

---

## Requirements

Make sure you have the following installed before you begin:

- [XAMPP](https://www.apachefriends.org/) (or any stack with PHP 8.1+ and MySQL 5.7.8+)
- [Composer](https://getcomposer.org/)
- [Git](https://git-scm.com/)

---

## Setup Steps

### 1. Clone the repository

Open a terminal and run:

```bash
git clone https://github.com/your-username/CVMaker.git
```

Then navigate into the project folder:

```bash
cd CVMaker
```

> Replace `your-username` with the actual GitHub username or organisation.

---

### 2. Install PHP dependencies

```bash
composer install
```

This installs `dompdf` (PDF generation) and `phpword` (DOCX generation) into the `vendor/` folder.

---

### 3. Create the database

Start **XAMPP** and make sure the **Apache** and **MySQL** services are running.

Then import the schema to create the database and table:

**Option A — phpMyAdmin**
1. Open `http://localhost/phpmyadmin`
2. Click **Import**
3. Choose `schema.sql` from the project folder and click **Go**

**Option B — Command line**
```bash
mysql -u root -p < schema.sql
```

This creates a database called `cv_maker` with the `cv_records` table.

---

### 4. Configure environment variables

Create a `.env` file in the project root by copying the example below:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=cv_maker
DB_USER=root
DB_PASS=
```

Update `DB_USER` and `DB_PASS` to match your MySQL credentials. Leave `DB_PASS` empty if your local MySQL has no root password (default XAMPP setup).

> `db.php` is intentionally excluded from version control. The `.env` file holds your credentials and should never be committed either.

---

### 5. Run the application

**Option A — XAMPP (recommended)**

Place the project folder inside XAMPP's `htdocs` directory:

```
C:\xampp\htdocs\CVMaker\
```

Then open your browser and visit:

```
http://localhost/CVMaker/
```

**Option B — PHP built-in server**

From the project root, run:

```bash
php -S localhost:8000 router.php
```

Then open:

```
http://localhost:8000
```

---

## Running Tests

The project uses PHPUnit. To run the test suite:

```bash
./vendor/bin/phpunit
```

Tests use an in-memory SQLite database so no MySQL connection is needed for testing.

---

## Project Structure

```
CVMaker/
├── api.php          # REST API endpoints (save, load, delete, preview)
├── client.js        # Frontend form logic
├── db.php           # Database connection factory (not in Git)
├── download.php     # PDF / DOCX download handler
├── index.html       # Main application page
├── preview.php      # CV preview page
├── router.php       # Router for PHP built-in server
├── schema.sql       # Database schema
├── styles.css       # Application styles
├── template.php     # CV HTML template
├── composer.json    # PHP dependencies
├── .env             # Environment variables (not in Git)
└── tests/           # PHPUnit test suite
```

---

## Troubleshooting

**Blank page or "Database connection failed"**
- Check that MySQL is running in XAMPP.
- Verify your `.env` values match your MySQL setup.

**`vendor/` folder missing**
- Run `composer install` to download dependencies.

**PDF/DOCX download not working**
- Make sure `composer install` completed without errors — dompdf and phpword must be present.

**Toggle sections not responding**
- Open the browser console and check for JavaScript errors.
- Make sure you are accessing the app through a server (XAMPP or PHP built-in), not by opening the HTML file directly.
