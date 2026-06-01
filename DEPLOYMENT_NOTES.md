# Deployment Notes

## ✅ Safe Deployment Schema

The `deployment_schema.sql` file is **100% SAFE** and will NOT delete any existing data.

### What It Does:
- ✅ Creates database if it doesn't exist (`CREATE DATABASE IF NOT EXISTS`)
- ✅ Creates tables only if they don't exist (`CREATE TABLE IF NOT EXISTS`)
- ✅ Adds sample data only if not already present (`INSERT IGNORE`)
- ✅ **NO DROP commands** - Your data is safe!

### What It Contains:
- 6 database tables (users, genres, movies, ratings, comments, media)
- 12 movie genres
- 3 sample users (admin, creator, visitor)

### Safe to Run:
- ✅ On fresh database (creates everything)
- ✅ On existing database (skips what exists)
- ✅ Multiple times (won't cause errors)

---

## 📦 Files Overview

### For Submission/Deployment:
- **`deployment_schema.sql`** - Safe schema for instructor/grader
- **`README.md`** - Setup and deployment instructions
- **`TEST_PLAN.md`** - Comprehensive testing guide
- **`PROJECT_REPORT.md`** - Full project documentation

### For Development:
- **`complete_schema.sql`** - Original schema (has DROP commands - use carefully!)
- **`config.php`** - Database configuration
- All PHP application files

---

## 🔑 Default Credentials

After running `deployment_schema.sql`:

**Admin:**
- Email: admin@moviereview.com
- Password: test123

**Creator:**
- Email: creator@example.com
- Password: test123

**Visitor:**
- Email: visitor@example.com
- Password: test123

---

## 🚀 Deployment Steps

### For Instructor/Grader:

1. **Import Schema:**
   ```bash
   mysql -u root -p < deployment_schema.sql
   ```

2. **Configure Database:**
   - Edit `config.php` with database credentials

3. **Start Server:**
   ```bash
   php -S localhost:8000
   ```

4. **Login:**
   - Use credentials above
   - Test all features per TEST_PLAN.md

---

## ⚠️ Important Notes

- **`deployment_schema.sql`** is SAFE - no data loss
- **`complete_schema.sql`** has DROP commands - use only for fresh setup
- Both schemas work with the same application code
- Your movies and data are preserved in your development database

---

## ✨ Features Ready for Testing

- User authentication (login/signup)
- Role-based access (visitor, creator, admin)
- Movie CRUD operations
- Rating system with AJAX
- Comment system with AJAX
- Search and filtering
- Admin panel with reports
- Creator dashboard
- jQuery animations
- Responsive design
