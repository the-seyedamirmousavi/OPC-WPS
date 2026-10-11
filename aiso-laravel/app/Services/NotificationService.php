<?php

namespace App\Services;

use App\Enums\Role;
use App\Messaging\BaleClient;
use App\Models\AppUser;
use App\Models\Notification;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores every notification in the in-app inbox and attempts delivery through the configured messenger platform. A
 * failed external delivery is recorded (delivery_status = FAILED) so it can be detected and reported.
 */
class NotificationService
{
    public function __construct(
        private readonly LanguageService $lang,
        private readonly BaleClient $bale,
    ) {
    }

    public function notifyUser(?string $userId, string $kind, string $message, ?string $operationId): void
    {
        if ($userId === null) {
            return;
        }
        $user = AppUser::query()->find($userId);
        if ($user === null) {
            return;
        }
        $message = $this->lang->forStorage($message);
        $n = new Notification([
            'recipient_id' => $userId,
            'kind' => $kind,
            'message' => mb_substr($message, 0, 1000),
            'related_operation_id' => $operationId,
            'created_at' => CarbonImmutable::now(),
            'delivery_status' => 'SENT',
        ]);
        try {
            $this->deliver($message, $user);
        } catch (Throwable $e) {
            Log::warning("Messenger delivery to {$userId} failed: {$e->getMessage()}");
            $n->delivery_status = 'FAILED';
            $n->delivery_error = mb_substr($e->getMessage() ?: $e::class, 0, 500);
        }
        $n->save();
    }

    public function notifyManagers(string $kind, string $message, ?string $operationId): void
    {
        $managers = AppUser::query()->where('user_role', Role::MANAGER->value)->get();
        foreach ($managers as $m) {
            if ($m->isActive()) {
                $this->notifyUser($m->id, $kind, $message, $operationId);
            }
        }
    }

    /**
     * Messenger connector seam. IN_APP sends nothing beyond the inbox. BALE sends the message to the recipient's linked
     * Bale chat; a user without a linked chat keeps the inbox message only. Any other platform name configured in
     * Settings fails visibly instead of silently dropping the message. Add a connector (e.g. Telegram) here.
     */
    private function deliver(string $message, AppUser $recipient): void
    {
        $platform = SystemSetting::current()->messenger_platform;
        if ($platform === null || trim($platform) === '' || strcasecmp($platform, 'IN_APP') === 0) {
            return;
        }
        if (strcasecmp($platform, 'BALE') === 0) {
            $chatId = trim((string) $recipient->messenger_id);
            if ($chatId === '') {
                Log::debug("No Bale chat linked for {$recipient->id}; the in-app inbox is used only.");

                return;
            }
            $this->bale->sendMessage($chatId, $message);

            return;
        }
        throw new \RuntimeException("No connector installed for messenger platform {$platform}");
    }
}
