<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BR-29 — changing a password ends every other session of that account, for
 * real and on the server.
 *
 * Rotating the remember-token alone is not enough: sessions live in a database
 * table on shared hosting, and a row that is not deleted is a way back in. The
 * rows are therefore removed, the current session is kept and re-keyed, and the
 * account keeps working in the browser that made the change.
 *
 * @see BR-29 · PRD §9.3.3, §12.1 · CONSTITUTION Art. 24
 */
trait InvalidatesOtherSessions
{
    /**
     * @return int number of sessions destroyed
     */
    protected function invalidateOtherSessions(Request $request, User $user): int
    {
        $destroyed = 0;

        if ((string) config('session.driver') === 'database') {
            $table = (string) config('session.table', 'user_sessions');
            $currentId = $request->hasSession() ? $request->session()->getId() : null;

            $query = DB::table($table)->where('user_id', $user->getKey());

            if (is_string($currentId) && $currentId !== '') {
                $query->where('id', '!=', $currentId);
            }

            $destroyed = $query->delete();
        }

        // A stolen "remember me" cookie must die with the old sessions.
        $user->forceFill(['remember_token' => null])->save();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $destroyed;
    }
}
