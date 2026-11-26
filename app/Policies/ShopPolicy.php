<?php

namespace App\Policies;

use App\Models\Shop;
use App\Models\User;

class ShopPolicy
{
    /**
     * Reading the shared directory: administrators see every entry, and
     * executives see the ones the administrator has granted them — unless the
     * shop has been hidden from the field entirely.
     */
    public function view(User $user, Shop $shop): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $shop->is_visible_to_field && $this->assignedTo($user, $shop);
    }

    /**
     * Executives manage the shops they have been granted — the creator and
     * anybody else the administrator listed; administrators manage all of
     * them. A hidden shop is out of an executive's reach for editing too —
     * they cannot see it, so they cannot change it.
     */
    public function update(User $user, Shop $shop): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $shop->is_visible_to_field && $this->assignedTo($user, $shop);
    }

    public function delete(User $user, Shop $shop): bool
    {
        return $this->update($user, $shop);
    }

    public function create(User $user): bool
    {
        return $user->isEmployee() || $user->isAdmin();
    }

    /**
     * Reading the credit ledger follows shop access: administrators see
     * every shop, executives only the ones granted to them.
     */
    public function viewCredit(User $user, Shop $shop): bool
    {
        return $user->isAdmin()
            || ($shop->is_visible_to_field && $this->assignedTo($user, $shop));
    }

    /**
     * Adding and correcting ledger entries: administrators anywhere,
     * executives only on their own assigned shops.
     */
    public function updateCredit(User $user, Shop $shop): bool
    {
        return $user->isAdmin()
            || ($shop->is_visible_to_field && $this->assignedTo($user, $shop));
    }

    /**
     * Whether the executive may work with this shop at all: they created it,
     * or the administrator listed them on it. Kept as one method so every
     * shop-scoped feature follows the same access list.
     */
    private function assignedTo(User $user, Shop $shop): bool
    {
        return $shop->isAssignedTo($user);
    }
}
