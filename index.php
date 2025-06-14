<?php
// Configuration
$mediaRoot = './media'; // Root directory to scan for media files
$favoritesFolder = './favorites'; // Folder for favorites
$allowedExtensions = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'jpg', 'jpeg', 'png', 'gif'];
$thumbnailWidth = 320; // Thumbnail width in pixels
$thumbnailHeight = 180; // Thumbnail height in pixels
$thumbnailQuality = 85; // Thumbnail quality (for JPEG)

// Start session for CSRF protection
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Security check - prevent directory traversal
function sanitizePath($path) {
    // Remove all directory traversal attempts
    $path = str_replace(['../', '..\\', '~/'], '', $path);
    // Remove null bytes
    $path = str_replace("\0", '', $path);
    // Normalize slashes
    $path = str_replace('\\', '/', $path);
    // Remove duplicate slashes
    $path = preg_replace('/\/+/', '/', $path);
    return trim($path, '/');
}

// Get current directory from request
$currentDir = isset($_GET['dir']) ? sanitizePath($_GET['dir']) : '';

// Determine full path and validate
$fullPath = realpath($mediaRoot . '/' . $currentDir);

// Allow access to favorites folder
$favoritesRelativePath = ltrim(str_replace($mediaRoot, '', $favoritesFolder), '/');
if ($currentDir === 'favorites' || $currentDir === $favoritesRelativePath) {
    $fullPath = realpath($favoritesFolder);
}

// Verify the path is within our allowed roots
if (!$fullPath || (strpos($fullPath, realpath($mediaRoot)) !== 0 && 
    strpos($fullPath, realpath($favoritesFolder)) !== 0)) {
    die('Access denied: Invalid directory path');
}

// Handle file deletion
if (isset($_GET['delete']) && isset($_GET['file'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        die('Invalid CSRF token');
    }
    
    $fileToDelete = sanitizePath($_GET['file']);
    $deletePath = realpath($fullPath . '/' . $fileToDelete);
    
    // Verify the file to delete is within our allowed roots
    if ($deletePath && (strpos($deletePath, realpath($mediaRoot)) === 0 || 
        strpos($deletePath, realpath($favoritesFolder)) === 0) && 
        file_exists($deletePath)) {
        unlink($deletePath);
        // Also delete thumbnail if exists
        $thumbnailPath = 'thumbs/' . md5($deletePath) . '.jpg';
        if (file_exists($thumbnailPath)) {
            unlink($thumbnailPath);
        }
        header('Location: ?dir=' . urlencode($currentDir));
        exit;
    }
}

// Handle favorite/unfavorite actions
if ((isset($_GET['fav']) || isset($_GET['unfav'])) && isset($_GET['file'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        die('Invalid CSRF token');
    }
    
    $fileAction = sanitizePath($_GET['file']);
    $sourcePath = realpath($fullPath . '/' . $fileAction);
    
    if ($sourcePath) {
        $isFavorite = isset($_GET['fav']);
        $sourceRoot = $isFavorite ? $mediaRoot : $favoritesFolder;
        $destRoot = $isFavorite ? $favoritesFolder : $mediaRoot;
        
        // Verify source path is within allowed roots
        if (strpos($sourcePath, realpath($sourceRoot)) === 0) {
            // Maintain subfolder structure
            $subfolderPath = str_replace(realpath($sourceRoot), '', dirname($sourcePath));
            $destinationPath = realpath($destRoot) . $subfolderPath . '/' . basename($sourcePath);
            
            // Create destination directory if needed
            if (!file_exists(dirname($destinationPath))) {
                mkdir(dirname($destinationPath), 0755, true);
            }
            
            if (!file_exists($destinationPath)) {
                rename($sourcePath, $destinationPath);
            }
        }
    }
    header('Location: ?dir=' . urlencode($currentDir));
    exit;
}

// Create thumbs and favorites directories if they don't exist
if (!file_exists('thumbs')) {
    mkdir('thumbs', 0755, true);
}
if (!file_exists($favoritesFolder)) {
    mkdir($favoritesFolder, 0755, true);
}

// Scan directory for media files
function scanDirectory($path) {
    global $allowedExtensions, $mediaRoot, $favoritesFolder;
    
    $items = [];
    $files = scandir($path);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $fullPath = $path . '/' . $file;
        $isDir = is_dir($fullPath);
        
        if ($isDir) {
            $items[] = [
                'name' => $file,
                'path' => $fullPath,
                'type' => 'directory',
                'thumbnail' => 'folder-icon.png'
            ];
        } else {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExtensions)) {
                $isFavorite = strpos($fullPath, realpath($favoritesFolder)) === 0;
                $items[] = [
                    'name' => $file,
                    'path' => $fullPath,
                    'type' => in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv']) ? 'video' : 'image',
                    'extension' => $ext,
                    'favorite' => $isFavorite
                ];
            }
        }
    }
    
    return $items;
}

// Generate thumbnail for media file
function generateThumbnail($filePath, $type) {
    global $thumbnailWidth, $thumbnailHeight, $thumbnailQuality;
    
    $thumbnailPath = 'thumbs/' . md5($filePath) . '.jpg';
    
    // Return existing thumbnail if it exists
    if (file_exists($thumbnailPath)) {
        return $thumbnailPath;
    }
    
    if ($type === 'video') {
        // Use FFMPEG to generate video thumbnail
        $ffmpegPath = 'ffmpeg';
        $cmd = sprintf(
            '%s -i %s -ss 00:00:01 -vframes 1 -vf "scale=%d:%d:force_original_aspect_ratio=decrease" %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($filePath),
            $thumbnailWidth,
            $thumbnailHeight,
            escapeshellarg($thumbnailPath)
        );
        
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($thumbnailPath)) {
            return 'video-icon.png';
        }
    } elseif ($type === 'image') {
        try {
            $sourceImage = null;
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $sourceImage = imagecreatefromjpeg($filePath);
                    break;
                case 'png':
                    $sourceImage = imagecreatefrompng($filePath);
                    break;
                case 'gif':
                    $sourceImage = imagecreatefromgif($filePath);
                    break;
            }
            
            if ($sourceImage) {
                $width = imagesx($sourceImage);
                $height = imagesy($sourceImage);
                
                $aspectRatio = $width / $height;
                if ($thumbnailWidth / $thumbnailHeight > $aspectRatio) {
                    $newWidth = $thumbnailHeight * $aspectRatio;
                    $newHeight = $thumbnailHeight;
                } else {
                    $newWidth = $thumbnailWidth;
                    $newHeight = $thumbnailWidth / $aspectRatio;
                }
                
                $thumbnail = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($thumbnail, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                
                imagejpeg($thumbnail, $thumbnailPath, $thumbnailQuality);
                imagedestroy($sourceImage);
                imagedestroy($thumbnail);
            }
        } catch (Exception $e) {
            return 'image-icon.png';
        }
    }
    
    return file_exists($thumbnailPath) ? $thumbnailPath : ($type === 'video' ? 'video-icon.png' : 'image-icon.png');
}

// Get media items for current directory
$mediaItems = scanDirectory($fullPath);

// Generate breadcrumb navigation
function generateBreadcrumbs($baseUrl, $currentDir, $mediaRoot, $favoritesFolder) {
    $breadcrumbs = [];
    $parts = explode('/', trim($currentDir, '/'));
    $accumulatedPath = '';
    
    $breadcrumbs[] = [
        'name' => 'Home',
        'path' => $baseUrl . '?dir='
    ];
    
    // Special case for favorites
    $favoritesRelativePath = ltrim(str_replace($mediaRoot, '', $favoritesFolder), '/');
    if ($currentDir === 'favorites' || $currentDir === $favoritesRelativePath) {
        $breadcrumbs[] = [
            'name' => 'Favorites',
            'path' => $baseUrl . '?dir=' . urlencode($favoritesRelativePath)
        ];
        return $breadcrumbs;
    }
    
    foreach ($parts as $part) {
        $accumulatedPath .= '/' . $part;
        $breadcrumbs[] = [
            'name' => $part,
            'path' => $baseUrl . '?dir=' . urlencode(ltrim($accumulatedPath, '/'))
        ];
    }
    
    return $breadcrumbs;
}

$breadcrumbs = generateBreadcrumbs($_SERVER['PHP_SELF'], $currentDir, $mediaRoot, $favoritesFolder);
$isFavoritesView = strpos($fullPath, realpath($favoritesFolder)) === 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Browser</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/css/styles.css">
<script src="/js/script.js" defer></script>

</head>
<body>
    <div class="nav-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($crumb['path']) ?>"><?= htmlspecialchars($crumb['name']) ?></a></li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <?php if (!$isFavoritesView): ?>
<a href="?dir=<?= urlencode('favorites') ?>" class="btn btn-sm btn-outline-danger">
    <i class="bi bi-heart-fill"></i> View Favorites
</a>
        <?php endif; ?>
    </div>

    <div class="media-grid">
        <?php foreach ($mediaItems as $item): ?>
            <div class="media-item <?= $item['favorite'] ?? false ? 'favorited' : '' ?>">
                <?php if ($item['type'] === 'directory'): ?>
                    <a href="?dir=<?= urlencode(ltrim(str_replace(realpath($isFavoritesView ? $favoritesFolder : $mediaRoot), '', $item['path']), '/')) ?>">
                        <div class="folder-icon">
                            <i class="bi bi-folder"></i>
                        </div>
                        <div class="media-info">
                            <div class="media-title"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="media-actions">
                                <span>Folder</span>
                            </div>
                        </div>
                    </a>
                <?php else: ?>
                    <?php 
                    $thumbnail = generateThumbnail($item['path'], $item['type']);
                    $relativePath = str_replace(realpath($isFavoritesView ? $favoritesFolder : $mediaRoot), '', $item['path']);
                    ?>
                    <img src="<?= htmlspecialchars($thumbnail) ?>" class="media-thumbnail" 
                         onclick="openMedia('<?= htmlspecialchars($relativePath) ?>', '<?= $item['type'] ?>')">
                    <div class="media-info">
                        <div class="media-title"><?= htmlspecialchars($item['name']) ?></div>
                        <div class="media-actions">
                            <span><?= strtoupper($item['extension']) ?></span>
                            <div>
                                <a href="?<?= $item['favorite'] ? 'unfav' : 'fav' ?>=1&file=<?= urlencode($item['name']) ?>&dir=<?= urlencode($currentDir) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                   onclick="return toggleFavorite(event, this, '<?= htmlspecialchars($item['name']) ?>', '<?= htmlspecialchars($currentDir) ?>', <?= $item['favorite'] ? 'true' : 'false' ?>)"
                                   title="<?= $item['favorite'] ? 'Remove from favorites' : 'Add to favorites' ?>">
                                    <i class="bi <?= $item['favorite'] ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                                </a>
                                <a href="?delete=1&file=<?= urlencode($item['name']) ?>&dir=<?= urlencode($currentDir) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                   onclick="return confirm('Are you sure you want to delete this file?')"
                                   title="Delete">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

<div id="mediaPlayer" class="video-player" style="display: none;">
    <div class="player-container">
        <video id="videoElement" controls autoplay></video>
       <div class="player-controls">
    <button class="btn btn-sm btn-outline-secondary" onclick="prevMedia()">
        <i class="bi bi-skip-backward-fill"></i> Prev
    </button>
    <button class="btn btn-sm btn-outline-danger" id="favoriteBtn" onclick="toggleFavoriteCurrent()">
        <i class="bi bi-heart" id="favoriteIcon"></i> <span id="favoriteText">Favorite</span>
    </button>
    <button class="btn btn-sm btn-outline-danger" onclick="deleteCurrentVideo()">
        <i class="bi bi-trash"></i> Delete
    </button>
    <button class="btn btn-sm btn-outline-secondary" onclick="nextMedia()">
        <i class="bi bi-skip-forward-fill"></i> Next
    </button>
    <button class="btn btn-sm btn-outline-light" onclick="closeMedia()">
        <i class="bi bi-x-lg"></i> Close
    </button>
</div>
    </div>
</div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let currentMediaIndex = -1;
let currentMediaList = [];

function openMedia(path, type) {
    if (type === 'video') {
        // Get all media items that are videos
        currentMediaList = Array.from(document.querySelectorAll('.media-item'))
            .filter(item => item.querySelector('img') && item.querySelector('img').getAttribute('onclick').includes("'video'"))
            .map(item => {
                const img = item.querySelector('img');
                const onclick = img.getAttribute('onclick');
                const pathMatch = onclick.match(/openMedia\('([^']*)'/);
                return {
                    path: pathMatch[1],
                    type: 'video',
                    favorite: item.classList.contains('favorited'),
                    name: item.querySelector('.media-title').textContent
                };
            });

        // Find current index
        currentMediaIndex = currentMediaList.findIndex(media => media.path === path);
        
        if (currentMediaIndex === -1) return;

        currentPlayingVideo = {
            path: path,
            name: currentMediaList[currentMediaIndex].name,
            dir: '<?= addslashes($currentDir) ?>',
            favorite: currentMediaList[currentMediaIndex].favorite
        };
        
        const player = document.getElementById('mediaPlayer');
        const videoElement = document.getElementById('videoElement');
        
        videoElement.src = '<?= $isFavoritesView ? $favoritesFolder : $mediaRoot ?>' + path;
        player.style.display = 'flex';
        
        // Update favorite button state
        updateFavoriteButton();
        
        if (document.fullscreenElement) {
            document.exitFullscreen();
        }
    } else if (type === 'image') {
        window.open('<?= $isFavoritesView ? $favoritesFolder : $mediaRoot ?>' + path, '_blank');
    }
}

function updateFavoriteButton() {
    const favoriteBtn = document.getElementById('favoriteBtn');
    const favoriteIcon = document.getElementById('favoriteIcon');
    const favoriteText = document.getElementById('favoriteText');
    
    if (!favoriteBtn || !favoriteIcon || !favoriteText) return;
    
    if (currentPlayingVideo && currentPlayingVideo.favorite) {
        favoriteBtn.classList.add('btn-danger');
        favoriteBtn.classList.remove('btn-outline-danger');
        favoriteIcon.className = 'bi bi-heart-fill';
        favoriteText.textContent = 'Favorited';
    } else {
        favoriteBtn.classList.remove('btn-danger');
        favoriteBtn.classList.add('btn-outline-danger');
        favoriteIcon.className = 'bi bi-heart';
        favoriteText.textContent = 'Favorite';
    }
}

function toggleFavoriteCurrent() {
    if (!currentPlayingVideo) return;
    
    const isFavorite = !currentPlayingVideo.favorite;
    const action = isFavorite ? 'fav' : 'unfav';
    
    $.ajax({
        url: `?${action}=1&file=${encodeURIComponent(currentPlayingVideo.name)}&dir=${encodeURIComponent(currentPlayingVideo.dir)}&csrf_token=<?= $_SESSION['csrf_token'] ?>`,
        method: 'GET',
        success: function() {
            currentPlayingVideo.favorite = isFavorite;
            updateFavoriteButton();
            
            // Update the corresponding thumbnail in the grid
            const thumbnails = document.querySelectorAll('.media-item');
            thumbnails.forEach(item => {
                const title = item.querySelector('.media-title');
                if (title && title.textContent === currentPlayingVideo.name) {
                    item.classList.toggle('favorited', isFavorite);
                    const favIcon = item.querySelector('.bi-heart, .bi-heart-fill');
                    if (favIcon) {
                        favIcon.className = isFavorite ? 'bi bi-heart-fill' : 'bi bi-heart';
                        const favLink = favIcon.closest('a');
                        if (favLink) {
                            const newHref = favLink.href.replace(
                                isFavorite ? /unfav=1/ : /fav=1/,
                                isFavorite ? 'fav=1' : 'unfav=1'
                            );
                            favLink.href = newHref;
                        }
                    }
                }
            });
        },
        error: function() {
            alert('Error updating favorite');
        }
    });
}

function prevMedia() {
    if (currentMediaList.length === 0 || currentMediaIndex <= 0) return;
    
    const prevIndex = currentMediaIndex - 1;
    const prevMedia = currentMediaList[prevIndex];
    openMedia(prevMedia.path, prevMedia.type);
}

function nextMedia() {
    if (currentMediaList.length === 0 || currentMediaIndex >= currentMediaList.length - 1) return;
    
    const nextIndex = currentMediaIndex + 1;
    const nextMedia = currentMediaList[nextIndex];
    openMedia(nextMedia.path, nextMedia.type);
}
function closeMedia() {
    const player = document.getElementById('mediaPlayer');
    const videoElement = document.getElementById('videoElement');
    
    if (videoElement) {
        videoElement.pause();
        videoElement.currentTime = 0;
        videoElement.src = '';
    }
    
    player.style.display = 'none';
    currentPlayingVideo = null;
    currentMediaIndex = -1;
}

function deleteCurrentVideo() {
    // Double-check we have a current video and get user confirmation
    if (!currentPlayingVideo || !confirm('Are you sure you want to permanently delete this file?')) {
        return;
    }

    // Store references to the current video before deletion
    const videoToDelete = currentPlayingVideo;
    const currentIndex = currentMediaIndex;
    
    $.ajax({
        url: `?delete=1&file=${encodeURIComponent(videoToDelete.name)}&dir=${encodeURIComponent(videoToDelete.dir)}&csrf_token=<?= $_SESSION['csrf_token'] ?>`,
        method: 'GET',
        success: function() {
            // Remove the item from the grid view
            const thumbnails = document.querySelectorAll('.media-item');
            thumbnails.forEach(item => {
                const title = item.querySelector('.media-title');
                if (title && title.textContent === videoToDelete.name) {
                    item.remove();
                }
            });

            // Update the media list if it exists
            if (currentMediaList && currentIndex >= 0) {
                currentMediaList.splice(currentIndex, 1);
                
                // If there are remaining items, show the next one
                if (currentMediaList.length > 0) {
                    const newIndex = Math.min(currentIndex, currentMediaList.length - 1);
                    const nextMedia = currentMediaList[newIndex];
                    openMedia(nextMedia.path, nextMedia.type);
                } else {
                    // No more videos, close the player
                    closeMedia();
                }
            } else {
                // No media list, just close the player
                closeMedia();
            }
        },
        error: function() {
            alert('Error deleting file. Please try again.');
        }
    });
}
// Add keyboard navigation
document.addEventListener('keydown', function(e) {
    if (document.getElementById('mediaPlayer').style.display === 'none') return;
    
    switch(e.key) {
        case 'Escape':
            closeMedia();
            break;
        case 'ArrowLeft':
            prevMedia();
            break;
        case 'ArrowRight':
            nextMedia();
            break;
        case 'f':
            toggleFavoriteCurrent();
            break;
    }
});
    </script>
</body>
</html>