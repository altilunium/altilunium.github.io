<?php
/**
 * Single-file PHP application for OpenStreetMap list manager.
 * Features:
 *  - OSM OAuth 2.0 Authentication & Mock Toggle for local dev
 *  - Flat-file JSON database per user with file locking (fcntl)
 *  - Global activity log with 600-record file rollover
 *  - "Read More" pagination for the global timeline
 *  - Interactive live-search autocomplete storing labels directly
 *  - Full list management, reordering, item comments editing, description editing, public profiles,
 *    upvotes, and private following system.
 */

session_start();

// -----------------------------------------------------------------------------
// CONFIGURATION & OAUTH SETTINGS
// -----------------------------------------------------------------------------
define('MOCK_DEV_MODE', false); // Set to false in production on Toolforge
define('OSM_CLIENT_ID', 'set up yours');
define('OSM_CLIENT_SECRET', 'set up yours');
define('OSM_REDIRECT_URI', 'https://cyllchuesnconii.rf.gd/w/index.php?action=oauth_callback');

define('OSM_AUTH_URL', 'https://www.openstreetmap.org/oauth2/authorize');
define('OSM_TOKEN_URL', 'https://www.openstreetmap.org/oauth2/token');
define('OSM_USER_DETAILS_URL', 'https://api.openstreetmap.org/api/0.6/user/details.json');

define('DATA_DIR', __DIR__ . '/data');
define('USERS_DIR', DATA_DIR . '/users');
define('ACTIVITY_DIR', DATA_DIR . '/activity');

// Ensure data directories exist
if (!is_dir(USERS_DIR)) { mkdir(USERS_DIR, 0777, true); }
if (!is_dir(ACTIVITY_DIR)) { mkdir(ACTIVITY_DIR, 0777, true); }

// -----------------------------------------------------------------------------
// FLAT-FILE STORAGE HELPERS (WITH FILE LOCKING)
// -----------------------------------------------------------------------------
function load_user_file($username) {
    $filepath = USERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $username) . '.json';
    if (!file_exists($filepath)) {
        return [
            'username' => $username,
            'lists' => [],
            'following' => [],
            'upvoted_lists' => []
        ];
    }
    $fp = fopen($filepath, 'r');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $data = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($data, true);
}

function save_user_file($username, $data) {
    $filepath = USERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $username) . '.json';
    $fp = fopen($filepath, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function log_global_activity($actor, $action_text, $target_url = '#') {
    $files = glob(ACTIVITY_DIR . '/activity_*.json');
    sort($files);
    
    $target_file = null;
    $max_index = 0;
    
    if (empty($files)) {
        $target_file = ACTIVITY_DIR . '/activity_0.json';
    } else {
        $last_file = end($files);
        preg_match('/activity_(\d+)\.json$/', $last_file, $matches);
        $max_index = isset($matches[1]) ? (int)$matches[1] : 0;
        
        $fp = fopen($last_file, 'r');
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        $entries = json_decode($content, true) ?: [];
        if (count($entries) >= 600) {
            $max_index++;
            $target_file = ACTIVITY_DIR . '/activity_' . $max_index . '.json';
        } else {
            $target_file = $last_file;
        }
    }
    
    $fp = fopen($target_file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    $entries = json_decode($content, true) ?: [];
    
    array_unshift($entries, [
        'timestamp' => date('c'),
        'actor' => $actor,
        'action' => $action_text,
        'url' => $target_url
    ]);
    
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($entries, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function get_global_activity_feed($limit = 150) {
    $files = glob(ACTIVITY_DIR . '/activity_*.json');
    sort($files);
    $files = array_reverse($files);
    
    $feed = [];
    foreach ($files as $file) {
        $fp = fopen($file, 'r');
        if (!$fp) continue;
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        $entries = json_decode($content, true) ?: [];
        foreach ($entries as $e) {
            $feed[] = $e;
            if (count($feed) >= $limit) break 2;
        }
    }
    return $feed;
}

function render_markdown($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*([^\*]+)\*/s', '<em>$1</em>', $text);
    $text = preg_replace('/\`([^\`]+)\`/s', '<code>$1</code>', $text);
    return nl2br($text);
}

// -----------------------------------------------------------------------------
// ROUTING & REQUEST HANDLING
// -----------------------------------------------------------------------------
$action = $_GET['action'] ?? 'home';
$user = $_SESSION['user'] ?? null;

if ($action === 'login') {
    if (MOCK_DEV_MODE) {
        $_SESSION['user'] = 'OSM_Mapper_Dev';
        header('Location: index.php');
        exit;
    }
    
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $authUrl = OSM_AUTH_URL . '?' . http_build_query([
        'client_id' => OSM_CLIENT_ID,
        'redirect_uri' => OSM_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'read_prefs',
        'state' => $state
    ]);
    header('Location: ' . $authUrl);
    exit;
}

if ($action === 'oauth_callback') {
    $code = $_GET['code'] ?? null;
    if ($code) {
        $ch = curl_init(OSM_TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => OSM_CLIENT_ID,
            'client_secret' => OSM_CLIENT_SECRET,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => OSM_REDIRECT_URI
        ]));
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        $accessToken = $response['access_token'] ?? null;
        if ($accessToken) {
            $ch = curl_init(OSM_USER_DETAILS_URL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}"]);
            $userData = json_decode(curl_exec($ch), true);
            curl_close($ch);
            
            $username = $userData['user']['display_name'] ?? null;
            if ($username) {
                $_SESSION['user'] = $username;
                $uData = load_user_file($username);
                save_user_file($username, $uData);
            }
        }
    }
    header('Location: index.php');
    exit;
}

if ($action === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $uData = load_user_file($user);
    
    if ($action === 'create_list') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title !== '') {
            $listId = uniqid('list_');
            $uData['lists'][$listId] = [
                'id' => $listId,
                'title' => $title,
                'description' => $description,
                'items' => [],
                'upvotes' => 0,
                'created_at' => date('c')
            ];
            save_user_file($user, $uData);
            log_global_activity($user, "created a new list '{$title}'", "index.php?action=view_list&owner={$user}&list_id={$listId}");
        }
        header('Location: index.php?action=my_lists');
        exit;
    }
    
    if ($action === 'delete_list') {
        $listId = $_POST['list_id'] ?? '';
        if (isset($uData['lists'][$listId])) {
            $title = $uData['lists'][$listId]['title'];
            unset($uData['lists'][$listId]);
            save_user_file($user, $uData);
            log_global_activity($user, "deleted list '{$title}'");
        }
        header('Location: index.php?action=my_lists');
        exit;
    }

    if ($action === 'edit_list') {
        $listId = $_POST['list_id'] ?? '';
        if (isset($uData['lists'][$listId])) {
            $uData['lists'][$listId]['title'] = trim($_POST['title'] ?? $uData['lists'][$listId]['title']);
            $uData['lists'][$listId]['description'] = trim($_POST['description'] ?? '');
            save_user_file($user, $uData);
        }
        header("Location: index.php?action=view_list&owner={$user}&list_id={$listId}");
        exit;
    }
    
    if ($action === 'add_item') {
        $listId = $_POST['list_id'] ?? '';
        $qid = trim($_POST['qid'] ?? '');
        $label = trim($_POST['label'] ?? $qid);
        $description = trim($_POST['desc'] ?? '');
        $comment = trim($_POST['comment'] ?? '');
        
        if (isset($uData['lists'][$listId]) && $qid !== '') {
            $itemId = uniqid('item_');
            $uData['lists'][$listId]['items'][] = [
                'id' => $itemId,
                'qid' => $qid,
                'label' => $label ?: $qid,
                'wikidata_desc' => $description,
                'comment' => $comment
            ];
            save_user_file($user, $uData);
            log_global_activity($user, "added Wikidata item \"{$label}\" ({$qid}) to list '{$uData['lists'][$listId]['title']}'", "index.php?action=view_list&owner={$user}&list_id={$listId}");
        }
        header("Location: index.php?action=view_list&owner={$user}&list_id={$listId}");
        exit;
    }

    if ($action === 'edit_item_comment') {
        $listId = $_POST['list_id'] ?? '';
        $index = (int)($_POST['index'] ?? -1);
        $comment = trim($_POST['comment'] ?? '');
        
        if (isset($uData['lists'][$listId]['items'][$index])) {
            $uData['lists'][$listId]['items'][$index]['comment'] = $comment;
            save_user_file($user, $uData);
        }
        header("Location: index.php?action=view_list&owner={$user}&list_id={$listId}");
        exit;
    }

    if ($action === 'reorder_items') {
        $listId = $_POST['list_id'] ?? '';
        $direction = $_POST['direction'] ?? '';
        $index = (int)($_POST['index'] ?? -1);
        
        if (isset($uData['lists'][$listId]['items'][$index])) {
            $items = &$uData['lists'][$listId]['items'];
            if ($direction === 'up' && $index > 0) {
                $tmp = $items[$index - 1];
                $items[$index - 1] = $items[$index];
                $items[$index] = $tmp;
            } elseif ($direction === 'down' && $index < count($items) - 1) {
                $tmp = $items[$index + 1];
                $items[$index + 1] = $items[$index];
                $items[$index] = $tmp;
            }
            save_user_file($user, $uData);
        }
        header("Location: index.php?action=view_list&owner={$user}&list_id={$listId}");
        exit;
    }

    if ($action === 'delete_item') {
        $listId = $_POST['list_id'] ?? '';
        $index = (int)($_POST['index'] ?? -1);
        
        if (isset($uData['lists'][$listId]['items'][$index])) {
            array_splice($uData['lists'][$listId]['items'], $index, 1);
            save_user_file($user, $uData);
        }
        header("Location: index.php?action=view_list&owner={$user}&list_id={$listId}");
        exit;
    }

    if ($action === 'upvote_list') {
        $owner = $_POST['owner'] ?? '';
        $listId = $_POST['list_id'] ?? '';
        
        $ownerData = load_user_file($owner);
        if ($ownerData && isset($ownerData['lists'][$listId])) {
            $upvoteKey = "{$owner}/{$listId}";
            if (!in_array($upvoteKey, $uData['upvoted_lists'] ?? [])) {
                $uData['upvoted_lists'][] = $upvoteKey;
                $ownerData['lists'][$listId]['upvotes'] = ($ownerData['lists'][$listId]['upvotes'] ?? 0) + 1;
                save_user_file($user, $uData);
                save_user_file($owner, $ownerData);
                log_global_activity($user, "upvoted {$owner}'s list '{$ownerData['lists'][$listId]['title']}'", "index.php?action=view_list&owner={$owner}&list_id={$listId}");
            }
        }
        header("Location: index.php?action=view_list&owner={$owner}&list_id={$listId}");
        exit;
    }

    if ($action === 'follow_list') {
        $owner = $_POST['owner'] ?? '';
        $listId = $_POST['list_id'] ?? '';
        $followKey = "{$owner}/{$listId}";
        
        if (!in_array($followKey, $uData['following'] ?? [])) {
            $uData['following'][] = $followKey;
            save_user_file($user, $uData);
            log_global_activity($user, "followed {$owner}'s list", "index.php?action=view_list&owner={$owner}&list_id={$listId}");
        }
        header("Location: index.php?action=view_list&owner={$owner}&list_id={$listId}");
        exit;
    }

    if ($action === 'unfollow_list') {
        $owner = $_POST['owner'] ?? '';
        $listId = $_POST['list_id'] ?? '';
        $followKey = "{$owner}/{$listId}";
        
        $idx = array_search($followKey, $uData['following'] ?? []);
        if ($idx !== false) {
            unset($uData['following'][$idx]);
            $uData['following'] = array_values($uData['following']);
            save_user_file($user, $uData);
        }
        header("Location: index.php?action=view_list&owner={$owner}&list_id={$listId}");
        exit;
    }
}

// -----------------------------------------------------------------------------
// UI VIEW RENDERING
// -----------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="https://www.wikidata.org/static/favicon/wikidata.ico">
    <title>OSM & Wikidata List Tool</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen text-gray-800">
    <header class="bg-emerald-700 text-white shadow-md">
        <div class="max-w-6xl mx-auto px-4 py-3 flex justify-between items-center">
            <a href="index.php" class="text-xl font-bold flex items-center space-x-2">
                <span>🗺️ OSM Wikidata Lists</span>
            </a>
            <nav class="flex items-center space-x-4">
                <a href="index.php" class="hover:underline">Global Activity</a>
                <?php if ($user): ?>
                    <a href="index.php?action=my_lists" class="hover:underline">My Lists</a>
                    <a href="index.php?action=followed" class="hover:underline">Followed Lists</a>
                    <a href="index.php?action=profile&username=<?= urlencode($user) ?>" class="bg-emerald-800 px-3 py-1 rounded text-sm hover:bg-emerald-900">
                        Profile (<?= htmlspecialchars($user) ?>)
                    </a>
                    <a href="index.php?action=logout" class="text-xs text-emerald-200 hover:underline">Logout</a>
                <?php else: ?>
                    <a href="index.php?action=login" class="bg-emerald-900 px-4 py-2 rounded font-semibold hover:bg-emerald-950 transition">
                        <?= MOCK_DEV_MODE ? '⚡ Mock Dev Login' : 'Login with OpenStreetMap' ?>
                    </a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6">

    <?php if ($action === 'home'): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="md:col-span-2">
                <h1 class="text-2xl font-bold mb-4">Global Activity Timeline</h1>
                <div class="bg-white rounded shadow p-4 space-y-3">
                    <?php 
                    $activities = get_global_activity_feed(100);
                    if (empty($activities)): 
                    ?>
                        <p class="text-gray-500 italic">No activity logged yet.</p>
                    <?php else: ?>
                        <div id="timelineContainer" class="space-y-3">
                            <?php foreach ($activities as $index => $act): ?>
                                <div class="timeline-item border-b border-gray-100 pb-2 text-sm flex justify-between items-start <?= $index >= 15 ? 'hidden' : '' ?>">
                                    <div>
                                        <a href="index.php?action=profile&username=<?= urlencode($act['actor']) ?>" class="font-bold text-emerald-700 hover:underline">
                                            <?= htmlspecialchars($act['actor']) ?>
                                        </a>
                                        <?= htmlspecialchars($act['action']) ?>
                                        <?php if (!empty($act['url']) && $act['url'] !== '#'): ?>
                                            <a href="<?= htmlspecialchars($act['url']) ?>" class="text-emerald-600 hover:underline font-medium">→ View</a>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-xs text-gray-400 whitespace-nowrap ml-2">
                                        <?= date('M j, H:i', strtotime($act['timestamp'])) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (count($activities) > 15): ?>
                            <div class="text-center pt-3">
                                <button id="loadMoreBtn" onclick="loadMoreTimeline()" class="bg-gray-100 hover:bg-gray-200 text-emerald-800 text-sm font-semibold px-4 py-2 rounded border">
                                    Read More Activity ↓
                                </button>
                            </div>
                        <?php endif; ?>

                        <script>
                        let visibleCount = 15;
                        function loadMoreTimeline() {
                            const items = document.querySelectorAll('.timeline-item');
                            for (let i = visibleCount; i < visibleCount + 15 && i < items.length; i++) {
                                items[i].classList.remove('hidden');
                            }
                            visibleCount += 15;
                            if (visibleCount >= items.length) {
                                document.getElementById('loadMoreBtn').style.display = 'none';
                            }
                        }
                        </script>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="space-y-4">
                <div class="bg-white rounded shadow p-4">
                    <h2 class="font-bold text-lg mb-2">About This Tool</h2>
                    <p class="text-sm text-gray-600">
                        Create, organize, and share custom lists of Wikidata items using your OpenStreetMap login credentials with real-time Wikidata search.
                    </p>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'my_lists' && $user): ?>
        <?php $uData = load_user_file($user); ?>
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">My Personal Lists</h1>
        </div>

        <div class="bg-white rounded shadow p-4 mb-6">
            <h2 class="font-bold mb-3">Create New List</h2>
            <form action="index.php?action=create_list" method="POST" class="space-y-3">
                <div>
                    <input type="text" name="title" placeholder="List Title (Mandatory)" required class="w-full border p-2 rounded focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <textarea name="description" placeholder="Description (Optional)" class="w-full border p-2 rounded focus:ring-2 focus:ring-emerald-500" rows="2"></textarea>
                </div>
                <button type="submit" class="bg-emerald-600 text-white px-4 py-2 rounded hover:bg-emerald-700">Create List</button>
            </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach (($uData['lists'] ?? []) as $list): ?>
                <div class="bg-white rounded shadow p-4 flex flex-col justify-between">
                    <div>
                        <h3 class="font-bold text-lg text-emerald-800">
                            <a href="index.php?action=view_list&owner=<?= urlencode($user) ?>&list_id=<?= $list['id'] ?>" class="hover:underline">
                                <?= htmlspecialchars($list['title']) ?>
                            </a>
                        </h3>
                        <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($list['description']) ?></p>
                        <p class="text-xs text-gray-400 mt-2"><?= count($list['items']) ?> Items · <?= $list['upvotes'] ?? 0 ?> Upvotes</p>
                    </div>
                    <div class="mt-4 flex space-x-2">
                        <a href="index.php?action=view_list&owner=<?= urlencode($user) ?>&list_id=<?= $list['id'] ?>" class="text-xs bg-gray-100 hover:bg-gray-200 px-3 py-1 rounded">Manage Items</a>
                        <form action="index.php?action=delete_list" method="POST" onsubmit="return confirm('Delete this list?');">
                            <input type="hidden" name="list_id" value="<?= $list['id'] ?>">
                            <button type="submit" class="text-xs bg-red-100 text-red-700 hover:bg-red-200 px-3 py-1 rounded">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($action === 'view_list'): ?>
        <?php 
        $owner = $_GET['owner'] ?? '';
        $listId = $_GET['list_id'] ?? '';
        $ownerData = load_user_file($owner);
        $list = $ownerData['lists'][$listId] ?? null;
        $isOwner = ($user && $user === $owner);
        $currentUserData = $user ? load_user_file($user) : null;
        $isFollowing = $currentUserData && in_array("{$owner}/{$listId}", $currentUserData['following'] ?? []);
        $hasUpvoted = $currentUserData && in_array("{$owner}/{$listId}", $currentUserData['upvoted_lists'] ?? []);
        ?>

        <?php if (!$list): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">List not found.</div>
        <?php else: ?>
            <div class="bg-white rounded shadow p-6 mb-6">
                <div class="flex justify-between items-start">
                    <div class="w-full">
                        <div class="flex justify-between items-center">
                            <h1 class="text-3xl font-bold"><?= htmlspecialchars($list['title']) ?></h1>
                            <?php if ($isOwner): ?>
                                <button onclick="document.getElementById('editListSection').classList.toggle('hidden')" class="text-xs bg-gray-100 hover:bg-gray-200 px-3 py-1 rounded text-gray-700">✏️ Edit List Info</button>
                            <?php endif; ?>
                        </div>
                        <p class="text-gray-600 mt-1"><?= htmlspecialchars($list['description']) ?></p>
                        <p class="text-xs text-gray-400 mt-2">
                            Created by 
                            <a href="index.php?action=profile&username=<?= urlencode($owner) ?>" class="text-emerald-700 font-semibold hover:underline">
                                <?= htmlspecialchars($owner) ?>
                            </a>
                        </p>

                        <!-- Inline Edit List Form (Owner Only) -->
                        <?php if ($isOwner): ?>
                            <div id="editListSection" class="hidden mt-4 pt-4 border-t border-gray-100">
                                <form action="index.php?action=edit_list" method="POST" class="space-y-3">
                                    <input type="hidden" name="list_id" value="<?= htmlspecialchars($listId) ?>">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-600 mb-1">List Title</label>
                                        <input type="text" name="title" value="<?= htmlspecialchars($list['title']) ?>" required class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-emerald-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-600 mb-1">List Description</label>
                                        <textarea name="description" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-emerald-500" rows="2"><?= htmlspecialchars($list['description']) ?></textarea>
                                    </div>
                                    <div class="flex space-x-2">
                                        <button type="submit" class="bg-emerald-600 text-white px-3 py-1.5 rounded text-xs hover:bg-emerald-700">Save Changes</button>
                                        <button type="button" onclick="document.getElementById('editListSection').classList.add('hidden')" class="bg-gray-200 text-gray-700 px-3 py-1.5 rounded text-xs hover:bg-gray-300">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($user && !$isOwner): ?>
                        <div class="flex space-x-2 ml-4 shrink-0">
                            <form action="index.php?action=upvote_list" method="POST">
                                <input type="hidden" name="owner" value="<?= htmlspecialchars($owner) ?>">
                                <input type="hidden" name="list_id" value="<?= htmlspecialchars($listId) ?>">
                                <button type="submit" <?= $hasUpvoted ? 'disabled' : '' ?> class="px-3 py-1 rounded border text-sm <?= $hasUpvoted ? 'bg-gray-100 text-gray-400' : 'bg-emerald-50 text-emerald-700 border-emerald-300 hover:bg-emerald-100' ?>">
                                    👍 <?= $list['upvotes'] ?? 0 ?> Upvotes
                                </button>
                            </form>
                            <form action="index.php?action=<?= $isFollowing ? 'unfollow_list' : 'follow_list' ?>" method="POST">
                                <input type="hidden" name="owner" value="<?= htmlspecialchars($owner) ?>">
                                <input type="hidden" name="list_id" value="<?= htmlspecialchars($listId) ?>">
                                <button type="submit" class="px-3 py-1 rounded text-sm text-white <?= $isFollowing ? 'bg-gray-600 hover:bg-gray-700' : 'bg-emerald-600 hover:bg-emerald-700' ?>">
                                    <?= $isFollowing ? 'Unfollow List' : 'Follow List' ?>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isOwner): ?>
                <div class="bg-gray-50 border rounded p-4 mb-6 relative">
                    <h3 class="font-bold text-sm mb-2">Search & Add Wikidata Item</h3>
                    <form action="index.php?action=add_item" method="POST" class="space-y-3">
                        <input type="hidden" name="list_id" value="<?= htmlspecialchars($listId) ?>">
                        <input type="hidden" name="qid" id="selectedQid" required>
                        <input type="hidden" name="label" id="selectedLabel">
                        <input type="hidden" name="desc" id="selectedDesc">
                        
                        <div class="relative">
                            <input type="text" id="wikidataSearchInput" placeholder="Type to search Wikidata (e.g. Albert Einstein, Paris)..." autocomplete="off" class="w-full border p-2 rounded focus:ring-2 focus:ring-emerald-500 bg-white">
                            <div id="wikidataSearchResults" class="absolute z-10 left-0 right-0 bg-white border border-gray-200 rounded mt-1 shadow-lg max-h-60 overflow-y-auto hidden"></div>
                        </div>

                        <div id="selectedItemPreview" class="text-xs text-emerald-700 font-medium hidden">
                            Selected: <span id="previewText">None</span>
                        </div>

                        <div>
                            <input type="text" name="comment" placeholder="Short comment (Markdown supported)" class="w-full border p-2 rounded focus:ring-2 focus:ring-emerald-500">
                        </div>
                        <button type="submit" id="addItemBtn" disabled class="bg-gray-300 text-gray-500 rounded px-4 py-2 cursor-not-allowed">Add to List</button>
                    </form>
                </div>

                <script>
                const searchInput = document.getElementById('wikidataSearchInput');
                const resultsContainer = document.getElementById('wikidataSearchResults');
                const qidInput = document.getElementById('selectedQid');
                const labelInput = document.getElementById('selectedLabel');
                const descInput = document.getElementById('selectedDesc');
                const previewDiv = document.getElementById('selectedItemPreview');
                const previewText = document.getElementById('previewText');
                const addItemBtn = document.getElementById('addItemBtn');

                let debounceTimer;

                searchInput.addEventListener('input', function() {
                    clearTimeout(debounceTimer);
                    const query = this.value.trim();
                    if (query.length < 2) {
                        resultsContainer.classList.add('hidden');
                        return;
                    }

                    debounceTimer = setTimeout(() => {
                        const url = `https://www.wikidata.org/w/api.php?action=wbsearchentities&origin=*&search=${encodeURIComponent(query)}&format=json&language=en&limit=10&type=item`;
                        fetch(url)
                            .then(res => res.json())
                            .then(data => {
                                resultsContainer.innerHTML = '';
                                const items = data.search || [];
                                if (items.length === 0) {
                                    resultsContainer.innerHTML = '<div class="p-2 text-xs text-gray-500">No results found</div>';
                                } else {
                                    items.forEach(item => {
                                        const div = document.createElement('div');
                                        div.className = 'p-2 hover:bg-emerald-50 cursor-pointer border-b border-gray-100 last:border-b-0';
                                        div.innerHTML = `<div class="font-bold text-sm text-emerald-800">${item.display?.label?.value || item.title} <span class="font-mono text-xs text-gray-500">(${item.title})</span></div>` +
                                                        `<div class="text-xs text-gray-600">${item.display?.description?.value || ''}</div>`;
                                        
                                        div.addEventListener('click', () => {
                                            const label = item.display?.label?.value || item.title;
                                            const desc = item.display?.description?.value || '';

                                            searchInput.value = `${label} (${item.title})`;
                                            qidInput.value = item.title;
                                            labelInput.value = label;
                                            descInput.value = desc;

                                            previewText.textContent = `${label} [${item.title}]`;
                                            previewDiv.classList.remove('hidden');
                                            resultsContainer.classList.add('hidden');
                                            
                                            addItemBtn.disabled = false;
                                            addItemBtn.className = 'bg-emerald-600 text-white rounded px-4 py-2 hover:bg-emerald-700 cursor-pointer';
                                        });
                                        resultsContainer.appendChild(div);
                                    });
                                }
                                resultsContainer.classList.remove('hidden');
                            })
                            .catch(err => console.error('Wikidata search error:', err));
                    }, 300);
                });

                document.addEventListener('click', function(e) {
                    if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
                        resultsContainer.classList.add('hidden');
                    }
                });
                </script>
            <?php endif; ?>

            <div class="space-y-4">
                <?php foreach (($list['items'] ?? []) as $index => $item): ?>
                    <div class="bg-white rounded shadow p-4 flex justify-between items-start">
                        <div class="flex-grow">
                            <div class="flex items-baseline space-x-2">
                                <span class="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded text-gray-600"><?= $index + 1 ?></span>
                                <a href="https://www.wikidata.org/wiki/<?= urlencode($item['qid']) ?>" target="_blank" class="text-emerald-700 font-bold hover:underline">
                                    <?= htmlspecialchars($item['label']) ?> (<?= htmlspecialchars($item['qid']) ?>)
                                </a>
                            </div>
                            <?php if (!empty($item['wikidata_desc'])): ?>
                                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($item['wikidata_desc']) ?></p>
                            <?php endif; ?>
                            
                            <!-- Comment Section -->
                            <?php if (!empty($item['comment']) || $isOwner): ?>
                                <div class="mt-2">
                                    <div id="commentView_<?= $index ?>" class="text-sm <?= !empty($item['comment']) ? 'bg-gray-50 p-2 rounded border-l-2 border-emerald-500' : '' ?> flex justify-between items-start">
                                        <div><?= render_markdown($item['comment']) ?></div>
                                        <?php if ($isOwner): ?>
                                            <button onclick="document.getElementById('commentView_<?= $index ?>').classList.add('hidden'); document.getElementById('commentEdit_<?= $index ?>').classList.remove('hidden');" class="text-xs text-emerald-700 ml-2 hover:underline shrink-0">
                                                <?= !empty($item['comment']) ? '✏️ Edit' : '💬 Add comment' ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($isOwner): ?>
                                        <div id="commentEdit_<?= $index ?>" class="<?= !empty($item['comment']) ? 'hidden' : '' ?> mt-1">
                                            <form action="index.php?action=edit_item_comment" method="POST" class="space-y-2">
                                                <input type="hidden" name="list_id" value="<?= htmlspecialchars($listId) ?>">
                                                <input type="hidden" name="index" value="<?= $index ?>">
                                                <textarea name="comment" placeholder="Add short comment (Markdown supported)..." class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-emerald-500" rows="2"><?= htmlspecialchars($item['comment']) ?></textarea>
                                                <div class="flex space-x-2">
                                                    <button type="submit" class="bg-emerald-600 text-white px-3 py-1 rounded text-xs hover:bg-emerald-700">Save Comment</button>
                                                    <?php if (!empty($item['comment'])): ?>
                                                        <button type="button" onclick="document.getElementById('commentEdit_<?= $index ?>').classList.add('hidden'); document.getElementById('commentView_<?= $index ?>').classList.remove('hidden');" class="bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs hover:bg-gray-300">Cancel</button>
                                                    <?php endif; ?>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                        </div>

                        <?php if ($isOwner): ?>
                            <div class="flex items-center space-x-1 ml-4 shrink-0">
                                <form action="index.php?action=reorder_items" method="POST">
                                    <input type="hidden" name="list_id" value="<?= htmlspecialchars($listId) ?>">
                                    <input type="hidden" name="index" value="<?= $index ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" <?= $index === 0 ? 'disabled' : '' ?> class="px-2 py-1 bg-gray-100 rounded text-xs hover:bg-gray-200 disabled:opacity-30">▲</button>
                                </form>
                                <form action="index.php?action=reorder_items" method="POST">
                                    <input type="hidden" name="list_id" value="<?= htmlspecialchars($listId) ?>">
                                    <input type="hidden" name="index" value="<?= $index ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" <?= $index === count($list['items']) - 1 ? 'disabled' : '' ?> class="px-2 py-1 bg-gray-100 rounded text-xs hover:bg-gray-200 disabled:opacity-30">▼</button>
                                </form>
                                <form action="index.php?action=delete_item" method="POST">
                                    <input type="hidden" name="list_id" value="<?= htmlspecialchars($listId) ?>">
                                    <input type="hidden" name="index" value="<?= $index ?>">
                                    <button type="submit" class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs hover:bg-red-200">✕</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($action === 'profile'): ?>
        <?php 
        $reqUser = $_GET['username'] ?? '';
        $profileData = load_user_file($reqUser);
        ?>
        <div class="bg-white rounded shadow p-6 mb-6">
            <h1 class="text-2xl font-bold">Public Profile: <?= htmlspecialchars($reqUser) ?></h1>
            <p class="text-xs text-gray-400 mt-1">Permalink: <code><?= htmlspecialchars($_SERVER['REQUEST_URI']) ?></code></p>
        </div>

        <h2 class="text-xl font-bold mb-4">Lists by <?= htmlspecialchars($reqUser) ?></h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach (($profileData['lists'] ?? []) as $list): ?>
                <div class="bg-white rounded shadow p-4">
                    <h3 class="font-bold text-lg text-emerald-800">
                        <a href="index.php?action=view_list&owner=<?= urlencode($reqUser) ?>&list_id=<?= $list['id'] ?>" class="hover:underline">
                            <?= htmlspecialchars($list['title']) ?>
                        </a>
                    </h3>
                    <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($list['description']) ?></p>
                    <p class="text-xs text-gray-400 mt-2"><?= count($list['items']) ?> Items · <?= $list['upvotes'] ?? 0 ?> Upvotes</p>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($action === 'followed' && $user): ?>
        <?php $uData = load_user_file($user); ?>
        <h1 class="text-2xl font-bold mb-4">Privately Followed Lists</h1>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach (($uData['following'] ?? []) as $followKey): 
                list($fOwner, $fListId) = explode('/', $followKey);
                $fOwnerData = load_user_file($fOwner);
                $fList = $fOwnerData['lists'][$fListId] ?? null;
                if (!$fList) continue;
            ?>
                <div class="bg-white rounded shadow p-4">
                    <h3 class="font-bold text-lg text-emerald-800">
                        <a href="index.php?action=view_list&owner=<?= urlencode($fOwner) ?>&list_id=<?= $fListId ?>" class="hover:underline">
                            <?= htmlspecialchars($fList['title']) ?>
                        </a>
                    </h3>
                    <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($fList['description']) ?></p>
                    <p class="text-xs text-gray-400 mt-2">By <?= htmlspecialchars($fOwner) ?> · <?= count($fList['items']) ?> Items</p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    </main>
</body>
</html>
