<?php

namespace App\Domain\Auth\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Password reset email, queued and sent only after the database commit
 * (plan 01 §1). The link opens the Next.js reset page, not a Laravel view.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }

    protected function resetUrl($notifiable): string
    {
        return rtrim((string) config('app.url'), '/').'/reset-password/?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
