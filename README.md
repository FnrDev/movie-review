# Movie Review Application

A PHP-based movie review platform with user authentication, media uploads, and review management.

## Requirements

- PHP >= 7.4
- MySQL/MariaDB
- Apache/Nginx web server (or PHP built-in server for development)
- PHP Extensions:
  - mysqli
  - json
  - session
  - mbstring
  - fileinfo (optional, for better MIME detection)
  - gd (optional, for image processing)

## Setup Instructions

### 1. Install PHP

**macOS (using Homebrew):**
```bash
brew install php@8.1
brew install mysql
```

**Linux (Ubuntu/Debian):**
```bash
sudo apt update
sudo apt install php php-mysqli php-mbstring php-json php-gd
sudo apt install mysql-server
```

### 2. Configure Database

1. Start MySQL server:
   ```bash
   # macOS
   brew services start mysql
   
   # Linux
   sudo systemctl start mysql
   ```

2. Create database and user:
   ```sql
   CREATE DATABASE db202202672;
   CREATE USER 'u202202672'@'localhost' IDENTIFIED BY 'asdASD123!';
   GRANT ALL PRIVILEGES ON db202202672.* TO 'u202202672'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. Import database schema:
   
   **For Fresh Deployment (Instructor/Grader):**
   ```bash
   mysql -u root -p < deployment_schema.sql
   ```
   This creates a clean database with 3 sample users for testing.
   
   **For Development (Existing Database):**
   ```bash
   mysql -u u202202672 -p db202202672 < complete_schema.sql
   ```
   This preserves your existing data.

### 3. Configure Application

1. Copy environment file:
   ```bash
   cp .env.example .env
   ```

2. Update `.env` with your database credentials

3. Ensure upload directory has write permissions:
   ```bash
   chmod 755 uploads/
   ```

### 4. Install Dependencies (Optional)

If using Composer:
```bash
composer install
```

### 5. Run the Application

**Development Server (PHP Built-in):**
```bash
php -S localhost:8000 -c php.ini
```

**Apache:**
- Place project in `/var/www/html/` or configure virtual host
- Ensure `.htaccess` is enabled
- Restart Apache: `sudo systemctl restart apache2`

**Nginx:**
- Configure PHP-FPM
- Set document root to project directory
- Restart Nginx: `sudo systemctl restart nginx`

## Project Structure

```
movie-review/
├── admin/              # Admin panel
├── creator/            # Creator dashboard
├── uploads/            # User uploaded files
├── config.php          # Database configuration
├── index.php           # Homepage
├── login.php           # User login
├── signup.php          # User registration
├── movie.php           # Movie details
├── search.php          # Search functionality
├── ajax_handler.php    # AJAX requests
└── style.css           # Styles
```

## Default Login Credentials

After importing `deployment_schema.sql`, use these credentials:

**Admin Account:**
- Email: `admin@moviereview.com`
- Password: `test123`

**Creator Account:**
- Email: `creator@example.com`
- Password: `test123`

**Visitor Account:**
- Email: `visitor@example.com`
- Password: `test123`

## Features

- User authentication (login/signup)
- Movie reviews and ratings
- Media uploads (images, videos, audio)
- Search functionality
- Admin panel
- Creator dashboard
- AJAX-powered interactions

## Security Notes

- Change default database credentials in `config.php`
- Use HTTPS in production
- Enable `session.cookie_secure` in production
- Validate and sanitize all user inputs
- Keep PHP and dependencies updated

## Troubleshooting

**Database connection failed:**
- Verify MySQL is running
- Check credentials in `config.php`
- Ensure database exists

**Upload errors:**
- Check `upload_max_filesize` in `php.ini`
- Verify `uploads/` directory permissions
- Check disk space

**Session issues:**
- Verify session directory is writable
- Check `session.save_path` in `php.ini`


