<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/Files.php';

class ApplicationUI {
    
    /**
     * Generate a cache-busted URL for a static resource
     */
    public static function staticResourceUrl(string $path): string {
        $filePath = __DIR__ . '/../' . ltrim($path, '/');
        $version = @filemtime($filePath);
        if (!$version) { 
            $version = date('Ymd'); 
        }
        return $path . '?v=' . $version;
    }
    
    /**
     * Generate a complete CSS link tag with cache-busting
     */
    public static function cssLink(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    }
    
    /**
     * Generate a complete JS script tag with cache-busting
     */
    public static function jsScript(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
    }
    
    public static function headerHtml(string $title): void {
        $u = current_user();
        $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $link = function(string $path, string $label) use ($cur) {
            $active = ($cur === basename($path));
            $a = '<a href="'.h($path).'">'.h($label).'</a>';
            return $active ? '<strong>'.$a.'</strong>' : $a;
        };

        // Build nav (left/right groups)
        $navLeft = [];
        $navRight = [];
        if ($u) {
            $navLeft[] = $link('/index.php','Home');
            $navLeft[] = $link('/divisions/','Divisions');
            $navLeft[] = $link('/teams/','Teams');
            $navLeft[] = $link('/locations/','Locations');
            
            // Admin menu goes on the right side
            if (!empty($u['is_admin'])) {
                $navRight[] = '<div class="nav-admin-wrap">'
                            . '<a href="#" id="adminToggle" class="nav-admin-link" aria-expanded="false">Admin</a>'
                            . '<div id="adminMenu" class="admin-menu hidden" role="menu" aria-hidden="true">'
                            .   '<a href="/admin/users.php" role="menuitem">Users</a>'
                            .   '<a href="/admin/settings.php" role="menuitem">Settings</a>'
                            .   '<a href="/admin/activity_log.php" role="menuitem">Activity Log</a>'
                            .   '<a href="/admin/email_log.php" role="menuitem">Email Log</a>'
                            . '</div>'
                            . '</div>';
            }
            
            // Profile photo with dropdown menu
            $initials = strtoupper(substr((string)($u['first_name'] ?? ''),0,1).substr((string)($u['last_name'] ?? ''),0,1));
            $photoUrl = Files::profilePhotoUrl($u['photo_public_file_id'] ?? null, 32);
            
            if ($photoUrl !== '') {
                $avatar = '<img class="nav-avatar" src="'.h($photoUrl).'" alt="Profile" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">';
            } else {
                $avatar = '<span class="nav-avatar nav-avatar-initials" aria-hidden="true">'.h($initials).'</span>';
            }
            
            $navRight[] = '<div class="nav-avatar-wrap">'
                        . '<a href="#" id="avatarToggle" class="nav-avatar-link" aria-expanded="false" title="Account">'.$avatar.'</a>'
                        . '<div id="avatarMenu" class="avatar-menu hidden" role="menu" aria-hidden="true">'
                        .   '<a href="/my_profile.php" role="menuitem">My Profile</a>'
                        .   '<a href="/change_password.php" role="menuitem">Change Password</a>'
                        .   '<a href="/logout.php" role="menuitem">Logout</a>'
                        . '</div>'
                        . '</div>';
        } else {
            $navLeft[] = $link('/login.php','Login');
        }
        
        $navHtml = '<span class="nav-left">'.implode(' ', $navLeft).'</span>'
                 . '<span class="nav-right">'.implode(' ', $navRight).'</span>';

        $siteTitle = Settings::siteTitle();

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>'.h($title).' - '.h($siteTitle).'</title>';

        // cache-busted CSS
        echo self::cssLink('/styles.css');
        echo '</head><body>';
        echo '<header><h1><a href="/index.php">'.h($siteTitle).'</a></h1><nav>'.$navHtml.'</nav></header>';

        // Dropdown menu scripts
        if ($u) {
            echo '<script>document.addEventListener("DOMContentLoaded",function(){';
            
            // Avatar dropdown script
            echo 'var at=document.getElementById("avatarToggle");var m=document.getElementById("avatarMenu");function hideAvatar(){if(m){m.classList.add("hidden");m.setAttribute("aria-hidden","true");}if(at){at.setAttribute("aria-expanded","false");}}function toggleAvatar(e){e.preventDefault();if(!m)return;var isHidden=m.classList.contains("hidden");if(isHidden){m.classList.remove("hidden");m.setAttribute("aria-hidden","false");if(at)at.setAttribute("aria-expanded","true");}else{hideAvatar();}}if(at)at.addEventListener("click",toggleAvatar);';
            
            // Admin dropdown script
            if (!empty($u['is_admin'])) {
                echo 'var adminToggle=document.getElementById("adminToggle");var adminMenu=document.getElementById("adminMenu");function hideAdmin(){if(adminMenu){adminMenu.classList.add("hidden");adminMenu.setAttribute("aria-hidden","true");}if(adminToggle){adminToggle.setAttribute("aria-expanded","false");}}function toggleAdmin(e){e.preventDefault();if(!adminMenu)return;var isHidden=adminMenu.classList.contains("hidden");if(isHidden){adminMenu.classList.remove("hidden");adminMenu.setAttribute("aria-hidden","false");if(adminToggle)adminToggle.setAttribute("aria-expanded","true");}else{hideAdmin();}}if(adminToggle)adminToggle.addEventListener("click",toggleAdmin);';
            }
            
            // Global click handler to close dropdowns
            echo 'document.addEventListener("click",function(e){';
            echo 'var avatarWrap=at?at.closest(".nav-avatar-wrap"):null;if(avatarWrap&&avatarWrap.contains(e.target))return;hideAvatar();';
            if (!empty($u['is_admin'])) {
                echo 'var adminWrap=adminToggle?adminToggle.closest(".nav-admin-wrap"):null;if(adminWrap&&adminWrap.contains(e.target))return;hideAdmin();';
            }
            echo '});';
            
            // Escape key handler
            echo 'document.addEventListener("keydown",function(e){if(e.key==="Escape"){hideAvatar();';
            if (!empty($u['is_admin'])) {
                echo 'hideAdmin();';
            }
            echo '}});';
            
            echo '});</script>';
        }
        echo '<main>';
    }

    public static function footerHtml(): void {
        // cache-busted JS
        echo '</main>' . self::jsScript('/main.js') . '</body></html>';
    }
}
