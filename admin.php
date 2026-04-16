<?php

declare(strict_types=1);

require __DIR__ . '/src/install.php';

if (!is_installed()) {
    header('Location: install/');
    exit;
}

require __DIR__ . '/src/bootstrap.php';

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $allowedRoles = ['admin'];

    if ($action === 'login') {
        if (!verify_csrf($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Invalid session token.';
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            $user = verify_credentials($username, $password);
            if ($user) {
                login_user($user);
                header('Location: admin.php');
                exit;
            }

            $errors[] = 'Invalid username or password.';
        }
    } else {
        if (!is_admin()) {
            http_response_code(403);
            $errors[] = 'Authentication required.';
        } elseif (!verify_csrf($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Invalid session token.';
        } elseif ($action === 'logout') {
            logout_user();
            header('Location: admin.php');
            exit;
        } elseif ($action === 'create_entity') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $contactData = trim((string) ($_POST['contact_data'] ?? ''));

            if ($name === '' || $contactData === '') {
                $errors[] = 'Name and contact data are required.';
            } else {
                $entity = create_entity($name, $contactData);
                $messages[] = 'Entity created. Hash: ' . $entity['hash'];
            }
        } elseif ($action === 'create_user') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
            $role = (string) ($_POST['role'] ?? 'admin');

            if ($username === '' || $password === '') {
                $errors[] = 'Username and password are required.';
            } elseif ($password !== $passwordConfirm) {
                $errors[] = 'Passwords do not match.';
            } elseif (($pwError = validate_password($password)) !== null) {
                $errors[] = $pwError;
            } elseif (!in_array($role, $allowedRoles, true)) {
                $errors[] = 'Invalid role.';
            } elseif (find_user_by_username($username)) {
                $errors[] = 'Username already exists.';
            } else {
                create_user($username, $password, $role);
                $messages[] = 'User created.';
            }
        } elseif ($action === 'update_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $role = (string) ($_POST['role'] ?? 'admin');
            $newPassword = (string) ($_POST['new_password'] ?? '');

            if ($userId <= 0 || !get_user_by_id($userId)) {
                $errors[] = 'User not found.';
            } elseif (!in_array($role, $allowedRoles, true)) {
                $errors[] = 'Invalid role.';
            } elseif ($newPassword !== '' && ($pwError = validate_password($newPassword)) !== null) {
                $errors[] = $pwError;
            } else {
                update_user_role($userId, $role);
                if ($newPassword !== '') {
                    update_user_password($userId, $newPassword);
                }
                $messages[] = 'User updated.';
            }
        } elseif ($action === 'create_document') {
            $entityId = (int) ($_POST['entity_id'] ?? 0);
            $type = (string) ($_POST['type'] ?? '');
            $language = trim((string) ($_POST['language'] ?? ''));
            $content = trim((string) ($_POST['content'] ?? ''));
            $version = trim((string) ($_POST['version'] ?? ''));

            if (!in_array($type, ['imprint', 'privacy'], true)) {
                $errors[] = 'Invalid document type.';
            } elseif ($entityId <= 0 || $language === '' || $content === '' || $version === '') {
                $errors[] = 'All document fields are required.';
            } else {
                create_document($entityId, $type, $language, $content, $version);
                $messages[] = 'Document created.';
            }
        } elseif ($action === 'update_document') {
            $docId = (int) ($_POST['doc_id'] ?? 0);
            $entityId = (int) ($_POST['entity_id'] ?? 0);
            $type = (string) ($_POST['type'] ?? '');
            $language = trim((string) ($_POST['language'] ?? ''));
            $content = trim((string) ($_POST['content'] ?? ''));
            $version = trim((string) ($_POST['version'] ?? ''));

            if ($docId <= 0) {
                $errors[] = 'Document not found.';
            } elseif (!in_array($type, ['imprint', 'privacy'], true)) {
                $errors[] = 'Invalid document type.';
            } elseif ($entityId <= 0 || $language === '' || $content === '' || $version === '') {
                $errors[] = 'All document fields are required.';
            } else {
                update_document($docId, $entityId, $type, $language, $content, $version);
                $messages[] = 'Document updated.';
            }
        }
    }
}

$loggedIn = is_admin();
$entities = $loggedIn ? list_entities() : [];
$documents = $loggedIn ? list_documents() : [];
$users = $loggedIn ? list_users() : [];
$pwPolicy  = app_config()['security']['password_policy'];
$pwMin     = max(8, (int) ($pwPolicy['min_length'] ?? 16));
$pwMax     = min(256, max($pwMin, (int) ($pwPolicy['max_length'] ?? 256)));
$editDoc = null;

if ($loggedIn && isset($_GET['doc_id'])) {
    $editDoc = get_document((int) $_GET['doc_id']);
    if (!$editDoc) {
        $errors[] = 'Document not found.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LexNova Admin</title>
    <style>
        :root {
            color-scheme: light;
        }
        body {
            font-family: "Trebuchet MS", "Verdana", sans-serif;
            margin: 0;
            background: #f6f4ef;
            color: #1b1b1b;
        }
        .wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 32px 20px 80px;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        h1 {
            margin: 0;
            font-size: 28px;
        }
        .card {
            background: #ffffff;
            border: 1px solid #d9d2c7;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 24px rgba(31, 26, 20, 0.05);
            margin-bottom: 24px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #c8c0b3;
            font-size: 14px;
        }
        textarea {
            min-height: 140px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        .actions {
            margin-top: 16px;
            display: flex;
            gap: 12px;
        }
        button {
            background: #2f3a2f;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            cursor: pointer;
        }
        button.secondary {
            background: #6a5b4a;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #e1dacd;
        }
        .notice {
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        .notice.error {
            background: #ffe9e6;
            color: #8a1f0e;
            border: 1px solid #f0b1a5;
        }
        .notice.success {
            background: #eef7ec;
            color: #285c2e;
            border: 1px solid #b7dfb6;
        }
        .hint {
            font-size: 12px;
            color: #6a5b4a;
            margin: 6px 0 0;
        }
        .pw-strength-bar {
            height: 5px;
            border-radius: 3px;
            background: #e0d8ce;
            margin: 7px 0 2px;
        }
        .pw-strength-fill {
            height: 100%;
            border-radius: 3px;
            width: 0;
            transition: width .3s ease, background .3s ease;
        }
        .pw-score-0 { width: 10%;  background: #c0392b; }
        .pw-score-1 { width: 30%;  background: #e67e22; }
        .pw-score-2 { width: 55%;  background: #d4ac0d; }
        .pw-score-3 { width: 80%;  background: #7db96c; }
        .pw-score-4 { width: 100%; background: #27ae60; }
        .pw-feedback {
            font-size: 12px;
            color: #5a4535;
            min-height: 17px;
            margin: 2px 0 4px;
        }
        .pw-gen-row { display: flex; gap: 8px; margin-top: 6px; }
        .pw-gen-row button {
            font-size: 12px;
            padding: 5px 11px;
            background: #3a527a;
            border-radius: 6px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <?php if (!$loggedIn): ?>
        <header>
            <h1>LexNova Admin</h1>
        </header>
        <div class="card">
            <?php foreach ($errors as $error): ?>
                <div class="notice error"><?php echo h($error); ?></div>
            <?php endforeach; ?>
            <form method="post">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="login">
                <div class="grid">
                    <div>
                        <label for="username">Username</label>
                        <input id="username" name="username" required>
                    </div>
                    <div>
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" required>
                    </div>
                </div>
                <div class="actions">
                    <button type="submit">Sign in</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <header>
            <h1>LexNova Admin</h1>
            <form method="post">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="logout">
                <button class="secondary" type="submit">Logout</button>
            </form>
        </header>

        <?php foreach ($errors as $error): ?>
            <div class="notice error"><?php echo h($error); ?></div>
        <?php endforeach; ?>
        <?php foreach ($messages as $message): ?>
            <div class="notice success"><?php echo h($message); ?></div>
        <?php endforeach; ?>

        <div class="card">
            <h2>Create user</h2>
            <form method="post">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create_user">
                <div class="grid">
                    <div>
                        <label for="user_username">Username</label>
                        <input id="user_username" name="username" required>
                    </div>
                    <div>
                        <label for="user_password">Password</label>
                        <input id="user_password" name="password" type="password" class="pw-field"
                               minlength="<?php echo $pwMin; ?>" maxlength="<?php echo $pwMax; ?>" required>
                    </div>
                    <div>
                        <label for="user_password_confirm">Confirm password</label>
                        <input id="user_password_confirm" name="password_confirm" type="password"
                               minlength="<?php echo $pwMin; ?>" maxlength="<?php echo $pwMax; ?>" required>
                    </div>
                    <div>
                        <label for="user_role">Role</label>
                        <select id="user_role" name="role" required>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <p class="hint">Min. <?php echo $pwMin; ?> – max. <?php echo $pwMax; ?> characters. Standard ASCII printable only (A–Z, 0–9, !, @, #, $ …). No accented or Unicode characters.</p>
                <div class="actions">
                    <button type="submit">Create user</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Users</h2>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Created</th>
                    <th>Update</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr><td colspan="5">No users yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo (int) $user['id']; ?></td>
                            <td><?php echo h((string) $user['username']); ?></td>
                            <td><?php echo h((string) $user['role']); ?></td>
                            <td><?php echo h((string) $user['created_at']); ?></td>
                            <td>
                                <form method="post">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                    <div class="grid">
                                        <div>
                                            <label for="role_<?php echo (int) $user['id']; ?>">Role</label>
                                            <select id="role_<?php echo (int) $user['id']; ?>" name="role" required>
                                                <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label for="new_password_<?php echo (int) $user['id']; ?>">New password</label>
                                            <input id="new_password_<?php echo (int) $user['id']; ?>" name="new_password" type="password" class="pw-field"
                                                   minlength="<?php echo $pwMin; ?>" maxlength="<?php echo $pwMax; ?>"
                                                   placeholder="Leave blank to keep">
                                            <p class="hint">Min. <?php echo $pwMin; ?> – max. <?php echo $pwMax; ?> chars., ASCII printable only.</p>
                                        </div>
                                    </div>
                                    <div class="actions">
                                        <button type="submit">Update</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Create legal entity</h2>
            <form method="post">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create_entity">
                <div class="grid">
                    <div>
                        <label for="name">Name</label>
                        <input id="name" name="name" required>
                    </div>
                    <div>
                        <label for="contact_data">Contact data</label>
                        <textarea id="contact_data" name="contact_data" required></textarea>
                    </div>
                </div>
                <div class="actions">
                    <button type="submit">Create entity</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Create legal document</h2>
            <form method="post">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create_document">
                <div class="grid">
                    <div>
                        <label for="entity_id">Entity</label>
                        <select id="entity_id" name="entity_id" required>
                            <option value="">Select entity</option>
                            <?php foreach ($entities as $entity): ?>
                                <option value="<?php echo (int) $entity['id']; ?>"><?php echo h($entity['name']); ?> (<?php echo h($entity['hash']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="type">Type</label>
                        <select id="type" name="type" required>
                            <option value="imprint">Imprint</option>
                            <option value="privacy">Privacy</option>
                        </select>
                    </div>
                    <div>
                        <label for="language">Language</label>
                        <input id="language" name="language" placeholder="de" required>
                    </div>
                    <div>
                        <label for="version">Version</label>
                        <input id="version" name="version" placeholder="1.0" required>
                    </div>
                </div>
                <div>
                    <label for="content">Content (Markdown or structured text)</label>
                    <textarea id="content" name="content" required></textarea>
                </div>
                <div class="actions">
                    <button type="submit">Create document</button>
                </div>
            </form>
        </div>

        <?php if ($editDoc): ?>
            <div class="card">
                <h2>Edit document #<?php echo (int) $editDoc['id']; ?></h2>
                <form method="post">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="update_document">
                    <input type="hidden" name="doc_id" value="<?php echo (int) $editDoc['id']; ?>">
                    <div class="grid">
                        <div>
                            <label for="edit_entity_id">Entity</label>
                            <select id="edit_entity_id" name="entity_id" required>
                                <?php foreach ($entities as $entity): ?>
                                    <option value="<?php echo (int) $entity['id']; ?>" <?php echo ((int) $entity['id'] === (int) $editDoc['entity_id']) ? 'selected' : ''; ?>><?php echo h($entity['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="edit_type">Type</label>
                            <select id="edit_type" name="type" required>
                                <option value="imprint" <?php echo $editDoc['type'] === 'imprint' ? 'selected' : ''; ?>>Imprint</option>
                                <option value="privacy" <?php echo $editDoc['type'] === 'privacy' ? 'selected' : ''; ?>>Privacy</option>
                            </select>
                        </div>
                        <div>
                            <label for="edit_language">Language</label>
                            <input id="edit_language" name="language" value="<?php echo h((string) $editDoc['language']); ?>" required>
                        </div>
                        <div>
                            <label for="edit_version">Version</label>
                            <input id="edit_version" name="version" value="<?php echo h((string) $editDoc['version']); ?>" required>
                        </div>
                    </div>
                    <div>
                        <label for="edit_content">Content</label>
                        <textarea id="edit_content" name="content" required><?php echo h((string) $editDoc['content']); ?></textarea>
                    </div>
                    <div class="actions">
                        <button type="submit">Update document</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Entities</h2>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Hash</th>
                    <th>Contact data</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$entities): ?>
                    <tr><td colspan="4">No entities yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($entities as $entity): ?>
                        <tr>
                            <td><?php echo (int) $entity['id']; ?></td>
                            <td><?php echo h((string) $entity['name']); ?></td>
                            <td><?php echo h((string) $entity['hash']); ?></td>
                            <td><?php echo h((string) $entity['contact_data']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Documents</h2>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Entity</th>
                    <th>Type</th>
                    <th>Language</th>
                    <th>Version</th>
                    <th>Updated</th>
                    <th>Edit</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$documents): ?>
                    <tr><td colspan="7">No documents yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td><?php echo (int) $doc['id']; ?></td>
                            <td><?php echo h((string) $doc['entity_name']); ?></td>
                            <td><?php echo h((string) $doc['type']); ?></td>
                            <td><?php echo h((string) $doc['language']); ?></td>
                            <td><?php echo h((string) $doc['version']); ?></td>
                            <td><?php echo h((string) $doc['updated_at']); ?></td>
                            <td><a href="admin.php?doc_id=<?php echo (int) $doc['id']; ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script src="assets/zxcvbn.js"></script>
<script>
(function () {
    'use strict';

    const PW_MIN = <?php echo (int) $pwMin; ?>;
    const PW_MAX = <?php echo (int) $pwMax; ?>;

    /* ~512-word EFF-style wordlist (ASCII, 4-7 letters, easy to type) */
    const WORDS = [
        'able','acid','aged','arch','area','army','away','back','ball','band',
        'bank','barn','base','bath','bead','beam','bean','bear','beat','beef',
        'beer','bell','belt','bird','bite','blow','blue','boat','bold','bolt',
        'bond','bone','book','boot','bowl','brew','bulk','burn','cage','calm',
        'camp','card','cart','case','cave','cell','chip','clay','clip','coal',
        'coat','code','coil','cold','comb','cone','cool','cord','core','cork',
        'corn','cost','crop','cube','curb','curl','damp','dark','data','dawn',
        'deal','deck','deep','desk','dial','disk','dive','dock','door','dose',
        'drag','draw','drop','drum','duck','dune','dust','edge','face','fact',
        'fail','fame','fast','feed','feel','fill','film','fish','fist','flag',
        'flat','foam','fold','font','ford','form','fort','fuel','full','fuse',
        'gain','gate','gear','gift','glow','glue','goal','gold','grab','grid',
        'grin','grip','grow','gulf','hack','half','hall','halt','hand','hard',
        'harm','heap','heat','helm','high','hill','hint','hold','hook','hope',
        'horn','hull','hunt','idle','iron','isle','item','jade','join','jump',
        'just','keep','kind','lake','land','lane','last','lead','leaf','leap',
        'lens','lift','lime','link','list','load','lock','long','loop','loud',
        'luck','lung','main','mane','mark','mast','meal','melt','mesh','mind',
        'mint','mist','mode','mold','moon','moor','more','move','nail','neck',
        'need','nest','next','nice','node','norm','note','once','open','over',
        'pace','pain','pair','park','part','pass','path','peak','pick','pine',
        'pipe','plan','plot','plow','pool','port','post','pour','pull','pump',
        'pure','push','race','rack','rain','rank','read','real','reed','reef',
        'reel','rest','rice','rich','ride','ring','rise','road','roam','robe',
        'rock','role','roll','roof','root','rope','rose','ruin','rush','safe',
        'sage','sail','salt','sand','save','seal','seed','self','shed','ship',
        'shoe','shop','show','sift','sign','silk','silt','sink','site','skip',
        'slam','slow','snow','soak','soil','soul','span','spin','spur','star',
        'stay','stem','step','stop','suit','tale','tall','tank','task','tell',
        'tend','term','test','tide','tile','time','toll','tone','tool','town',
        'trap','tray','tree','trim','trip','true','tube','tune','turn','type',
        'vast','veil','view','vine','void','wade','wake','walk','wall','wave',
        'weld','well','west','wild','wind','wire','wise','wood','word','work',
        'wrap','yard','year','zoom',
        'blaze','blend','block','bloom','brave','bread','break','brush','build',
        'burst','chess','chord','civil','claim','clean','clear','climb','cloak',
        'coast','craft','crane','crash','creek','crisp','cross','crowd','crush',
        'cycle','depth','digit','doubt','draft','dream','drift','drill','drive',
        'drone','elite','empty','equal','error','event','exact','extra','fancy',
        'fiber','field','final','fixed','flame','flash','fleet','flood','floor',
        'flour','fluid','focus','force','found','frame','frank','fresh','frost',
        'fruit','gauge','glass','gleam','glide','globe','grace','grade','grain',
        'grant','graph','grasp','graze','green','grind','group','guard','guide',
        'happy','harsh','haven','heart','heavy','horse','house','image','inner',
        'joint','judge','jumbo','knife','knoll','known','label','later','layer',
        'learn','level','light','limit','local','lodge','lower','lucid','magic',
        'manor','maple','march','marsh','match','media','merit','metal','might',
        'minor','mixed','model','money','moral','mount','music','nerve','night',
        'noble','north','notch','novel','nudge','ocean','outer','panel','patch',
        'pause','peace','pedal','perch','phase','photo','pixel','plain','plane',
        'plank','plant','plate','plaza','point','polar','power','press','price',
        'prime','print','probe','proof','proud','prove','proxy','query','quick',
        'quiet','quota','radar','radix','raise','range','rapid','ratio','reach',
        'realm','rebel','resin','retry','rhyme','ridge','risky','river','robot',
        'rough','round','route','royal','ruler','rural','scale','scene','scope',
        'scout','screw','seize','sense','serve','setup','shaft','shake','sharp',
        'shelf','shell','shine','shirt','short','sight','skill','slate','slide',
        'slope','small','smart','smoke','snare','sneak','solar','solid','solve',
        'south','space','speak','speed','spell','spend','spoke','spray','stack',
        'stage','stake','stall','stamp','stand','stark','start','state','stave',
        'steel','steep','stiff','stock','stone','storm','stout','strap','straw',
        'strip','study','stump','style','super','surge','swamp','sweep','swift',
        'sword','table','teach','thick','think','third','throw','tight','thorn',
        'tiger','token','torch','total','touch','tower','trace','track','trade',
        'trail','train','tribe','trout','truce','trust','truth','ultra','unify',
        'unity','upper','urban','usher','value','vault','verse','visit','vital',
        'vocal','voice','voter','waltz','watch','water','wheel','which','white',
        'whole','width','witch','woman','world','worth','write','xenon','yield',
        'young','zebra','zonal'
    ];

    const SCORE_LABEL = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong'];
    const SCORE_HINT  = [
        'Avoid short or common passwords.',
        'Mix uppercase, lowercase, digits and symbols.',
        'Getting better — aim for score Good or higher.',
        'Good — consider making it even longer.',
        'Excellent password!'
    ];

    function generateRandom(len) {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*-_=+;:,.?';
        const buf = new Uint32Array(len);
        crypto.getRandomValues(buf);
        return Array.from(buf, n => chars[n % chars.length]).join('');
    }

    function generatePassphrase(count) {
        const buf = new Uint32Array(count);
        crypto.getRandomValues(buf);
        return Array.from(buf, n => WORDS[n % WORDS.length]).join('-');
    }

    function initPasswordField(input) {
        /* strength bar */
        const bar  = document.createElement('div');
        bar.className = 'pw-strength-bar';
        const fill = document.createElement('div');
        fill.className = 'pw-strength-fill';
        bar.appendChild(fill);

        /* feedback text */
        const msg = document.createElement('p');
        msg.className = 'pw-feedback';

        /* generator buttons */
        const genRow = document.createElement('div');
        genRow.className = 'pw-gen-row';
        const btnR = document.createElement('button');
        btnR.type = 'button';
        btnR.textContent = 'Random (' + PW_MIN + ' chars)';
        const btnP = document.createElement('button');
        btnP.type = 'button';
        btnP.textContent = 'Passphrase (5 words)';
        genRow.append(btnR, btnP);

        input.after(bar, msg, genRow);

        /* strength update */
        input.addEventListener('input', function () {
            const val = this.value;
            if (!val) {
                fill.className = 'pw-strength-fill';
                msg.textContent = '';
                return;
            }
            const r     = (typeof zxcvbn !== 'undefined') ? zxcvbn(val) : null;
            const score = r ? r.score : Math.min(4, Math.floor(val.length / 5));
            fill.className = 'pw-strength-fill pw-score-' + score;
            let text = SCORE_LABEL[score];
            if (r && r.feedback.warning)              text += ' – ' + r.feedback.warning;
            else if (r && r.feedback.suggestions[0]) text += '. ' + r.feedback.suggestions[0];
            else                                      text += '. ' + SCORE_HINT[score];
            msg.textContent = text;
        });

        /* fill helper: sets input + optional confirm in same form */
        function fill_and_sync(val) {
            input.value = val;
            input.dispatchEvent(new Event('input'));
            const form = input.closest('form');
            if (form) {
                const confirm = form.querySelector('[name="password_confirm"]');
                if (confirm) confirm.value = val;
            }
        }

        btnR.addEventListener('click', () => fill_and_sync(generateRandom(PW_MIN)));
        btnP.addEventListener('click', () => fill_and_sync(generatePassphrase(5)));
    }

    document.querySelectorAll('.pw-field').forEach(initPasswordField);
}());
</script>
</body>
</html>
