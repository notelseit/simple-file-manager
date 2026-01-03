# simple-file-manager

A lightweight, single-file **PHP File Manager**, modernized and hardened for **PHP 8.x** environments.

Originally created by John Campbell, this version has been **patched and updated** to fix deprecated features, improve security, and ensure compatibility with modern servers (PHP-FPM, nginx, aaPanel, etc.).

---

## ✨ Features

- 📄 **Single PHP file** – no external assets, no build steps
- ⚡ **AJAX-based interface** – fast, responsive, back-button friendly
- 🖱️ **Drag & Drop uploads** (when directory is writable)
- 🌍 **Unicode / UTF-8 filenames support**
- 📱 **Usable on tablets (iPad compatible UI)**
- 🛡️ **XSRF protection**
- 🔐 **Optional password protection**
- 📂 **Recursive delete with permission checks**
- ⬇️ **Secure file download handling**
- 🎨 Minimal, clean UI (Dropbox-style, not Explorer-style)

---

## 🆕 What’s New in the Updated Version

This fork includes important improvements over the original project:

### Backend (PHP)
- ✅ Full **PHP 8.0 / 8.1 / 8.2 compatibility**
- ✅ Fixed deprecated functions and warnings
- ✅ Hardened **path traversal protection**
- ✅ Improved **XSRF validation**
- ✅ Safer file upload handling
- ✅ Fixed `mime_content_type()` fallback
- ✅ Fixed recursive delete permission checks
- ✅ Removed debug output (`var_dump`)
- ✅ Proper HTTP headers and JSON responses

### Frontend (JavaScript)
- ✅ Removed deprecated jQuery `.live()`
- ✅ Compatible with newer jQuery versions
- ✅ Fixed invalid HTML markup
- ✅ Improved event handling

---

## 🚀 Installation

1. Copy `index.php` into a directory on your web server
2. Make sure PHP is enabled (PHP 8.x recommended)
3. Open the file in your browser

```bash
cp index.php /var/www/html/files/
