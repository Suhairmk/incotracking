<?php

namespace App\Policies;

use App\Models\Shop;
use App\Models\User;

class ShopPolicy
{
    /**
     * Every active user may read the shared directory: the point of the
     * directory is that executives see shops added by their colleagues too.
     */
    public function view(User $user, Shop $shop): bool
    {
        return $user->is_active;
    }

    /**
     * Executives manage their own entries; administrators manage all of them.
     */
    public function update(User $user, Shop $shop): bool
    {
        return $user->isAdmin() || $shop->created_by === $user->id;
    }

    public function delete(User $user, Shop $shop): bool
    {
        return $this->update($user, $shop);
    }

    public function create(User $user): bool
    {
        return $user->isEmployee() || $user->isAdmin();
    }
}
