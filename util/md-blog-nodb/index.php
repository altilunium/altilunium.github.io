<?php
date_default_timezone_set('Asia/Jakarta');

if (isset($_GET['page'])) {
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['page']));
    $file = 'pages/' . $slug . '.json';
    if (file_exists($file)) {
        $post = json_decode(file_get_contents($file), true);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <link rel="icon" href="https://static.miraheze.org/pustakawiki/4/42/Logo_pustaka.png">
            <title><?= htmlspecialchars($slug) ?></title>
            <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
            <style>
                @font-face {
    font-family: 'Noto';
    font-style: normal;
    font-weight: normal;
    src: url('noto.woff2') format('woff');
}

                body { background: #121212; color: #e0e0e0; font-family: -apple-system, sans-serif; line-height: 1.6; margin: 0; padding: 20px; font-size: 14px;}
                .container { max-width: 600px; margin: 0 auto; }
                a { color: #1da1f2; text-decoration: none; }
                a:hover { text-decoration: underline; }
                .markdown-body img { max-width: 100%; height: auto; border-radius: 4px; margin-top: 10px; }

                .post-content {
            width: 95%;
            border: 0px;
            overflow: hidden;
            resize: none;
            font-family: 'Noto';
            color: #D8D8D8;
            font-size: 14px !important;
            line-height: 21px;
            font-weight: 400;
            margin-top: 47px;
            margin: 0px auto 0px auto;
            max-width: 750px;
        }
    
    .post-content img {
    display: block;
    margin-left: auto;
    margin-right: auto;
    max-width: 100%;
    height: auto;
}
    

        .post-content blockquote {
            border-left: 1px solid #cacaca;
            padding-left: 9px;
            margin-left: 19px;
            margin-right: 7px;
        }

        .post-content blockquote p {
            padding-top: 4px;
            padding-bottom: 4px;
        }

        .post-content h1 {
            text-align: center;
            margin-bottom: 44px;
            line-height: 38px;
            margin-top:22px;
        }

        .post-content h3 {
            display: block;
            font-size: 1.17em;
            margin-block-start: 1em;
            margin-block-end: 1em;
            margin-inline-start: 0px;
            margin-inline-end: 0px;
            font-weight: bold;
            unicode-bidi: isolate;
        }

        .post-content p {
            margin: 19px 0px;
            display: block;
            margin-block-start: 1em;
            margin-block-end: 1em;
            margin-inline-start: 0px;
            margin-inline-end: 0px;
            unicode-bidi: isolate;
        }

        .post-content ol {
            display: block;
            list-style-type: decimal;
            margin-block-start: 1em;
            margin-block-end: 1em;
            padding-inline-start: 40px;
            unicode-bidi: isolate;
        }

        .post-content ul {
            display: block;
            list-style-type: disc;
            margin-block-start: 1em;
            margin-block-end: 1em;
            padding-inline-start: 40px;
            unicode-bidi: isolate;
        }

        .post-content hr{
            width: 100%;
            border: #2b2828 1px solid;
            margin: 39px 0px;
        }

        .post-content p { margin: 0 0 10px 0; }
        .post-content pre { background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto; }
        .post-content code { font-family: monospace; background: #eee; padding: 2px 4px; border-radius: 3px; }

            </style>
        </head>
        <body>
            <div class="container">
                <div id="content" class="markdown-body post-content"></div>
                <div class="images">
                    <?php
                    if (!empty($post['images'])) {
                        foreach ($post['images'] as $img) {
                            echo '<img src="' . htmlspecialchars($img) . '" style="display:block; margin-bottom:10px;">';
                        }
                    }
                    ?>
                </div>
            </div>
            <script>
                document.getElementById('content').innerHTML = marked.parse(<?= json_encode($post['text']) ?>);
            </script>
        </body>
        </html>
        <?php
        exit;
    } else {
        http_response_code(404);
        echo "Page not found.";
        exit;
    }
}

$PASSWORD = 'ENTER YOURS HERE';
$SECRET_SALT = 'chronoo_secret_salt';
$COOKIE_NAME = 'chronoo_auth_cookie';

function isUserAuthenticated() {
    global $COOKIE_NAME, $PASSWORD, $SECRET_SALT;
    return isset($_COOKIE[$COOKIE_NAME]) && $_COOKIE[$COOKIE_NAME] === hash('sha256', $PASSWORD . $SECRET_SALT);
}

function rebuildIndex() {
    if (!is_dir('pages')) mkdir('pages', 0755, true);
    $files = glob('pages/*.json');
    $index = [];
    if ($files !== false) {
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data) && isset($data['id'])) {
                $index[] = [
                    'id' => $data['id'],
                    'title' => $data['title'] ?? 'Untitled',
                    'slug' => $data['slug'],
                    'timestamp' => $data['timestamp'],
                    'dateString' => $data['dateString']
                ];
            }
        }
        usort($index, function($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });
    }
    file_put_contents('index.json', json_encode($index));
    return $index;
}

function getIndex() {
    if (!file_exists('index.json')) return rebuildIndex();
    return json_decode(file_get_contents('index.json'), true) ?: rebuildIndex();
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $requestData = json_decode(file_get_contents('php://input'), true);

    if ($_GET['api'] === 'login') {
        if (isset($requestData['password']) && $requestData['password'] === $PASSWORD) {
            setcookie($COOKIE_NAME, hash('sha256', $PASSWORD . $SECRET_SALT), time() + (365 * 24 * 60 * 60), "/");
            echo json_encode(['success' => true]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
        }
        exit;
    }

    if ($_GET['api'] === 'index') {
        echo json_encode(getIndex());
        exit;
    }

    if (!isUserAuthenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    if ($_GET['api'] === 'edit' || $_GET['api'] === 'delete') {
        $targetId = $requestData['id'];
        $files = glob('pages/*.json');
        $operationSuccessful = false;
        
        if ($files !== false) {
            foreach ($files as $file) {
                $post = json_decode(file_get_contents($file), true);
                if (is_array($post) && isset($post['id']) && $post['id'] == $targetId) {
                    if ($_GET['api'] === 'edit') {
                        $post['title'] = $requestData['title'];
                        $post['text'] = $requestData['text'];
                        file_put_contents($file, json_encode($post, JSON_PRETTY_PRINT));
                    } else if ($_GET['api'] === 'delete') {
                        if (!empty($post['images']) && is_array($post['images'])) {
                            foreach ($post['images'] as $imgPath) {
                                if (file_exists($imgPath) && is_file($imgPath)) {
                                    unlink($imgPath);
                                }
                            }
                        }
                        unlink($file);
                    }
                    $operationSuccessful = true;
                    rebuildIndex();
                    break;
                }
            }
        }
        echo json_encode(['success' => $operationSuccessful]);
        exit;
    }

    if ($_GET['api'] === 'post') {
        if (!is_dir('pages')) mkdir('pages', 0755, true);
        
        $title = trim($requestData['title'] ?? '');
        if (empty($title)) {
            http_response_code(400);
            echo json_encode(['error' => 'Title is mandatory']);
            exit;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
        $slug = trim($slug, '-');
        if (empty($slug)) $slug = 'post-' . time();

        $targetFile = "pages/{$slug}.json";
        if (file_exists($targetFile)) {
            $slug = $slug . '-' . time();
            $targetFile = "pages/{$slug}.json";
        }

        $processedImages = [];
        if (!empty($requestData['images'])) {
            if (!is_dir('images')) mkdir('images', 0755, true);
            foreach ($requestData['images'] as $imgData) {
                if (preg_match('/^data:image\/(\w+);base64,/', $imgData, $type)) {
                    $encodedData = substr($imgData, strpos($imgData, ',') + 1);
                    $fileExtension = strtolower($type[1]);
                    $fileExtension = str_replace('jpeg', 'jpg', $fileExtension);
                    $decodedData = base64_decode($encodedData);
                    $newFilename = 'images/' . uniqid() . '.' . $fileExtension;
                    file_put_contents($newFilename, $decodedData);
                    $processedImages[] = $newFilename;
                } else {
                    $processedImages[] = $imgData;
                }
            }
        }
        
        $newPost = [
            'id' => round(microtime(true) * 1000),
            'title' => $title,
            'slug' => $slug,
            'text' => $requestData['text'],
            'images' => $processedImages,
            'timestamp' => round(microtime(true) * 1000),
            'dateString' => date('Y-m-d')
        ];
        
        file_put_contents($targetFile, json_encode($newPost, JSON_PRETTY_PRINT));
        rebuildIndex();
        echo json_encode($newPost);
        exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>roll v.26.8.2</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <style>
        :root {
            --bg: #121212; --surface: #1e1e1e; --border: #333;
            --text: #e0e0e0; --text-muted: #888; --accent: #1da1f2;
            --danger: #f44336; --radius: 4px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, sans-serif; background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.4; }
        .container { max-width: 600px; margin: 0 auto; border-left: 1px solid var(--border); border-right: 1px solid var(--border); min-height: 100vh; display: flex; flex-direction: column; }
        .composer { padding: 15px; border-bottom: 1px solid var(--border); background: var(--surface); }
        .title-input { width: 100%; background: transparent; border: 1px solid var(--border); color: var(--text); padding: 8px; margin-bottom: 10px; border-radius: var(--radius); font-size: 16px; font-weight: bold; }
        textarea { width: 100%; background: transparent; border: 1px solid var(--border); color: var(--text); padding: 8px; border-radius: var(--radius); resize: vertical; outline: none; min-height: 100px; font-size: 14px; }
        .composer-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
        button { background: var(--accent); color: white; border: none; padding: 6px 14px; border-radius: var(--radius); font-weight: bold; cursor: pointer; }
        .action-btn { background: transparent; color: var(--text-muted); padding: 4px 8px; border: 1px solid var(--border); font-weight: normal; font-size: 12px; }
        .staging-area { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; margin-bottom: 4px; }
        .staging-img-container { position: relative; width: 60px; height: 60px; }
        .staging-img { width: 100%; height: 100%; object-fit: cover; border-radius: 4px; border: 1px solid var(--border); }
        .remove-img { position: absolute; top: -5px; right: -5px; background: var(--danger); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 12px; text-align: center; line-height: 18px; cursor: pointer; }
        .post-link-container { padding: 15px; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; }
        .post-link { color: var(--accent); font-size: 18px; font-weight: bold; text-decoration: none; margin-bottom: 4px; }
        .post-link:hover { text-decoration: underline; }
        .post-meta { font-size: 12px; color: var(--text-muted); }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 1000; flex-direction: column; }
    </style>
</head>
<body>

<div class="container">
    <div id="mainUI">
        <div class="composer">
            <input type="text" id="postTitle" class="title-input" placeholder="Page Title (Mandatory for URL)">
            <textarea id="postText" placeholder="Write your content here (Markdown supported)"></textarea>
            <div id="staging-main" class="staging-area"></div>
            <div class="composer-actions">
                <button class="action-btn" onclick="triggerImageInput()">📷 Attach Image</button>
                <button id="postBtn">Publish Page</button>
            </div>
        </div>
    </div>
    <div id="feed"></div>
</div>

<input type="file" id="globalImageInput" accept="image/*" style="display:none;">
<div id="cropperModal" class="modal">
    <div style="flex:1; min-height:0; display:flex; align-items:center; justify-content:center;">
        <img id="cropperImage" src="" style="max-width:100%; max-height:100%;">
    </div>
    <div style="padding:15px; display:flex; justify-content:flex-end; gap:10px; background:var(--surface)">
        <button onclick="closeCropper()" style="background:var(--border)">Cancel</button>
        <button id="applyCropBtn">Crop & Keep</button>
    </div>
</div>

<script>
    let indexData = [];
    let stagedImages = [];
    let cropper = null;

    async function fetchIndexAndRender() {
        try {
            const res = await fetch('?api=index');
            if (res.ok) {
                indexData = await res.json();
                renderTitleList();
            }
        } catch (e) {
            console.error("Failed to load index:", e);
        }
    }

    function renderTitleList() {
        const feed = document.getElementById('feed');
        feed.innerHTML = '';
        indexData.forEach(item => {
            const el = document.createElement('div');
            el.className = 'post-link-container';
            const dateStr = new Date(item.timestamp).toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' });
            el.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div style="display:flex; flex-direction:column;">
                        <a href="?page=${encodeURIComponent(item.slug)}" class="post-link">${item.title}</a>
                        <span class="post-meta">Published on ${dateStr}</span>
                    </div>
                    <div style="display:flex; gap:5px;">
                        <button class="action-btn" onclick="openEdit(${item.id}, '${item.slug}')">Edit</button>
                        <button class="action-btn" style="color:var(--danger); border-color:var(--danger);" onclick="deletePost(${item.id})">Delete</button>
                    </div>
                </div>
                <div id="edit-area-${item.id}" style="display:none; margin-top:15px; border-top:1px dashed var(--border); padding-top:15px;">
                    <input type="text" id="edit-title-${item.id}" class="title-input">
                    <textarea id="edit-text-${item.id}"></textarea>
                    <div style="display:flex; justify-content:flex-end; gap:5px; margin-top:5px;">
                        <button class="action-btn" onclick="document.getElementById('edit-area-${item.id}').style.display='none'">Cancel</button>
                        <button onclick="saveEdit(${item.id})">Save Changes</button>
                    </div>
                </div>
            `;
            feed.appendChild(el);
        });
    }

    async function executeApiAction(endpoint, payload) {
        let res = await fetch('?api=' + endpoint, {
            method: 'POST',
            body: JSON.stringify(payload),
            headers: {'Content-Type': 'application/json'}
        });
        
        if (res.status === 401) {
            const pwd = prompt("Authentication required. Enter password:");
            if (!pwd) return null;
            
            const loginRes = await fetch('?api=login', {
                method: 'POST',
                body: JSON.stringify({password: pwd}),
                headers: {'Content-Type': 'application/json'}
            });
            
            if (!loginRes.ok) {
                alert("Incorrect password.");
                return null;
            }
            
            res = await fetch('?api=' + endpoint, {
                method: 'POST',
                body: JSON.stringify(payload),
                headers: {'Content-Type': 'application/json'}
            });
        }
        
        if (res.ok) return await res.json();
        return null;
    }

    async function openEdit(id, slug) {
        const el = document.getElementById(`edit-area-${id}`);
        if (el.style.display === 'block') {
            el.style.display = 'none';
            return;
        }
        
        const res = await fetch(`pages/${slug}.json`);
        if (res.ok) {
            const post = await res.json();
            document.getElementById(`edit-title-${id}`).value = post.title;
            document.getElementById(`edit-text-${id}`).value = post.text || '';
            el.style.display = 'block';
        } else {
            alert('Could not fetch post data for editing.');
        }
    }

    async function saveEdit(id) {
        const title = document.getElementById(`edit-title-${id}`).value.trim();
        const text = document.getElementById(`edit-text-${id}`).value.trim();
        
        if (!title) {
            alert('Title cannot be empty.');
            return;
        }

        const result = await executeApiAction('edit', { id, title, text });
        if (result && result.success) {
            fetchIndexAndRender();
        }
    }

    async function deletePost(id) {
        if (confirm('Are you certain you want to permanently delete this page?')) {
            const result = await executeApiAction('delete', { id });
            if (result && result.success) {
                fetchIndexAndRender();
            }
        }
    }

    function triggerImageInput() {
        document.getElementById('globalImageInput').click();
    }

    document.getElementById('globalImageInput').onchange = (e) => {
        if (!e.target.files || e.target.files.length === 0) return;
        const reader = new FileReader();
        reader.onload = (ev) => {
            document.getElementById('cropperImage').src = ev.target.result;
            document.getElementById('cropperModal').style.display = 'flex';
            if (cropper) cropper.destroy();
            cropper = new Cropper(document.getElementById('cropperImage'), { viewMode: 1, autoCropArea: 1, background: false });
        };
        reader.readAsDataURL(e.target.files[0]);
    };

    function closeCropper() { 
        document.getElementById('cropperModal').style.display='none'; 
        if(cropper) cropper.destroy(); 
    }

    document.getElementById('applyCropBtn').onclick = () => {
        const b64 = cropper.getCroppedCanvas().toDataURL('image/jpeg', 0.8);
        stagedImages.push(b64);
        renderStaging();
        closeCropper();
        document.getElementById('globalImageInput').value = '';
    };

    window.removeStagedImage = (index) => {
        stagedImages.splice(index, 1);
        renderStaging();
    }

    function renderStaging() {
        const el = document.getElementById('staging-main');
        el.innerHTML = stagedImages.map((src, i) => `
            <div class="staging-img-container">
                <img src="${src}" class="staging-img">
                <div class="remove-img" onclick="removeStagedImage(${i})">×</div>
            </div>
        `).join('');
    }

    document.getElementById('postBtn').onclick = async () => {
        const title = document.getElementById('postTitle').value.trim();
        const text = document.getElementById('postText').value.trim();
        
        if (!title) {
            alert("A page title is mandatory.");
            return;
        }

        const payload = { title, text, images: stagedImages };
        const result = await executeApiAction('post', payload);
        
        if (result) {
            document.getElementById('postTitle').value = '';
            document.getElementById('postText').value = '';
            stagedImages = [];
            renderStaging();
            fetchIndexAndRender();
        }
    };

    fetchIndexAndRender();
</script>
</body>
</html>
