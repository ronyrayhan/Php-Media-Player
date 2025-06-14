**PHP Media Browser
**
A secure, self-hosted media browser for managing and viewing your media collection with favorites support.


**Features
**
🖼️ Media Browsing: View images and videos in a responsive grid layout

📂 Directory Navigation: Browse through nested folders with breadcrumb navigation

❤️ Favorites System: Mark files as favorites for quick access

🎥 Built-in Player: Video player with keyboard controls (← → for navigation)

🔒 Security: CSRF protection and path sanitization

🖼️ Thumbnail Generation: Automatic thumbnails for videos and images

🗑️ File Management: Delete files directly from the interface

**Requirements
**PHP 7.4 or higher

FFmpeg (for video thumbnail generation)

GD Library (for image thumbnail generation)

Web server (Apache/Nginx)

_Installation
_Clone this repository to your web server:

git clone https://github.com/yourusername/php-media-browser.git
cd php-media-browser
_Set up permissions:
_
chmod -R 775 thumbs/
chmod -R 775 media/
chmod -R 775 favorites/
Configure the media root directory in index.php:

php
$mediaRoot = './media'; // Change this to your media directory
Ensure FFmpeg is installed for video thumbnail generation:

sudo apt install ffmpeg  # For Debian/Ubuntu

**Configuration
**
Edit the following variables in index.php to customize your media browser:

php
// Configuration
$mediaRoot = './media'; // Root directory to scan for media files
$favoritesFolder = './favorites'; // Folder for favorites
$allowedExtensions = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'jpg', 'jpeg', 'png', 'gif'];
$thumbnailWidth = 320; // Thumbnail width in pixels
$thumbnailHeight = 180; // Thumbnail height in pixels
$thumbnailQuality = 85; // Thumbnail quality (for JPEG)
Keyboard Shortcuts
When viewing a video:

← Previous media file

→ Next media file

f Toggle favorite

d Delete current file

Esc Close player
