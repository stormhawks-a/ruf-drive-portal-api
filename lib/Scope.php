<?php

/** Access-scope checks: CUSTOMER users may only touch folders/files within their own root folder. */
final class Scope
{
    public static function isWithinCustomerRoot(?string $folderId, string $rootFolderId): bool
    {
        $current = $folderId;
        $guard = 0;
        while ($current !== null && $guard < 100) {
            if ($current === $rootFolderId) {
                return true;
            }
            $row = Db::queryOne('SELECT parent_id FROM folders WHERE id = ?', [$current]);
            $current = $row['parent_id'] ?? null;
            $guard++;
        }
        return false;
    }

    public static function assertFolderAccessible(array $user, ?string $folderId): void
    {
        if ($user['role'] !== 'CUSTOMER') {
            return; // staff: unrestricted
        }
        if ($folderId === null || $user['folder_id'] === null) {
            Response::error('Yetkisiz erişim.', 403);
        }
        if ($folderId === $user['folder_id']) {
            return;
        }
        if (!self::isWithinCustomerRoot($folderId, $user['folder_id'])) {
            Response::error('Yetkisiz erişim.', 403);
        }
    }

}
