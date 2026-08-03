<?php
session_start();
date_default_timezone_set('Asia/Jakarta'); // GMT+7 Timezone

$ADMIN_PASS = 'setup yours!';
$DATA_DIR = __DIR__ . '/board_data';
$IMG_DIR = __DIR__ . '/board_images';
$MAX_ALIVE = 500;
$POSTS_PER_FILE = 100;

if (!is_dir($DATA_DIR)) mkdir($DATA_DIR, 0777, true);
if (!is_dir($IMG_DIR)) mkdir($IMG_DIR, 0777, true);

$indexFile = $DATA_DIR . '/index.json';
if (!file_exists($indexFile)) {
    file_put_contents($indexFile, json_encode([
        'last_id' => 0, 
        'boards' => ['b' => ['uri' => 'b', 'name' => 'Random']], 
        'threads' => [], 
        'archived' => [], 
        'banned' => [],
        'readonly' => false
    ]));
}

if (!isset($_COOKIE['uid'])) {
    $uid = bin2hex(random_bytes(16));
    setcookie('uid', $uid, time() + (86400 * 365), "/");
    $_COOKIE['uid'] = $uid;
}
$user_id = $_COOKIE['uid'];

function getIndex() { global $indexFile; return json_decode(file_get_contents($indexFile), true); }
function saveIndex($data) { global $indexFile; file_put_contents($indexFile, json_encode($data, JSON_PRETTY_PRINT)); }
function getPostFile($id) { global $DATA_DIR, $POSTS_PER_FILE; return $DATA_DIR . '/posts_' . ceil($id / $POSTS_PER_FILE) . '.json'; }
function savePost($post) {
    $file = getPostFile($post['id']);
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $data[$post['id']] = $post;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}
function loadPostsForThread($thread_id, $idx) {
    $posts = [];
    if (!isset($idx['threads'][$thread_id]) && !isset($idx['archived'][$thread_id])) return $posts;
    $tinfo = $idx['threads'][$thread_id] ?? $idx['archived'][$thread_id];
    foreach ($tinfo['posts'] as $pid) {
        $file = getPostFile($pid);
        if (file_exists($file)) {
            $chunkData = json_decode(file_get_contents($file), true);
            if (isset($chunkData[$pid])) $posts[] = $chunkData[$pid];
        }
    }
    return $posts;
}
function formatText($text) {
    $text = htmlspecialchars($text);
    $text = preg_replace('/^&gt;(.*)$/m', '<span class="greentext">&gt;$1</span>', $text);
    $text = preg_replace('/&gt;&gt;(\d+)/', '<a href="#p$1" class="quote">&gt;&gt;$1</a>', $text);
    return nl2br($text);
}

$idx = getIndex();
if (!isset($idx['boards'])) {
    $idx['boards'] = ['b' => ['uri' => 'b', 'name' => 'Random']];
    if (isset($idx['threads'])) {
        foreach ($idx['threads'] as $k => $v) {
            $idx['threads'][$k]['board'] = 'b';
        }
    }
    if (isset($idx['archived'])) {
        foreach ($idx['archived'] as $k => $v) {
            $idx['archived'][$k]['board'] = 'b';
        }
    }
    saveIndex($idx);
}
if (!isset($idx['readonly'])) {
    $idx['readonly'] = false;
    saveIndex($idx);
}

$is_admin = isset($_SESSION['is_admin']);
$action = $_GET['action'] ?? 'front';
$current_board = $_GET['board'] ?? array_key_first($idx['boards']);
if (!$current_board || !isset($idx['boards'][$current_board])) $current_board = 'b';

if (isset($idx['banned'][$user_id]) && !$is_admin) {
    die("<div style='padding:20px; font-family:sans-serif;'><h1>You are banned.</h1><p>Reason: " . htmlspecialchars($idx['banned'][$user_id]['reason']) . "</p></div>");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['admin_login'])) {
        if ($_POST['password'] === $ADMIN_PASS) $_SESSION['is_admin'] = true;
        header("Location: ?board=$current_board");
        exit;
    }
    if (isset($_POST['admin_logout'])) {
        unset($_SESSION['is_admin']);
        header("Location: ?board=$current_board");
        exit;
    }

    if ($is_admin) {
        if (isset($_POST['toggle_readonly'])) {
            $idx['readonly'] = !($idx['readonly'] ?? false);
            saveIndex($idx);
            header("Location: ?board=$current_board"); exit;
        }
        if (isset($_POST['create_board'])) {
            $uri = preg_replace('/[^a-z0-9]/', '', strtolower($_POST['board_uri']));
            $name = trim($_POST['board_name']);
            if ($uri && $name) {
                $idx['boards'][$uri] = ['uri' => $uri, 'name' => $name];
                saveIndex($idx);
            }
            header("Location: ?board=$current_board"); exit;
        }
        if (isset($_POST['delete_board'])) {
            unset($idx['boards'][$_POST['target_board']]);
            saveIndex($idx);
            header("Location: ?"); exit;
        }
        if (isset($_POST['delete_post'])) {
            $tid = (int)$_POST['tid'];
            $pid = (int)$_POST['pid'];
            if (isset($idx['threads'][$tid])) {
                if ($idx['threads'][$tid]['posts'][0] == $pid) {
                    unset($idx['threads'][$tid]);
                } else {
                    $idx['threads'][$tid]['posts'] = array_values(array_diff($idx['threads'][$tid]['posts'], [$pid]));
                }
                saveIndex($idx);
            }
            header("Location: " . $_SERVER['HTTP_REFERER']); exit;
        }
        if (isset($_POST['ban_user'])) {
            $idx['banned'][$_POST['target_uid']] = ['reason' => $_POST['ban_reason'], 'expires' => 0];
            saveIndex($idx);
            header("Location: " . $_SERVER['HTTP_REFERER']); exit;
        }
    }

    if (isset($_POST['message'])) {
        if (!empty($idx['readonly'])) {
            die("<div style='padding:20px; font-family:sans-serif;'><h1>Board Closed</h1><p>The site is currently in read-only mode.</p></div>");
        }
        $idx['last_id']++;
        $new_id = $idx['last_id'];
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $imagePath = time() . '_' . rand(1000,9999) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $IMG_DIR . '/' . $imagePath);
        }
        $post = [
            'id' => $new_id, 'uid' => $user_id, 'title' => $_POST['title'] ?? '',
            'message' => $_POST['message'], 'image' => $imagePath, 'time' => time(),
            'is_op' => !isset($_POST['thread_id']),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ];
        savePost($post);

        if ($post['is_op']) {
            $idx['threads'][$new_id] = ['id' => $new_id, 'board' => $current_board, 'bump' => time(), 'posts' => [$new_id]];
            $board_threads = array_filter($idx['threads'], function($t) use ($current_board) { return $t['board'] === $current_board; });
            if (count($board_threads) > $MAX_ALIVE) {
                uasort($board_threads, function($a, $b) { return $b['bump'] - $a['bump']; });
                $oldest_id = array_key_last($board_threads);
                $idx['archived'][$oldest_id] = $idx['threads'][$oldest_id];
                unset($idx['threads'][$oldest_id]);
            }
        } else {
            $tid = (int)$_POST['thread_id'];
            if (isset($idx['threads'][$tid])) {
                $idx['threads'][$tid]['posts'][] = $new_id;
                $idx['threads'][$tid]['bump'] = time();
            }
        }
        saveIndex($idx);
        header("Location: ?board=$current_board&action=" . ($post['is_op'] ? "front" : "thread&id=" . $_POST['thread_id']));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imageboard</title>
    <style>
        :root { --bg: #1e1e24; --panel: #2b2b36; --text: #e0e0e0; --accent: #ff6b6b; --green: #78c278; }
        body { background: var(--bg); color: var(--text); font-family: -apple-system, sans-serif; margin: 0; padding: 20px; font-size: 14px; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .greentext { color: var(--green); }
        .board-list { text-align: center; margin-bottom: 20px; font-size: 1.2em; font-weight: bold; }
        .board-header { text-align: center; margin-bottom: 30px; }
        .admin-panel { background: #4a2c2c; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
        .admin-panel form { display: inline-block; margin: 0 10px; }
        .admin-panel input, .admin-panel button { padding: 5px; background: var(--bg); color: var(--text); border: 1px solid #555; }
        .post-form { background: var(--panel); padding: 15px; border-radius: 8px; max-width: 500px; margin: 0 auto 30px; display: flex; flex-direction: column; gap: 10px; }
        .post-form input, .post-form textarea { width: 100%; padding: 8px; box-sizing: border-box; background: var(--bg); border: 1px solid #444; color: var(--text); }
        .post-form button { background: var(--accent); color: white; border: none; padding: 10px; cursor: pointer; font-weight: bold; }
        .thread { background: var(--panel); padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .post { margin-top: 10px; padding: 10px; background: rgba(0,0,0,0.2); border-left: 3px solid var(--accent); }
        .post-header { font-size: 0.9em; color: #aaa; margin-bottom: 8px; }
        .post-image { max-width: 250px; max-height: 250px; display: block; margin-bottom: 10px; cursor: pointer; }
        .inline-form { display: inline; margin: 0; }
        .inline-btn { background: none; border: none; color: #ff4444; cursor: pointer; font-size: 0.9em; padding: 0; text-decoration: underline; }

        .catalog-grid { display: flex; flex-wrap: wrap; gap: 15px; justify-content: center; }
        .catalog-card { background: var(--panel); width: 190px; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #3d3d4e; display: flex; flex-direction: column; align-items: center; color: var(--text); transition: border-color 0.2s; }
        .catalog-card:hover { border-color: var(--accent); text-decoration: none; }
        .catalog-thumb { width: 160px; height: 160px; object-fit: cover; border-radius: 4px; margin-bottom: 8px; }
        .catalog-no-img { width: 160px; height: 160px; background: rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; border-radius: 4px; margin-bottom: 8px; color: #777; font-size: 0.85em; }
        .catalog-title { font-weight: bold; font-size: 0.95em; margin-bottom: 6px; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--accent); }
        .catalog-teaser { font-size: 0.8em; color: #ccc; max-height: 3.6em; overflow: hidden; line-height: 1.2em; word-break: break-word; width: 100%; margin-bottom: 8px; }
        .catalog-meta { font-size: 0.75em; color: #aaa; margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 6px; width: 100%; }
        .uid-info { display: none; background: #111; color: #ffb74d; padding: 2px 6px; border-radius: 4px; font-size: 0.85em; margin: 0 4px; border: 1px solid #ffb74d; }
    </style>
</head>
<body>

<div class="board-list">
    [ <?php foreach ($idx['boards'] as $uri => $b) echo "<a href='?board={$uri}'>/{$uri}/</a> "; ?> ]
</div>

<?php if ($is_admin): ?>
    <div class="admin-panel">
        <strong>Admin Control:</strong>
        <form method="POST">
            <button type="submit" name="toggle_readonly" style="background: <?= !empty($idx['readonly']) ? '#ff4444' : '#78c278' ?>; color: white; cursor: pointer;">
                Site Status: <?= !empty($idx['readonly']) ? 'CLOSED (Read-Only)' : 'OPEN' ?>
            </button>
        </form>
        <form method="POST">
            <input type="text" name="board_uri" placeholder="URI (e.g. g)" size="5" required>
            <input type="text" name="board_name" placeholder="Board Name" required>
            <button type="submit" name="create_board">Create Board</button>
        </form>
        <form method="POST">
            <input type="hidden" name="target_board" value="<?= htmlspecialchars($current_board) ?>">
            <button type="submit" name="delete_board" onclick="return confirm('Delete current board?')">Delete Current Board</button>
        </form>
        <form method="POST"><button type="submit" name="admin_logout">Logout</button></form>
    </div>
<?php endif; ?>

<div class="board-header">
    <h1>/<?= htmlspecialchars($idx['boards'][$current_board]['uri'] ?? '') ?>/ - <?= htmlspecialchars($idx['boards'][$current_board]['name'] ?? '') ?></h1>
    <a href="?board=<?= htmlspecialchars($current_board) ?>&action=front">Front Page</a> | 
    <a href="?board=<?= htmlspecialchars($current_board) ?>&action=archive">Archive</a>
</div>

<?php if (!empty($idx['readonly'])): ?>
    <div style="text-align:center; padding:15px; background:var(--panel); max-width:500px; margin:0 auto 30px; border-radius:8px; border:1px solid #ff4444;">
        <strong>Notice:</strong> The site is currently closed for posting (Read-Only Mode).
    </div>
<?php elseif ($action === 'front' || $action === 'thread'): ?>
    <form class="post-form" method="POST" enctype="multipart/form-data">
        <?php if ($action === 'thread'): ?>
            <input type="hidden" name="thread_id" value="<?= htmlspecialchars($_GET['id']) ?>">
        <?php else: ?>
            <input type="text" name="title" placeholder="Thread Title (Optional)">
        <?php endif; ?>
        <textarea name="message" rows="4" placeholder="Message" required></textarea>
        <input type="file" name="image" accept="image/*">
        <button type="submit">Post</button>
    </form>
<?php endif; ?>

<?php
if ($action === 'front') {
    $display_threads = array_filter($idx['threads'], function($t) use ($current_board) { return $t['board'] === $current_board; });
    uasort($display_threads, function($a, $b) { return $b['bump'] - $a['bump']; });

    echo "<div class='catalog-grid'>";
    foreach ($display_threads as $tid => $tinfo) {
        $posts = loadPostsForThread($tid, $idx);
        if (empty($posts)) continue;
        $op = $posts[0];
        $reply_count = count($posts) - 1;
        $date_str = date('D, M j, Y, g:i A', $op['time']);
        $title = !empty($op['title']) ? htmlspecialchars($op['title']) : 'No Title';
        $teaser = htmlspecialchars(mb_strimwidth($op['message'], 0, 100, '...'));
        
        echo "<a href='?board={$current_board}&action=thread&id={$tid}' class='catalog-card'>";
        if (!empty($op['image'])) {
            echo "<img src='board_images/{$op['image']}' class='catalog-thumb' alt='Thumbnail'>";
        } else {
            echo "<div class='catalog-no-img'>No Image</div>";
        }
        echo "<div class='catalog-title'>{$title}</div>";
        echo "<div class='catalog-teaser'>{$teaser}</div>";
        echo "<div class='catalog-meta'>";
        echo "R: <strong>{$reply_count}</strong> | No.{$op['id']}<br>";
        echo "<span style='font-size:0.85em;'>{$date_str}</span>";
        echo "</div>";
        echo "</a>";
    }
    echo "</div>";

} else {
    $display_threads = [];
    if ($action === 'archive') {
        $display_threads = array_filter($idx['archived'], function($t) use ($current_board) { return $t['board'] === $current_board; });
        echo "<h2 style='text-align:center;'>Archived Threads</h2>";
    } elseif ($action === 'thread' && isset($_GET['id'])) {
        $tid = (int)$_GET['id'];
        if (isset($idx['threads'][$tid])) $display_threads = [$tid => $idx['threads'][$tid]];
        elseif (isset($idx['archived'][$tid])) $display_threads = [$tid => $idx['archived'][$tid]];
    }

    foreach ($display_threads as $tid => $tinfo) {
        $posts = loadPostsForThread($tid, $idx);
        if (empty($posts)) continue;
        
        echo "<div class='thread' id='t{$tid}'>";
        foreach ($posts as $i => $p) {
            $is_op = ($i === 0);
            $class = $is_op ? "op" : "post";
            $formatted_date = date('D, M j, Y, g:i A', $p['time']);
            echo "<div class='{$class}' id='p{$p['id']}'>";
            echo "<div class='post-header'>";
            echo "Anonymous " . $formatted_date . " No.<a href='?board={$current_board}&action=thread&id={$tid}#p{$p['id']}'>{$p['id']}</a> ";
            if ($is_op && $p['title']) echo "<strong>" . htmlspecialchars($p['title']) . "</strong> ";
            
            if ($is_admin) {
                $p_ip = htmlspecialchars($p['ip'] ?? 'N/A');
                $p_ua = htmlspecialchars($p['user_agent'] ?? 'N/A');
                echo " <span style='color:red; cursor:pointer; text-decoration:underline;' onclick=\"toggleUidInfo('info_{$p['id']}')\">[UID: " . substr($p['uid'], 0, 8) . "]</span>";
                echo "<span id='info_{$p['id']}' class='uid-info'>IP: {$p_ip} | UA: {$p_ua}</span> ";
                echo "<form class='inline-form' method='POST'><input type='hidden' name='tid' value='{$tid}'><input type='hidden' name='pid' value='{$p['id']}'><button type='submit' name='delete_post' class='inline-btn'>[Delete]</button></form> ";
                echo "<form class='inline-form' method='POST' onsubmit=\"return prompt('Ban Reason:');\"><input type='hidden' name='target_uid' value='{$p['uid']}'><input type='hidden' name='ban_reason' id='br_{$p['id']}'><button type='submit' name='ban_user' class='inline-btn' onclick=\"document.getElementById('br_{$p['id']}').value = prompt('Reason for ban?'); if(!document.getElementById('br_{$p['id']}').value) return false;\">[Ban User]</button></form>";
            }
            echo "</div>";
            
            if ($p['image']) echo "<img src='board_images/{$p['image']}' class='post-image' onclick='window.open(this.src)'>";
            echo "<div class='post-body'>" . formatText($p['message']) . "</div>";
            echo "</div>";
        }
        echo "</div>";
    }
}
?>

<div style="text-align:center; margin-top:50px; color:#666;">
    📻📻📻📻📻<span onclick="document.getElementById('admin-modal').style.display='block'" style="cursor:pointer;">📻</span>📻
</div>

<div id="admin-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:var(--panel); padding:20px; border-radius:8px;">
    <form method="POST">
        <h3>Admin Login</h3>
        <input type="password" name="password" style="margin-bottom:10px; width:100%; padding:8px;">
        <button type="submit" name="admin_login" style="width:100%; padding:8px; background:var(--accent); color:white; border:none;">Login</button>
    </form>
</div>
<script>
    function toggleUidInfo(id) {
        let el = document.getElementById(id);
        if (el) {
            el.style.display = (el.style.display === 'inline-block') ? 'none' : 'inline-block';
        }
    }

    document.querySelectorAll('.quote').forEach(link => {
        link.addEventListener('click', function(e) {
            let targetId = this.getAttribute('href').substring(1);
            let targetPost = document.getElementById(targetId);
            if(targetPost) {
                targetPost.style.background = 'rgba(255, 107, 107, 0.2)';
                setTimeout(() => targetPost.style.background = '', 2000);
            }
        });
    });
</script>
</body>
</html>
