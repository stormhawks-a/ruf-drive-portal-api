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

    /** Walks up from $folderId to find which customer's root folder it lives under
        (if any), returning that customer's user id. Used so a staff member deleting
        something while browsing INTO a customer's folder tree (from the admin
        dashboard's own file browser — not a share link) still records the trash
        item as deleted by that customer, matching what a "Müşteri" share-link
        visitor's deletes already do — rather than showing the staff member's own
        name, which reads as a confusing/wrong "who did this" to anyone looking at
        the customer's trash later. Returns null for folders outside any customer's
        tree (e.g. a top-level folder staff created themselves). */
    public static function resolveOwningCustomerId(?string $folderId): ?string
    {
        $current = $folderId;
        $guard = 0;
        while ($current !== null && $guard < 100) {
            $owner = Db::queryOne('SELECT id FROM users WHERE folder_id = ? AND role = ?', [$current, 'CUSTOMER']);
            if ($owner !== null) {
                return $owner['id'];
            }
            $row = Db::queryOne('SELECT parent_id FROM folders WHERE id = ?', [$current]);
            $current = $row['parent_id'] ?? null;
            $guard++;
        }
        return null;
    }
}
