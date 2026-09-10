<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\User;

/**
 * Who may work with a node of the shared tree.
 *
 * Membership of the node's OWN team decides it - taken from the row, never
 * from the session's current team. Every user has a personal team
 * (Jetstream's CreateNewUser guarantees one), so personal filing is the
 * same check as team filing rather than a second code path.
 *
 * A tree row is filing, not access: putting a document in a folder never
 * widens or narrows who can read it. That stays entirely DocumentPolicy's,
 * which is why delete() - the one destructive verb that reaches a real
 * document - asks DocumentPolicy as well.
 */
class ObjPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Obj $obj): bool
    {
        return $this->inTeam($user, $obj);
    }

    /**
     * Creating happens INSIDE a parent, so the parent is what is checked.
     * Called as Gate::authorize('create', [Obj::class, $parent]).
     */
    public function create(User $user, ?Obj $parent = null): bool
    {
        return $parent !== null && $this->inTeam($user, $parent);
    }

    public function move(User $user, Obj $obj): bool
    {
        return $this->inTeam($user, $obj);
    }

    public function rename(User $user, Obj $obj): bool
    {
        return $this->inTeam($user, $obj);
    }

    public function delete(User $user, Obj $obj): bool
    {
        if (! $this->inTeam($user, $obj)) {
            return false;
        }

        $objectable = $obj->objectable;

        if ($objectable instanceof Document) {
            return $user->can('delete', $objectable);
        }

        return true;
    }

    private function inTeam(User $user, Obj $obj): bool
    {
        $team = $obj->team;

        return $team !== null && $user->belongsToTeam($team);
    }
}
