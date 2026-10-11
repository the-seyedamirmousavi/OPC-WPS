<?php

namespace App\Services;

use App\Models\AppUser;
use Illuminate\Support\Facades\Cache;

/**
 * Connects an AISO account to a Bale chat. The user asks for a short code in the app and sends "/link CODE" to the
 * bot; the code is single-use and expires after CODE_MINUTES. A chat belongs to one account at a time.
 */
class BaleLinkService
{
    public const CODE_MINUTES = 15;

    /** No look-alike characters (no 0/O, 1/I/L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 8;

    public function createLinkCode(AppUser $user): string
    {
        $ttl = now()->addMinutes(self::CODE_MINUTES);
        do {
            $code = $this->randomCode();
        } while (!Cache::add(self::key($code), $user->id, $ttl));

        return $code;
    }

    /**
     * Uses a code sent from a Bale chat. Returns the connected user, or null when the code is unknown, expired, already
     * used, or belongs to an inactive account. The code is deleted on every attempt that finds it.
     */
    public function consume(string $code, string $chatId): ?AppUser
    {
        $code = strtoupper(trim($code));
        if (preg_match('/\A[A-Z2-9]{' . self::CODE_LENGTH . '}\z/', $code) !== 1) {
            return null;
        }
        $userId = Cache::pull(self::key($code));
        if (!is_string($userId)) {
            return null;
        }
        $user = AppUser::query()->find($userId);
        if ($user === null || !$user->isActive()) {
            return null;
        }

        // the chat now belongs to this account: detach it from any other account that still points at it
        AppUser::query()->where('messenger_id', $chatId)->whereKeyNot($user->id)->update(['messenger_id' => null]);
        $user->messenger_id = $chatId;
        $user->save();

        return $user;
    }

    public function unlink(AppUser $user): void
    {
        $user->messenger_id = null;
        $user->save();
    }

    public function isConnected(AppUser $user): bool
    {
        return $user->messenger_id !== null && $user->messenger_id !== '';
    }

    private function randomCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private static function key(string $code): string
    {
        return 'bale:link:' . $code;
    }
}
