<?php

class UserController extends Controller
{
    public function index(): void
    {
        $actor = $this->requireAuth();
        MfaService::ensureSchema();
        UserPermissionService::ensureSchema();
        $canManageUsers = Auth::can('users.manage')
            && in_array($actor['role_slug'] ?? '', ['super-admin', 'admin'], true);
        $canResetAnyMfa = ScopeService::isAdmin($actor);
        $canManageUserPermissions = ScopeService::isAdmin($actor);
        $officeIds = $canManageUsers ? $this->managedOfficeIds($actor) : [];
        if (!$canManageUsers) {
            $scopeWhere = 'WHERE users.id = ?';
            $scopeParams = [(int)$actor['id']];
        } elseif (ScopeService::isAdmin($actor)) {
            $scopeWhere = '';
            $scopeParams = [];
        } else {
            $scopeWhere = 'WHERE users.office_id IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ')';
            $scopeParams = $officeIds;
        }
        $stmt = $this->db->prepare(
            'SELECT users.*, roles.name AS role_name, roles.slug AS role_slug, offices.name AS office_name,
                    users.status = \'active\'
                    AND users.last_seen_at IS NOT NULL
                    AND (users.logged_out_at IS NULL OR users.last_seen_at > users.logged_out_at)
                    AND users.last_seen_at >= NOW() - INTERVAL ' . Auth::onlineWindowSeconds() . ' SECOND AS is_online
             FROM users
             JOIN roles ON roles.id = users.role_id
             LEFT JOIN offices ON offices.id = users.office_id
             ' . $scopeWhere . '
             ORDER BY users.name'
        );
        $stmt->execute($scopeParams);
        $users = $stmt->fetchAll();
        $mfaUi = $this->mfaUiData($actor, $users, $canResetAnyMfa);
        $permissionUi = $this->permissionUiData($users, $canManageUserPermissions);
        $formData = $canManageUsers
            ? $this->formData(null, $actor)
            : ['userRecord' => null, 'offices' => [], 'canAssignAllOffices' => false];
        $this->view('users/index', $formData + $mfaUi + $permissionUi + [
            'title' => 'Users',
            'users' => $users,
            'protectedAdminId' => $this->protectedAdminId(),
            'canManageUsers' => $canManageUsers,
            'canResetAnyMfa' => $canResetAnyMfa,
            'canManageUserPermissions' => $canManageUserPermissions,
        ]);
    }

    public function create(): void
    {
        $actor = $this->requirePermission('users.manage');
        $this->requireAdminRole($actor);
        $this->view('users/form', $this->formData(null, $actor) + ['title' => 'Create User']);
    }

    public function edit(): void
    {
        $actor = $this->requirePermission('users.manage');
        $this->requireAdminRole($actor);
        $id = (int)Request::input('id');
        $user = $this->findUser($id, $actor);
        if (!$user) {
            http_response_code(404);
            echo 'User not found';
            return;
        }
        $protectedAdminSelf = $this->isProtectedAdminSelf($id);
        if (!$protectedAdminSelf && $this->redirectIfProtectedAdmin($id, '/users')) {
            return;
        }
        $this->view('users/form', $this->formData($user, $actor) + [
            'title' => 'Edit User',
            'protectedAdminSelf' => $protectedAdminSelf,
        ]);
    }

    public function store(): void
    {
        $actor = $this->requirePermission('users.manage');
        $this->requireAdminRole($actor);
        Csrf::verify();
        $officeId = $this->officeIdForAdmin($actor, '/users/create');
        $name = trim((string)Request::input('name'));
        $email = strtolower(trim((string)Request::input('email')));
        $username = strtolower(trim((string)Request::input('username')));
        $password = (string)Request::input('password');
        $passwordConfirmation = (string)Request::input('password_confirmation');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('danger', 'Enter a valid name and email address.');
            Response::redirect('/users/create');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', $username)) {
            $this->flash('danger', 'Username must be 3-80 characters and use only letters, numbers, dots, underscores, or hyphens.');
            Response::redirect('/users/create');
        }
        if ($this->usernameExists($username, null)) {
            $this->flash('danger', 'Username is already in use.');
            Response::redirect('/users/create');
        }
        if (strlen($password) < 8) {
            $this->flash('danger', 'Password must be at least 8 characters.');
            Response::redirect('/users/create');
        }
        if (!hash_equals($password, $passwordConfirmation)) {
            $this->flash('danger', 'Password and confirmation password do not match.');
            Response::redirect('/users/create');
        }

        $roleId = $this->roleId('admin');
        $stmt = $this->db->prepare(
            'INSERT INTO users (name, username, email, password_hash, role_id, office_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), NOW())'
        );
        try {
            $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $roleId, $officeId]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $this->flash('danger', 'Username and email must each be unique.');
                Response::redirect('/users/create');
            }
            throw $exception;
        }
        Audit::log('created', 'user', (int)$this->db->lastInsertId());
        $this->flash('success', 'User created.');
        Response::redirect('/users');
    }

    public function update(): void
    {
        $actor = $this->requirePermission('users.manage');
        $this->requireAdminRole($actor);
        Csrf::verify();
        $id = (int)Request::input('id');
        $user = $this->findUser($id, $actor);
        if (!$user) {
            http_response_code(404);
            echo 'User not found';
            return;
        }
        if ($this->isProtectedAdminSelf($id)) {
            $name = trim((string)Request::input('name'));
            $email = strtolower(trim((string)Request::input('email')));
            $username = strtolower(trim((string)Request::input('username')));
            $password = (string)Request::input('password');
            $passwordConfirmation = (string)Request::input('password_confirmation');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->flash('danger', 'Enter a valid name and email address.');
                Response::redirect('/users/edit?id=' . $id);
            }
            $this->validateUpdatedCredentials($username, $password, $passwordConfirmation, $id, '/users/edit?id=' . $id);

            $sql = 'UPDATE users SET name = ?, username = ?, email = ?';
            $params = [$name, $username, $email];
            if ($password !== '') {
                $sql .= ', password_hash = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ', updated_at = NOW() WHERE id = ?';
            $params[] = $id;
            $stmt = $this->db->prepare($sql);
            try {
                $stmt->execute($params);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $this->flash('danger', 'Username and email must each be unique.');
                    Response::redirect('/users/edit?id=' . $id);
                }
                throw $exception;
            }
            Audit::log('updated', 'user', $id);
            $this->flash('success', 'User updated.');
            Response::redirect('/users');
        }
        if ($this->redirectIfProtectedAdmin($id, '/users')) {
            return;
        }
        $officeId = $this->officeIdForAdmin($actor, '/users/edit?id=' . $id);
        $name = trim((string)Request::input('name'));
        $email = strtolower(trim((string)Request::input('email')));
        $username = strtolower(trim((string)Request::input('username')));
        $password = (string)Request::input('password');
        $passwordConfirmation = (string)Request::input('password_confirmation');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('danger', 'Enter a valid name and email address.');
            Response::redirect('/users/edit?id=' . $id);
        }
        $this->validateUpdatedCredentials($username, $password, $passwordConfirmation, $id, '/users/edit?id=' . $id);

        $sql = 'UPDATE users SET name = ?, username = ?, email = ?, role_id = ?, office_id = ?';
        $params = [$name, $username, $email, $this->roleId('admin'), $officeId];
        if ($password !== '') {
            $sql .= ', password_hash = ?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ', updated_at = NOW() WHERE id = ?';
        $params[] = $id;
        $stmt = $this->db->prepare($sql);
        try {
            $stmt->execute($params);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $this->flash('danger', 'Username and email must each be unique.');
                Response::redirect('/users/edit?id=' . $id);
            }
            throw $exception;
        }
        Audit::log('updated', 'user', $id);
        $this->flash('success', 'User updated.');
        Response::redirect('/users');
    }

    public function disable(): void
    {
        $this->status('disabled', 'User disabled.');
    }

    public function enable(): void
    {
        $this->status('active', 'User enabled.');
    }

    public function delete(): void
    {
        $actor = $this->requirePermission('users.manage');
        $this->requireAdminRole($actor);
        Csrf::verify();
        $ids = $this->selectedIds();
        if (!$ids) {
            http_response_code(404);
            echo 'User not found';
            return;
        }

        $deleteIds = [];
        foreach ($ids as $id) {
            if (!$this->findUser($id, $actor)) {
                continue;
            }
            if ($this->protectedAdminId() === $id) {
                $this->flash('danger', 'This is the only admin in the system. You cannot edit, disable, or delete this user.');
                Response::redirect('/users');
            }
            if ($id !== (int)($_SESSION['user_id'] ?? 0)) {
                $deleteIds[] = $id;
            }
        }
        if (!$deleteIds) {
            $this->flash('danger', 'Select at least one deletable user.');
            Response::redirect('/users');
        }

        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
        $stmt->execute($deleteIds);
        foreach ($deleteIds as $id) {
            Audit::log('deleted', 'user', $id);
        }
        $this->flash('success', count($deleteIds) === 1 ? 'User deleted.' : count($deleteIds) . ' users deleted.');
        Response::redirect('/users');
    }

    public function updatePermissions(): void
    {
        $actor = $this->requireAuth();
        if (!ScopeService::isAdmin($actor)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        Csrf::verify();
        UserPermissionService::ensureSchema();
        $targetId = (int)Request::input('user_id', 0);
        $stmt = $this->db->prepare(
            "SELECT users.*, roles.slug AS role_slug
             FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.id = ?"
        );
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            $this->flash('danger', 'User was not found.');
            Response::redirect('/users');
        }

        if (ScopeService::isAdmin($target)) {
            $this->flash('info', 'Sysadmin accounts already have all delete permissions.');
            Response::redirect('/users?permissions_user=' . $targetId);
        }

        $permissions = Request::input('permissions', []);
        $permissions = is_array($permissions) ? $permissions : [];
        UserPermissionService::replacePermissions($targetId, $permissions, (int)$actor['id']);
        Audit::log('updated', 'user_permissions', $targetId, [
            'permissions' => array_values(array_intersect(
                array_map('strval', $permissions),
                array_keys(UserPermissionService::managedPermissions())
            )),
        ]);
        $this->flash('success', 'Permissions updated for ' . $target['name'] . '.');
        Response::redirect('/users?permissions_user=' . $targetId);
    }

    private function status(string $status, string $message): void
    {
        $actor = $this->requirePermission('users.manage');
        $this->requireAdminRole($actor);
        Csrf::verify();
        $id = (int)Request::input('id');
        if (!$this->findUser($id, $actor)) {
            http_response_code(404);
            echo 'User not found';
            return;
        }
        if ($status === 'disabled' && $this->redirectIfProtectedAdmin($id, '/users')) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ? AND id <> ?');
        $stmt->execute([$status, $id, $_SESSION['user_id'] ?? 0]);
        Audit::log($status, 'user', $id);
        $this->flash('success', $message);
        Response::redirect('/users');
    }

    private function mfaUiData(array $actor, array $users, bool $canResetAnyMfa): array
    {
        $result = [
            'mfaTarget' => null,
            'mfaEnabled' => false,
        ];
        $targetId = (int)Request::input('mfa_user', 0);
        if ($targetId <= 0) {
            return $result;
        }

        $target = null;
        foreach ($users as $row) {
            if ((int)$row['id'] === $targetId) {
                $target = $row;
                break;
            }
        }
        if (!$target || !$canResetAnyMfa) {
            return $result;
        }

        $result['mfaTarget'] = $target;
        $result['mfaEnabled'] = !empty($target['mfa_enabled_at']) && !empty($target['mfa_secret_encrypted']);
        return $result;
    }

    private function permissionUiData(array $users, bool $canManageUserPermissions): array
    {
        $result = [
            'permissionTarget' => null,
            'permissionOptions' => UserPermissionService::managedPermissions(),
            'grantedPermissions' => [],
        ];
        $targetId = (int)Request::input('permissions_user', 0);
        if ($targetId <= 0 || !$canManageUserPermissions) {
            return $result;
        }

        foreach ($users as $row) {
            if ((int)$row['id'] !== $targetId) {
                continue;
            }
            $result['permissionTarget'] = $row;
            if (!ScopeService::isAdmin($row)) {
                $result['grantedPermissions'] = UserPermissionService::grantedPermissions($targetId);
            }
            break;
        }

        return $result;
    }

    private function formData(?array $user, array $actor): array
    {
        $offices = [];
        if (ScopeService::isAdmin($actor)) {
            $offices = $this->db->query('SELECT * FROM offices ORDER BY name')->fetchAll();
        } else {
            $officeIds = $this->managedOfficeIds($actor);
            $stmt = $this->db->prepare('SELECT * FROM offices WHERE id IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ') ORDER BY name');
            $stmt->execute($officeIds);
            $offices = $stmt->fetchAll();
        }

        return [
            'userRecord' => $user,
            'offices' => $offices,
            'canAssignAllOffices' => ScopeService::isAdmin($actor),
        ];
    }

    private function findUser(int $id, array $actor): ?array
    {
        $officeIds = $this->managedOfficeIds($actor);
        $where = ScopeService::isAdmin($actor) ? 'id = ?' : 'id = ? AND office_id IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ')';
        $params = ScopeService::isAdmin($actor) ? [$id] : array_merge([$id], $officeIds);
        $stmt = $this->db->prepare('SELECT * FROM users WHERE ' . $where);
        $stmt->execute($params);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    private function roleId(string $slug): int
    {
        $stmt = $this->db->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn();
    }

    private function protectedAdminId(): ?int
    {
        $admins = $this->db->query(
            "SELECT users.id
             FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE roles.slug IN ('super-admin', 'admin')
             ORDER BY users.id"
        )->fetchAll(PDO::FETCH_COLUMN);

        return count($admins) === 1 ? (int)$admins[0] : null;
    }

    private function redirectIfProtectedAdmin(int $id, string $redirect): bool
    {
        if ($this->protectedAdminId() !== $id) {
            return false;
        }

        $this->flash('danger', 'This is the only admin in the system. You cannot edit, disable, or delete this user.');
        Response::redirect($redirect);
        return true;
    }

    private function isProtectedAdminSelf(int $id): bool
    {
        return $this->protectedAdminId() === $id && $id === (int)($_SESSION['user_id'] ?? 0);
    }

    private function officeIdForAdmin(array $actor, string $redirect): ?int
    {
        $officeInput = trim((string)Request::input('office_id'));
        if ($officeInput === 'all') {
            if (!ScopeService::isAdmin($actor)) {
                $this->flash('danger', 'You can only assign users to your office.');
                Response::redirect($redirect);
            }
            return null;
        }

        $officeId = (int)$officeInput;
        if ($officeId <= 0) {
            $this->flash('danger', 'Assign the user to one office, or choose All Offices for an admin.');
            Response::redirect($redirect);
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM offices WHERE id = ?');
        $stmt->execute([$officeId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $this->flash('danger', 'Selected office was not found.');
            Response::redirect($redirect);
        }

        if (!ScopeService::isAdmin($actor) && !in_array($officeId, $this->managedOfficeIds($actor), true)) {
            $this->flash('danger', 'You can only assign users to your office tree.');
            Response::redirect($redirect);
        }

        return $officeId;
    }

    private function requireAdminRole(array $actor): void
    {
        if (!in_array($actor['role_slug'] ?? '', ['super-admin', 'admin'], true)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    private function managedOfficeIds(array $actor): array
    {
        if (ScopeService::isAdmin($actor)) {
            return [];
        }

        $officeIds = ScopeService::officeIdsForUser($actor);
        return $officeIds ?: [0];
    }

    private function usernameFromEmail(string $email, ?int $ignoreUserId): string
    {
        $base = strtolower((string)strstr($email, '@', true));
        $base = preg_replace('/[^a-z0-9._-]+/', '', $base) ?: 'user';
        $base = trim($base, '._-');
        if (strlen($base) < 3) {
            $base = 'user';
        }
        $base = substr($base, 0, 68);
        $username = $base;
        $suffix = 1;
        while ($this->usernameExists($username, $ignoreUserId)) {
            $suffix++;
            $username = substr($base, 0, 68) . '-' . $suffix;
        }
        return $username;
    }

    private function usernameExists(string $username, ?int $ignoreUserId): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE username = ?';
        $params = [$username];
        if ($ignoreUserId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreUserId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function validateUpdatedCredentials(string $username, string $password, string $passwordConfirmation, int $userId, string $redirect): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', $username)) {
            $this->flash('danger', 'Username must be 3-80 characters and use only letters, numbers, dots, underscores, or hyphens.');
            Response::redirect($redirect);
        }
        if ($this->usernameExists($username, $userId)) {
            $this->flash('danger', 'Username is already in use.');
            Response::redirect($redirect);
        }
        if ($password !== '' && strlen($password) < 8) {
            $this->flash('danger', 'Password must be at least 8 characters.');
            Response::redirect($redirect);
        }
        if ($password !== $passwordConfirmation) {
            $this->flash('danger', 'Password and confirmation password do not match.');
            Response::redirect($redirect);
        }
    }

    private function selectedIds(): array
    {
        $rawIds = Request::input('ids', []);
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }
        $rawIds[] = Request::input('id', 0);
        $ids = array_map('intval', $rawIds);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }
}
