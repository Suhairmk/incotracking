<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Administrators see every order; an executive only their own, which is
     * also the list the app shows them.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->isAdmin() || $order->employee_id === $user->id;
    }

    /**
     * Marking delivered (or cancelling) is the administrator's call or the
     * creating executive's, nobody else's — the transition rules themselves
     * live in the controller so both roles move through identical states.
     */
    public function updateStatus(User $user, Order $order): bool
    {
        return $this->view($user, $order);
    }
}
