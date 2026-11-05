<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\ApiProblem;
use App\Models\User;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Authenticator devices for two-factor authentication (plan 01 §9.2): a user may
 * register up to two. A device is added unconfirmed (we show its QR code) and only
 * counts once the user proves it works with a valid code. The first confirmed device
 * creates the recovery codes; removing the last one turns 2FA off, unless the
 * user's role requires 2FA.
 */
final class TwoFactorDevices
{
    public function __construct(private readonly TwoFactorAuthenticationProvider $totp) {}

    /**
     * @return array{device: TwoFactorDevice, secret: string, otpauth_url: string, qr_svg: string}
     */
    public function begin(User $user, string $name): array
    {
        return DB::transaction(function () use ($user, $name) {
            // An abandoned setup shouldn't block a new one: drop unconfirmed devices first.
            $user->twoFactorDevices()->whereNull('confirmed_at')->delete();

            if ($user->twoFactorDevices()->count() >= TwoFactorDevice::MAX_PER_USER) {
                throw ApiProblem::conflict('TWO_FACTOR_DEVICE_LIMIT', 'You already have '.TwoFactorDevice::MAX_PER_USER.' authenticator devices. Remove one to add another.');
            }

            $secret = $this->totp->generateSecretKey();
            /** @var TwoFactorDevice $device */
            $device = $user->twoFactorDevices()->create(['name' => trim($name), 'secret' => $secret]);
            $url = $this->totp->qrCodeUrl((string) config('app.name'), $user->email, $secret);

            return ['device' => $device, 'secret' => $secret, 'otpauth_url' => $url, 'qr_svg' => self::qrSvg($url)];
        });
    }

    /**
     * Confirms a device with a code from it. Returns the recovery codes when this is
     * the user's first device (so they can store them), otherwise null.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, TwoFactorDevice $device, string $code): ?array
    {
        if ($device->user_id !== $user->id) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Not found', 'The requested resource does not exist.');
        }
        if ($device->isConfirmed()) {
            throw ApiProblem::conflict('TWO_FACTOR_DEVICE_CONFIRMED', 'This device is already confirmed.');
        }
        if (! $this->totp->verify($device->secret, $code)) {
            throw ApiProblem::unprocessable('TWO_FACTOR_CODE_INVALID', 'That code is not valid. Check the time on your phone and try again.');
        }

        return DB::transaction(function () use ($user, $device) {
            $first = ! $user->hasTwoFactorEnabled();
            $device->forceFill(['confirmed_at' => now(), 'last_used_at' => now()])->save();
            if ($first) {
                app(GenerateNewRecoveryCodes::class)($user);
            }
            app(AuditLogger::class)->record('user.two_factor_device_added', $user, ['after' => ['device' => $device->public_id, 'name' => $device->name]]);

            return $first ? $user->fresh()?->recoveryCodes() : null;
        });
    }

    public function remove(User $user, TwoFactorDevice $device): void
    {
        if ($device->user_id !== $user->id) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Not found', 'The requested resource does not exist.');
        }

        $confirmed = $user->twoFactorDevices()->whereNotNull('confirmed_at')->count();
        if ($device->isConfirmed() && $confirmed === 1 && $user->twoFactorEnforced()) {
            throw ApiProblem::conflict('TWO_FACTOR_ENFORCED', 'Your role requires two-factor authentication. Add another device before removing this one.');
        }

        DB::transaction(function () use ($user, $device, $confirmed) {
            $device->delete();
            if ($device->isConfirmed()) {
                if ($confirmed === 1) {
                    // 2FA is now off: recovery codes no longer apply.
                    $user->forceFill(['two_factor_recovery_codes' => null])->save();
                }
                app(AuditLogger::class)->record('user.two_factor_device_removed', $user, ['before' => ['device' => $device->public_id, 'name' => $device->name]]);
            }
        });
    }

    /** The confirmed device the code belongs to, or null. Marks it as used. */
    public function verifyForLogin(User $user, string $code): ?TwoFactorDevice
    {
        foreach ($user->twoFactorDevices()->whereNotNull('confirmed_at')->get() as $device) {
            if ($this->totp->verify($device->secret, $code)) {
                $device->forceFill(['last_used_at' => now()])->save();

                return $device;
            }
        }

        return null;
    }

    private static function qrSvg(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(11, 59, 59))),
            new SvgImageBackEnd,
        )))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
