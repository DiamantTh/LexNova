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
                        <input id="user_password" name="password" type="password" required>
                    </div>
                    <div>
                        <label for="user_password_confirm">Confirm password</label>
                        <input id="user_password_confirm" name="password_confirm" type="password" required>
                    </div>
                    <div>
                        <label for="user_role">Role</label>
                        <select id="user_role" name="role" required>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
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
                                            <input id="new_password_<?php echo (int) $user['id']; ?>" name="new_password" type="password" placeholder="Leave blank to keep">
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
</body>
</html>
