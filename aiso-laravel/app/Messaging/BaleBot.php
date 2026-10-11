<?php

namespace App\Messaging;

use App\Models\AppUser;
use App\Services\BaleLinkService;

/**
 * Handles one Bale update: /start, /link CODE, /unlink, and any other text (answered with a short help). Replies are
 * plain Persian text. Updates without text (photos, edits, service messages) are ignored.
 */
class BaleBot
{
    private const START = "به ربات AISO خوش آمدید.\nبرای اتصال، در برنامه AISO روی «اتصال به بله» بزنید و کد را اینجا بفرستید: /link CODE";
    private const LINK_USAGE = 'کد اتصال را بعد از دستور بنویسید، مثلاً: /link CODE';
    private const LINK_OK = 'اتصال انجام شد. اعلان‌های AISO از این پس به همین چت فرستاده می‌شود.';
    private const LINK_FAILED = 'کد نامعتبر یا منقضی شده است. در برنامه AISO کد جدید بسازید و دوباره بفرستید.';
    private const UNLINK_OK = 'اتصال این چت با AISO قطع شد.';
    private const UNLINK_NONE = 'این چت به هیچ حساب AISO متصل نیست.';
    private const HELP = "دستور شناخته نشد.\n/link CODE برای اتصال حساب\n/unlink برای قطع اتصال";

    public function __construct(
        private readonly BaleClient $client,
        private readonly BaleLinkService $links,
    ) {
    }

    /** @param array<string, mixed> $update one element of the getUpdates result */
    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        if (!is_array($message) || !isset($message['text']) || !is_string($message['text'])) {
            return;
        }
        $chatId = (string) ($message['chat']['id'] ?? '');
        if ($chatId === '') {
            return;
        }

        $text = trim($message['text']);
        // "/link@BotName CODE" is accepted too (group chats append the bot name)
        if (preg_match('/\A\/([A-Za-z]+)(?:@\S+)?(?:\s+(.*))?\z/su', $text, $m) !== 1) {
            $this->reply($chatId, self::HELP);

            return;
        }
        $command = strtolower($m[1]);
        $argument = trim($m[2] ?? '');

        match ($command) {
            'start' => $this->reply($chatId, self::START),
            'link' => $this->link($chatId, $argument),
            'unlink' => $this->unlink($chatId),
            default => $this->reply($chatId, self::HELP),
        };
    }

    private function link(string $chatId, string $code): void
    {
        if ($code === '') {
            $this->reply($chatId, self::LINK_USAGE);

            return;
        }
        $user = $this->links->consume($code, $chatId);
        $this->reply($chatId, $user !== null ? self::LINK_OK : self::LINK_FAILED);
    }

    private function unlink(string $chatId): void
    {
        $user = AppUser::query()->where('messenger_id', $chatId)->first();
        if ($user === null) {
            $this->reply($chatId, self::UNLINK_NONE);

            return;
        }
        $this->links->unlink($user);
        $this->reply($chatId, self::UNLINK_OK);
    }

    private function reply(string $chatId, string $text): void
    {
        $this->client->sendMessage($chatId, $text);
    }
}
