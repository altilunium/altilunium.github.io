<?php
session_start();

// -------------------------------------------------------------------------
// CONFIGURATION & CONSTANTS
// -------------------------------------------------------------------------
define('HARDCODED_PASSWORD', 'sekrit');
define('DATA_DIR', __DIR__ . '/data');
define('SENTENCE_PAGE_SIZE', 100);
define('WORD_PAGE_SIZE', 250);
define('SENTENCES_PER_VIEW', 10);
define('WORDS_PER_VIEW', 10);

// Ensure storage directories exist
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
    mkdir(DATA_DIR . '/sentences', 0755, true);
    mkdir(DATA_DIR . '/words', 0755, true);
}

// -------------------------------------------------------------------------
// JSON / FILESYSTEM STORAGE HELPER
// -------------------------------------------------------------------------
class Storage {
    private static function getIndexPath($type) {
        return DATA_DIR . '/' . $type . '_index.json';
    }

    private static function getFilePath($type, $fileIndex) {
        return DATA_DIR . '/' . $type . 's/' . $type . '_' . $fileIndex . '.json';
    }

    public static function loadIndex($type) {
        $path = self::getIndexPath($type);
        if (!file_exists($path)) {
            return ['last_id' => 0, 'mapping' => []];
        }
        return json_decode(file_get_contents($path), true) ?: ['last_id' => 0, 'mapping' => []];
    }

    public static function saveIndex($type, $indexData) {
        file_put_contents(self::getIndexPath($type), json_encode($indexData, JSON_PRETTY_PRINT));
    }

    public static function loadFile($type, $fileIndex) {
        $path = self::getFilePath($type, $fileIndex);
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?: [];
    }

    public static function saveFile($type, $fileIndex, $data) {
        file_put_contents(self::getFilePath($type, $fileIndex), json_encode($data, JSON_PRETTY_PRINT));
    }

    public static function getRecord($type, $id) {
        $index = self::loadIndex($type);
        if (!isset($index['mapping'][$id])) {
            return null;
        }
        $fileIndex = $index['mapping'][$id];
        $fileData = self::loadFile($type, $fileIndex);
        return $fileData[$id] ?? null;
    }

    public static function saveRecord($type, $record, $customId = null) {
        $index = self::loadIndex($type);
        $pageSize = ($type === 'sentence') ? SENTENCE_PAGE_SIZE : WORD_PAGE_SIZE;

        if ($customId !== null) {
            $id = $customId;
        } else {
            $id = isset($record['id']) ? $record['id'] : ($index['last_id'] + 1);
        }

        if ($id > $index['last_id']) {
            $index['last_id'] = $id;
        }

        $fileIndex = (int)ceil($id / $pageSize);
        if ($fileIndex < 1) {
            $fileIndex = 1;
        }

        $record['id'] = $id;
        $index['mapping'][$id] = $fileIndex;

        $fileData = self::loadFile($type, $fileIndex);
        $fileData[$id] = $record;

        self::saveFile($type, $fileIndex, $fileData);
        self::saveIndex($type, $index);

        return $id;
    }

    public static function deleteRecord($type, $id) {
        $index = self::loadIndex($type);
        if (!isset($index['mapping'][$id])) {
            return false;
        }
        $fileIndex = $index['mapping'][$id];
        $fileData = self::loadFile($type, $fileIndex);
        if (isset($fileData[$id])) {
            unset($fileData[$id]);
            self::saveFile($type, $fileIndex, $fileData);
        }
        unset($index['mapping'][$id]);
        self::saveIndex($type, $index);
        return true;
    }

    public static function getAllRecords($type) {
        $index = self::loadIndex($type);
        $records = [];
        $fileIndices = array_unique(array_values($index['mapping']));
        foreach ($fileIndices as $fileIndex) {
            $fileData = self::loadFile($type, $fileIndex);
            foreach ($fileData as $id => $rec) {
                $records[$id] = $rec;
            }
        }
        ksort($records);
        return $records;
    }

    public static function findWordByText($text) {
        $words = self::getAllRecords('word');
        $lower = mb_strtolower(trim($text));
        foreach ($words as $w) {
            if (mb_strtolower($w['text']) === $lower) {
                return $w;
            }
        }
        return null;
    }
}

// -------------------------------------------------------------------------
// PARSING HELPER
// -------------------------------------------------------------------------
function parseSentenceTokens($rawSentence) {
    $tokens = [];
    $pattern = '/\[([^\]]+)\]|([^\s\[\]]+)/u';
    if (preg_match_all($pattern, $rawSentence, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            if (!empty($match[1])) {
                $tokens[] = trim($match[1]);
            } elseif (!empty($match[2])) {
                $tokens[] = trim($match[2]);
            }
        }
    }
    return array_values(array_filter($tokens));
}

// -------------------------------------------------------------------------
// ROUTING & REQUEST HANDLING
// -------------------------------------------------------------------------
$message = '';
$error = '';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['logged_in']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $password = $_POST['password'] ?? '';
        if ($password === HARDCODED_PASSWORD) {
            $_SESSION['logged_in'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = 'Invalid password.';
        }
    }

    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        if ($action === 'add_sentence') {
            $rawSentence = trim($_POST['raw_sentence'] ?? '');
            $translation = trim($_POST['translation'] ?? '');
            $wordDefs = $_POST['word_defs'] ?? [];
            $wordNewDefs = $_POST['word_new_defs'] ?? [];

            if ($rawSentence !== '') {
                $tokens = parseSentenceTokens($rawSentence);
                $sentenceWordLinks = [];

                foreach ($tokens as $index => $tokenText) {
                    $selectedDefId = $wordDefs[$index] ?? '';
                    $newDefInput = trim($wordNewDefs[$index] ?? '');

                    $existingWord = Storage::findWordByText($tokenText);
                    if ($existingWord) {
                        $wordId = $existingWord['id'];
                        $definitions = $existingWord['definitions'];
                    } else {
                        $wordId = null;
                        $definitions = [];
                    }

                    $defIdToUse = null;

                    if ($selectedDefId === 'NEW' && $newDefInput !== '') {
                        $nextDefId = 1;
                        foreach ($definitions as $d) {
                            if ($d['id'] >= $nextDefId) {
                                $nextDefId = $d['id'] + 1;
                            }
                        }
                        $defIdToUse = $nextDefId;
                        $definitions[] = [
                            'id' => $defIdToUse,
                            'definition' => $newDefInput
                        ];
                    } elseif ($selectedDefId !== '' && $selectedDefId !== 'NEW') {
                        $defIdToUse = (int)$selectedDefId;
                    } elseif ($existingWord && empty($selectedDefId) && !empty($definitions)) {
                        $defIdToUse = $definitions[0]['id'];
                    } elseif (!$existingWord && $newDefInput !== '') {
                        $defIdToUse = 1;
                        $definitions[] = [
                            'id' => 1,
                            'definition' => $newDefInput
                        ];
                    }

                    $wordRecord = [
                        'id' => $wordId,
                        'text' => $tokenText,
                        'definitions' => $definitions
                    ];
                    $savedWordId = Storage::saveRecord('word', $wordRecord, $wordId);

                    $sentenceWordLinks[] = [
                        'word_id' => $savedWordId,
                        'definition_id' => $defIdToUse
                    ];
                }

                $sentenceRecord = [
                    'raw_sentence' => $rawSentence,
                    'translation' => $translation,
                    'word_links' => $sentenceWordLinks
                ];
                Storage::saveRecord('sentence', $sentenceRecord);

                $message = 'Sentence successfully saved!';
            } else {
                $error = 'Sentence cannot be empty.';
            }
        } elseif ($action === 'edit_sentence') {
            $sId = (int)($_POST['sentence_id'] ?? 0);
            $rawSentence = trim($_POST['raw_sentence'] ?? '');
            $translation = trim($_POST['translation'] ?? '');
            $wordDefs = $_POST['word_defs'] ?? [];
            $wordNewDefs = $_POST['word_new_defs'] ?? [];

            $existingSentence = Storage::getRecord('sentence', $sId);
            if ($existingSentence && $rawSentence !== '') {
                $tokens = parseSentenceTokens($rawSentence);
                $sentenceWordLinks = [];

                foreach ($tokens as $index => $tokenText) {
                    $selectedDefId = $wordDefs[$index] ?? '';
                    $newDefInput = trim($wordNewDefs[$index] ?? '');

                    $existingWord = Storage::findWordByText($tokenText);
                    if ($existingWord) {
                        $wordId = $existingWord['id'];
                        $definitions = $existingWord['definitions'];
                    } else {
                        $wordId = null;
                        $definitions = [];
                    }

                    $defIdToUse = null;

                    if ($selectedDefId === 'NEW' && $newDefInput !== '') {
                        $nextDefId = 1;
                        foreach ($definitions as $d) {
                            if ($d['id'] >= $nextDefId) {
                                $nextDefId = $d['id'] + 1;
                            }
                        }
                        $defIdToUse = $nextDefId;
                        $definitions[] = [
                            'id' => $defIdToUse,
                            'definition' => $newDefInput
                        ];
                    } elseif ($selectedDefId !== '' && $selectedDefId !== 'NEW') {
                        $defIdToUse = (int)$selectedDefId;
                    } elseif ($existingWord && empty($selectedDefId) && !empty($definitions)) {
                        $defIdToUse = $definitions[0]['id'];
                    } elseif (!$existingWord && $newDefInput !== '') {
                        $defIdToUse = 1;
                        $definitions[] = [
                            'id' => 1,
                            'definition' => $newDefInput
                        ];
                    }

                    $wordRecord = [
                        'id' => $wordId,
                        'text' => $tokenText,
                        'definitions' => $definitions
                    ];
                    $savedWordId = Storage::saveRecord('word', $wordRecord, $wordId);

                    $sentenceWordLinks[] = [
                        'word_id' => $savedWordId,
                        'definition_id' => $defIdToUse
                    ];
                }

                $sentenceRecord = [
                    'id' => $sId,
                    'raw_sentence' => $rawSentence,
                    'translation' => $translation,
                    'word_links' => $sentenceWordLinks
                ];
                Storage::saveRecord('sentence', $sentenceRecord, $sId);
                $message = 'Sentence successfully updated!';
            } else {
                $error = 'Invalid sentence update.';
            }
        } elseif ($action === 'delete_sentence') {
            $sId = (int)($_POST['sentence_id'] ?? 0);
            if (Storage::deleteRecord('sentence', $sId)) {
                $message = 'Sentence deleted successfully.';
            } else {
                $error = 'Failed to delete sentence.';
            }
        } elseif ($action === 'edit_word') {
            $wId = (int)($_POST['word_id'] ?? 0);
            $wordText = trim($_POST['word_text'] ?? '');
            $defIds = $_POST['def_ids'] ?? [];
            $defTexts = $_POST['def_texts'] ?? [];
            $newDefText = trim($_POST['new_def_text'] ?? '');

            $existingWord = Storage::getRecord('word', $wId);
            if ($existingWord && $wordText !== '') {
                $definitions = [];
                foreach ($defIds as $idx => $dId) {
                    $dText = trim($defTexts[$idx] ?? '');
                    if ($dText !== '') {
                        $definitions[] = [
                            'id' => (int)$dId,
                            'definition' => $dText
                        ];
                    }
                }
                if ($newDefText !== '') {
                    $nextDefId = 1;
                    foreach ($definitions as $d) {
                        if ($d['id'] >= $nextDefId) {
                            $nextDefId = $d['id'] + 1;
                        }
                    }
                    $definitions[] = [
                        'id' => $nextDefId,
                        'definition' => $newDefText
                    ];
                }

                $wordRecord = [
                    'id' => $wId,
                    'text' => $wordText,
                    'definitions' => $definitions
                ];
                Storage::saveRecord('word', $wordRecord, $wId);
                $message = 'Word successfully updated!';
            } else {
                $error = 'Invalid word update.';
            }
        } elseif ($action === 'delete_word') {
            $wId = (int)($_POST['word_id'] ?? 0);
            if (Storage::deleteRecord('word', $wId)) {
                $message = 'Word deleted successfully.';
            } else {
                $error = 'Failed to delete word.';
            }
        }
    }
}

$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$currentAction = $_GET['action'] ?? 'home';
$allWordsForJs = Storage::getAllRecords('word');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sentence & Word Manager</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f6f8; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        h1, h2, h3 { color: #2c3e50; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
        .nav-links { margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn { background: #3498db; color: #fff; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; display: inline-block; }
        .btn:hover { background: #2980b9; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        .btn-secondary { background: #7f8c8d; }
        .btn-secondary:hover { background: #707b7c; }
        .alert { padding: 12px; margin-bottom: 15px; border-radius: 4px; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="password"], textarea, select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; margin-bottom: 5px; }
        textarea { height: 80px; resize: vertical; }
        .token-builder { background: #f8f9fa; padding: 15px; border: 1px solid #e9ecef; border-radius: 6px; margin-top: 15px; }
        .token-row { display: flex; flex-direction: column; background: #fff; padding: 10px; border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: 10px; gap: 8px; }
        .token-header { font-weight: bold; color: #2b6cb0; font-size: 15px; }
        .card { background: #fff; border: 1px solid #e1e8ed; border-radius: 6px; padding: 15px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .sentence-title { font-size: 18px; font-weight: bold; color: #1a202c; margin-bottom: 8px; }
        .sentence-translation { font-style: italic; color: #4a5568; margin-bottom: 12px; padding-left: 10px; border-left: 3px solid #cbd5e0; }
        .word-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .word-card { background: #edf2f7; border: 1px solid #cbd5e0; padding: 8px 12px; border-radius: 4px; text-decoration: none; color: inherit; display: block; transition: background 0.2s; }
        .word-card:hover { background: #e2e8f0; }
        .word-card .word-text { font-weight: bold; color: #2b6cb0; }
        .word-card .word-def { font-size: 13px; color: #4a5568; margin-top: 2px; }
        .meta-tag { font-size: 11px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .help-text { font-size: 12px; color: #718096; margin-top: 4px; }
        .pagination { display: flex; gap: 5px; margin-top: 20px; align-items: center; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; text-decoration: none; color: #333; }
        .pagination .active { background: #3498db; color: #fff; border-color: #3498db; }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        table.data-table th, table.data-table td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; }
        table.data-table th { background: #f7fafc; color: #4a5568; }
        .action-bar { display: flex; gap: 8px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Sentence & Word Manager</h1>
        <div>
            <?php if ($isLoggedIn): ?>
                <a href="?action=logout" class="btn btn-danger">Logout</a>
            <?php else: ?>
                <a href="?action=login" class="btn">Login</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="nav-links">
        <a href="?" class="btn <?= $currentAction === 'home' ? '' : 'btn-secondary' ?>">All Sentences View</a>
        <a href="?action=words" class="btn <?= $currentAction === 'words' ? '' : 'btn-secondary' ?>">All Words View</a>
        <?php if ($isLoggedIn): ?>
            <a href="?action=add" class="btn <?= $currentAction === 'add' ? '' : 'btn-secondary' ?>">Add New Sentence</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($currentAction === 'login' && !$isLoggedIn): ?>
        <h2>Login</h2>
        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn">Login</button>
        </form>

    <?php elseif ($currentAction === 'words'): ?>
        <h2>All Words View</h2>
        <form method="GET" style="margin-bottom: 15px; display: flex; gap: 10px;">
            <input type="hidden" name="action" value="words">
            <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Search words..." style="margin-bottom: 0;">
            <button type="submit" class="btn">Search</button>
            <?php if (!empty($_GET['q'])): ?>
                <a href="?action=words" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <?php
        $searchQuery = trim($_GET['q'] ?? '');
        $allWords = Storage::getAllRecords('word');
        if ($searchQuery !== '') {
            $filteredWords = [];
            foreach ($allWords as $wId => $w) {
                $matched = false;
                if (stripos($w['text'], $searchQuery) !== false) {
                    $matched = true;
                } else {
                    foreach ($w['definitions'] as $def) {
                        if (stripos($def['definition'], $searchQuery) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                }
                if ($matched) {
                    $filteredWords[$wId] = $w;
                }
            }
            $words = $filteredWords;
        } else {
            $words = $allWords;
        }

        $totalWords = count($words);
        $page = max(1, (int)($_GET['p'] ?? 1));
        $totalPages = max(1, ceil($totalWords / WORDS_PER_VIEW));
        $offset = ($page - 1) * WORDS_PER_VIEW;
        $pagedWords = array_slice($words, $offset, WORDS_PER_VIEW, true);

        if (empty($words)):
        ?>
            <p>No words recorded yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Word / Phrase</th>
                        <th>Definitions</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagedWords as $wId => $word): ?>
                        <tr>
                            <td>#<?= $wId ?></td>
                            <td><strong><?= htmlspecialchars($word['text']) ?></strong></td>
                            <td>
                                <ul style="margin: 0; padding-left: 20px;">
                                    <?php foreach ($word['definitions'] as $def): ?>
                                        <li>(#<?= $def['id'] ?>) <?= htmlspecialchars($def['definition']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                    <a href="?action=word_detail&id=<?= $wId ?>" class="btn" style="padding: 5px 10px; font-size: 12px;">View Details</a>
                                    <?php if ($isLoggedIn): ?>
                                        <a href="?action=edit_word_form&id=<?= $wId ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">Edit</a>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this word?');" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_word">
                                            <input type="hidden" name="word_id" value="<?= $wId ?>">
                                            <button type="submit" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <span>Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php 
                    $queryParam = $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '';
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="?action=words&p=<?= $page - 1 ?><?= $queryParam ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?action=words&p=<?= $page + 1 ?><?= $queryParam ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($currentAction === 'word_detail'): ?>
        <?php
        $wordId = (int)($_GET['id'] ?? 0);
        $word = Storage::getRecord('word', $wordId);
        if (!$word):
        ?>
            <p>Word not found.</p>
        <?php else: ?>
            <h2>Word Details: <?= htmlspecialchars($word['text']) ?> (#<?= $word['id'] ?>)</h2>
            <h3>Definitions</h3>
            <ul style="padding-left: 20px;">
                <?php foreach ($word['definitions'] as $def): ?>
                    <li><strong>Definition #<?= $def['id'] ?>:</strong> <?= htmlspecialchars($def['definition']) ?></li>
                <?php endforeach; ?>
            </ul>

            <h3>Sentences using this word</h3>
            <?php
            $allSentences = Storage::getAllRecords('sentence');
            $matchingSentences = [];
            foreach ($allSentences as $s) {
                foreach ($s['word_links'] as $link) {
                    if ($link['word_id'] == $wordId) {
                        $matchingSentences[] = [
                            'sentence' => $s,
                            'def_id' => $link['definition_id']
                        ];
                        break;
                    }
                }
            }

            if (empty($matchingSentences)):
            ?>
                <p>No sentences currently use this word.</p>
            <?php else: ?>
                <?php foreach ($matchingSentences as $item): ?>
                    <?php 
                    $s = $item['sentence'];
                    $usedDefText = 'N/A';
                    foreach ($word['definitions'] as $d) {
                        if ($d['id'] == $item['def_id']) {
                            $usedDefText = $d['definition'];
                            break;
                        }
                    }
                    ?>
                    <div class="card" style="cursor: pointer;" onclick="window.location.href='?action=sentence_detail&id=<?= $s['id'] ?>';">
                        <div class="meta-tag">Sentence ID: #<?= $s['id'] ?></div>
                        <div class="sentence-title"><?= htmlspecialchars($s['raw_sentence']) ?></div>
                        <?php if (!empty($s['translation'])): ?>
                            <div class="sentence-translation"><?= htmlspecialchars($s['translation']) ?></div>
                        <?php endif; ?>
                        <div style="font-size: 13px; color: #4a5568;">
                            <strong>Context Definition Used (#<?= $item['def_id'] ?>):</strong> <?= htmlspecialchars($usedDefText) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <p><a href="?action=words" class="btn btn-secondary">Back to All Words</a></p>
        <?php endif; ?>

    <?php elseif ($currentAction === 'sentence_detail'): ?>
        <?php
        $sId = (int)($_GET['id'] ?? 0);
        $sentence = Storage::getRecord('sentence', $sId);
        if (!$sentence):
        ?>
            <p>Sentence not found.</p>
        <?php else: ?>
            <h2>Sentence Details</h2>
            <div class="card" style="border: none; box-shadow: none; padding: 0;">
                <div class="meta-tag">Sentence ID: #<?= $sentence['id'] ?></div>
                <div class="sentence-title" style="font-size: 22px;"><?= htmlspecialchars($sentence['raw_sentence']) ?></div>
                
                <?php if (!empty($sentence['translation'])): ?>
                    <div class="sentence-translation" style="font-size: 16px;"><?= htmlspecialchars($sentence['translation']) ?></div>
                <?php endif; ?>

                <h3>Word breakdown & Context Definitions:</h3>
                <div class="word-grid">
                    <?php foreach ($sentence['word_links'] as $link): ?>
                        <?php
                        $wordRecord = Storage::getRecord('word', $link['word_id']);
                        $defText = 'No definition provided';
                        if ($wordRecord && !empty($link['definition_id'])) {
                            foreach ($wordRecord['definitions'] as $d) {
                                if ($d['id'] == $link['definition_id']) {
                                    $defText = $d['definition'];
                                    break;
                                }
                            }
                        }
                        ?>
                        <a href="?action=word_detail&id=<?= $link['word_id'] ?>" class="word-card">
                            <div class="word-text">
                                <?= htmlspecialchars($wordRecord['text'] ?? 'Unknown') ?>
                                <span style="font-size: 10px; color: #a0aec0;">(#<?= $link['word_id'] ?>)</span>
                            </div>
                            <div class="word-def">
                                <?= htmlspecialchars($defText) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($isLoggedIn): ?>
                <div class="action-bar" style="margin-top: 20px;">
                    <a href="?action=edit_sentence_form&id=<?= $sId ?>" class="btn btn-secondary">Edit Sentence</a>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this sentence?');">
                        <input type="hidden" name="action" value="delete_sentence">
                        <input type="hidden" name="sentence_id" value="<?= $sId ?>">
                        <button type="submit" class="btn btn-danger">Delete Sentence</button>
                    </form>
                </div>
            <?php endif; ?>
            <p style="margin-top: 20px;"><a href="?" class="btn btn-secondary">Back to Sentences</a></p>
        <?php endif; ?>

    <?php elseif ($currentAction === 'add' && $isLoggedIn): ?>
        <h2>Add New Sentence</h2>
        <form method="POST" id="sentenceForm">
            <input type="hidden" name="action" value="add_sentence">
            <div class="form-group">
                <label for="raw_sentence">Original Sentence</label>
                <input type="text" id="raw_sentence" name="raw_sentence" placeholder="e.g. I [look forward to] meeting you" required>
                <div class="help-text">Use square brackets <code>[...]</code> to group multiple words into a single unit.</div>
            </div>

            <div class="form-group">
                <label for="translation">Whole Sentence Translation</label>
                <textarea id="translation" name="translation" placeholder="Enter full translation here..."></textarea>
            </div>

            <div id="tokenContainer" class="token-builder" style="display: none;">
                <h3>Word & Definition Selection</h3>
                <div id="tokenList"></div>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <button type="submit" class="btn">Save Sentence Data</button>
            </div>
        </form>

    <?php elseif ($currentAction === 'edit_sentence_form' && $isLoggedIn): ?>
        <?php
        $sId = (int)($_GET['id'] ?? 0);
        $sentence = Storage::getRecord('sentence', $sId);
        if (!$sentence):
        ?>
            <p>Sentence not found.</p>
        <?php else: ?>
            <h2>Edit Sentence (#<?= $sentence['id'] ?>)</h2>
            <form method="POST" id="sentenceForm">
                <input type="hidden" name="action" value="edit_sentence">
                <input type="hidden" name="sentence_id" value="<?= $sentence['id'] ?>">
                <div class="form-group">
                    <label for="raw_sentence">Original Sentence</label>
                    <input type="text" id="raw_sentence" name="raw_sentence" value="<?= htmlspecialchars($sentence['raw_sentence']) ?>" required>
                    <div class="help-text">Use square brackets <code>[...]</code> to group multiple words into a single unit.</div>
                </div>

                <div class="form-group">
                    <label for="translation">Whole Sentence Translation</label>
                    <textarea id="translation" name="translation"><?= htmlspecialchars($sentence['translation'] ?? '') ?></textarea>
                </div>

                <div id="tokenContainer" class="token-builder">
                    <h3>Word & Definition Selection</h3>
                    <div id="tokenList"></div>
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <button type="submit" class="btn">Update Sentence Data</button>
                    <a href="?action=sentence_detail&id=<?= $sentence['id'] ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        <?php endif; ?>

    <?php elseif ($currentAction === 'edit_word_form' && $isLoggedIn): ?>
        <?php
        $wId = (int)($_GET['id'] ?? 0);
        $word = Storage::getRecord('word', $wId);
        if (!$word):
        ?>
            <p>Word not found.</p>
        <?php else: ?>
            <h2>Edit Word (#<?= $word['id'] ?>)</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit_word">
                <input type="hidden" name="word_id" value="<?= $word['id'] ?>">
                <div class="form-group">
                    <label for="word_text">Word / Phrase</label>
                    <input type="text" id="word_text" name="word_text" value="<?= htmlspecialchars($word['text']) ?>" required>
                </div>

                <h3>Definitions</h3>
                <?php foreach ($word['definitions'] as $idx => $def): ?>
                    <div class="form-group" style="background: #f8f9fa; padding: 10px; border: 1px solid #e9ecef; border-radius: 4px;">
                        <input type="hidden" name="def_ids[<?= $idx ?>]" value="<?= $def['id'] ?>">
                        <label>Definition #<?= $def['id'] ?></label>
                        <input type="text" name="def_texts[<?= $idx ?>]" value="<?= htmlspecialchars($def['definition']) ?>" required>
                    </div>
                <?php endforeach; ?>

                <div class="form-group" style="background: #eef2f7; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
                    <label>Add New Definition (Optional)</label>
                    <input type="text" name="new_def_text" placeholder="Type new definition...">
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <button type="submit" class="btn">Update Word</button>
                    <a href="?action=words" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        <?php endif; ?>

    <?php else: ?>
        <h2>All Sentences View</h2>
        <?php
        $sentences = Storage::getAllRecords('sentence');
        $totalSentences = count($sentences);
        $page = max(1, (int)($_GET['p'] ?? 1));
        $totalPages = max(1, ceil($totalSentences / SENTENCES_PER_VIEW));
        $offset = ($page - 1) * SENTENCES_PER_VIEW;
        $pagedSentences = array_slice($sentences, $offset, SENTENCES_PER_VIEW, true);

        if (empty($sentences)):
        ?>
            <p>No sentences recorded yet.</p>
        <?php else: ?>
            <?php foreach ($pagedSentences as $sId => $sentence): ?>
                <div class="card">
                    <div class="meta-tag">Sentence ID: #<?= $sId ?></div>
                    <div class="sentence-title"><?= htmlspecialchars($sentence['raw_sentence']) ?></div>
                    
                    <?php if (!empty($sentence['translation'])): ?>
                        <div class="sentence-translation"><?= htmlspecialchars($sentence['translation']) ?></div>
                    <?php endif; ?>

                    <div class="word-grid">
                        <?php foreach ($sentence['word_links'] as $link): ?>
                            <?php
                            $wordRecord = Storage::getRecord('word', $link['word_id']);
                            $defText = 'No definition provided';
                            if ($wordRecord && !empty($link['definition_id'])) {
                                foreach ($wordRecord['definitions'] as $d) {
                                    if ($d['id'] == $link['definition_id']) {
                                        $defText = $d['definition'];
                                        break;
                                    }
                                }
                            }
                            ?>
                            <a href="?action=word_detail&id=<?= $link['word_id'] ?>" class="word-card">
                                <div class="word-text">
                                    <?= htmlspecialchars($wordRecord['text'] ?? 'Unknown') ?>
                                    <span style="font-size: 10px; color: #a0aec0;">(#<?= $link['word_id'] ?>)</span>
                                </div>
                                <div class="word-def">
                                    <?= htmlspecialchars($defText) ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top: 10px; display: flex; gap: 10px; align-items: center;">
                        <a href="?action=sentence_detail&id=<?= $sId ?>" style="font-size: 13px; color: #3498db; text-decoration: none; font-weight: bold;">Read More / View Details &rarr;</a>
                        <?php if ($isLoggedIn): ?>
                            <a href="?action=edit_sentence_form&id=<?= $sId ?>" style="font-size: 13px; color: #7f8c8d; text-decoration: none;">Edit</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <span>Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php if ($page > 1): ?>
                        <a href="?p=<?= $page - 1 ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?p=<?= $page + 1 ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
const existingWordsData = <?= json_encode($allWordsForJs) ?>;

document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('raw_sentence');
    const container = document.getElementById('tokenContainer');
    const list = document.getElementById('tokenList');

    if (!input) return;

    function parseTokens(text) {
        const regex = /\[([^\]]+)\]|([^\s\[\]]+)/g;
        const tokens = [];
        let match;
        while ((match = regex.exec(text)) !== null) {
            if (match[1]) {
                tokens.push(match[1].trim());
            } else if (match[2]) {
                tokens.push(match[2].trim());
            }
        }
        return tokens;
    }

    function findExistingWord(tokenText) {
        const lower = tokenText.toLowerCase();
        for (let id in existingWordsData) {
            if (existingWordsData[id].text.toLowerCase() === lower) {
                return existingWordsData[id];
            }
        }
        return null;
    }

    function updateTokens() {
        const text = input.value;
        const tokens = parseTokens(text);

        if (tokens.length === 0) {
            container.style.display = 'none';
            list.innerHTML = '';
            return;
        }

        container.style.display = 'block';
        list.innerHTML = '';

        tokens.forEach((token, index) => {
            const row = document.createElement('div');
            row.className = 'token-row';

            const header = document.createElement('div');
            header.className = 'token-header';
            header.textContent = token;
            row.appendChild(header);

            const matchedWord = findExistingWord(token);

            if (matchedWord && matchedWord.definitions && matchedWord.definitions.length > 0) {
                const select = document.createElement('select');
                select.name = `word_defs[${index}]`;

                matchedWord.definitions.forEach(def => {
                    const opt = document.createElement('option');
                    opt.value = def.id;
                    opt.textContent = `Existing Def (#${def.id}): ${def.definition}`;
                    select.appendChild(opt);
                });

                const newOpt = document.createElement('option');
                newOpt.value = 'NEW';
                newOpt.textContent = '+ Add a new definition instead';
                select.appendChild(newOpt);

                row.appendChild(select);

                const newDefInput = document.createElement('input');
                newDefInput.type = 'text';
                newDefInput.name = `word_new_defs[${index}]`;
                newDefInput.placeholder = `Type new definition here...`;
                newDefInput.style.display = 'none';
                row.appendChild(newDefInput);

                select.addEventListener('change', function() {
                    if (this.value === 'NEW') {
                        newDefInput.style.display = 'block';
                        newDefInput.required = true;
                    } else {
                        newDefInput.style.display = 'none';
                        newDefInput.required = false;
                        newDefInput.value = '';
                    }
                });
            } else {
                const hiddenSelect = document.createElement('input');
                hiddenSelect.type = 'hidden';
                hiddenSelect.name = `word_defs[${index}]`;
                hiddenSelect.value = 'NEW';
                row.appendChild(hiddenSelect);

                const newDefInput = document.createElement('input');
                newDefInput.type = 'text';
                newDefInput.name = `word_new_defs[${index}]`;
                newDefInput.placeholder = `Definition for "${token}"...`;
                row.appendChild(newDefInput);
            }

            list.appendChild(row);
        });
    }

    input.addEventListener('input', updateTokens);
    if (input.value.trim() !== '') {
        updateTokens();
    }
});
</script>
</body>
</html>
