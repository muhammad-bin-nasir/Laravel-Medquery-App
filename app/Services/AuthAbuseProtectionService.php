<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthAbuseProtectionService
{
    public const HONEYPOT_FIELDS = ['website', 'company_url', 'fax_number'];

    private int $maxFailures;

    private int $failureWindowSeconds;

    private int $blockSeconds;

    private int $massLimit;

    private int $massWindowSeconds;

    public function __construct()
    {
        $this->maxFailures = max(3, (int) env('AUTH_MAX_FAILURES', 8));
        $this->failureWindowSeconds = max(60, (int) env('AUTH_FAILURE_WINDOW_SECONDS', 900));
        $this->blockSeconds = max(60, (int) env('AUTH_BLOCK_SECONDS', 1800));
        $this->massLimit = max(5, (int) env('AUTH_MASS_SUBMISSION_LIMIT', 20));
        $this->massWindowSeconds = max(30, (int) env('AUTH_MASS_WINDOW_SECONDS', 60));
    }

    public function clientIp(Request $request): string
    {
        return (string) ($request->ip() ?: 'unknown');
    }

    public function isBlocked(Request $request): bool
    {
        return Cache::has($this->blockKey($this->clientIp($request)));
    }

    public function blockRemainingSeconds(Request $request): int
    {
        $ttl = Cache::get($this->blockKey($this->clientIp($request)).':ttl');
        if (is_numeric($ttl)) {
            return max(0, (int) $ttl - time());
        }

        return $this->blockSeconds;
    }

    /**
     * Reject bots / blocked IPs / mass submitters before credential checks.
     *
     * @return array{ok:bool,status?:int,detail?:string,code?:string}
     */
    public function guard(Request $request, string $action = 'auth'): array
    {
        $ip = $this->clientIp($request);

        if ($this->isBlocked($request)) {
            return [
                'ok' => false,
                'status' => 429,
                'detail' => 'Too many failed attempts from this IP. Try again later.',
                'code' => 'ip_blocked',
            ];
        }

        if ($this->isMassSubmitting($ip, $action)) {
            $this->blockIp($ip, 'mass_submission');

            return [
                'ok' => false,
                'status' => 429,
                'detail' => 'Too many requests from this IP. Access temporarily blocked.',
                'code' => 'ip_blocked',
            ];
        }

        if ($this->honeypotFilled($request)) {
            Log::warning('auth.honeypot_triggered', ['ip' => $ip, 'action' => $action]);
            $this->registerFailure($request, $action, 'honeypot');

            return [
                'ok' => false,
                'status' => 422,
                'detail' => 'Unable to process this request.',
                'code' => 'bot_detected',
            ];
        }

        // One-time post-signup ticket can skip captcha for the immediate auto-login.
        if ($action === 'user_login' && $this->consumeSignupTicket($request)) {
            return ['ok' => true];
        }

        $captcha = $this->verifyCaptcha($request);
        if (! $captcha['ok']) {
            $this->registerFailure($request, $action, 'captcha');

            return $captcha;
        }

        return ['ok' => true];
    }

    public function issueSignupTicket(string $email, string $ip): string
    {
        $ticket = (string) Str::uuid();
        Cache::put($this->signupTicketKey($ticket), [
            'email' => strtolower(trim($email)),
            'ip' => $ip,
        ], 120);

        return $ticket;
    }

    private function consumeSignupTicket(Request $request): bool
    {
        $ticket = trim((string) $request->input('signup_ticket', ''));
        if ($ticket === '') {
            return false;
        }

        $payload = Cache::pull($this->signupTicketKey($ticket));
        if (! is_array($payload)) {
            return false;
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $ip = $this->clientIp($request);

        return ($payload['email'] ?? '') === $email && ($payload['ip'] ?? '') === $ip;
    }

    private function signupTicketKey(string $ticket): string
    {
        return "auth:signup_ticket:{$ticket}";
    }

    public function registerFailure(Request $request, string $action = 'auth', ?string $reason = null): void
    {
        $ip = $this->clientIp($request);
        $key = $this->failKey($ip);
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, $this->failureWindowSeconds);

        Log::info('auth.failure', [
            'ip' => $ip,
            'action' => $action,
            'reason' => $reason,
            'failures' => $count,
        ]);

        if ($count >= $this->maxFailures) {
            $this->blockIp($ip, $reason ?: 'brute_force');
        }
    }

    public function clearFailures(Request $request): void
    {
        Cache::forget($this->failKey($this->clientIp($request)));
    }

    public function blockIp(string $ip, string $reason = 'abuse'): void
    {
        $blockKey = $this->blockKey($ip);
        Cache::put($blockKey, [
            'reason' => $reason,
            'blocked_at' => now()->toIso8601String(),
        ], $this->blockSeconds);
        Cache::put($blockKey.':ttl', time() + $this->blockSeconds, $this->blockSeconds);

        Log::warning('auth.ip_blocked', [
            'ip' => $ip,
            'reason' => $reason,
            'seconds' => $this->blockSeconds,
        ]);
    }

    public function honeypotFilled(Request $request): bool
    {
        foreach (self::HONEYPOT_FIELDS as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ok:bool,status?:int,detail?:string,code?:string}
     */
    public function verifyCaptcha(Request $request): array
    {
        /** @var SystemConfigService $config */
        $config = app(SystemConfigService::class);
        $siteKey = $config->turnstileSiteKey();
        $secret = $config->turnstileSecretKey();

        if ($siteKey !== '' && $secret !== '') {
            $token = trim((string) $request->input('captcha_token', ''));
            if ($token === '') {
                return [
                    'ok' => false,
                    'status' => 422,
                    'detail' => 'Please complete the captcha.',
                    'code' => 'captcha_required',
                ];
            }

            try {
                $response = Http::asForm()
                    ->timeout(8)
                    ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                        'secret' => $secret,
                        'response' => $token,
                        'remoteip' => $this->clientIp($request),
                    ]);
            } catch (ConnectionException $e) {
                Log::error('auth.turnstile_unreachable', ['error' => $e->getMessage()]);

                return [
                    'ok' => false,
                    'status' => 503,
                    'detail' => 'Captcha verification is temporarily unavailable.',
                    'code' => 'captcha_unavailable',
                ];
            }

            $body = $response->json();
            if (! ($body['success'] ?? false)) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'detail' => 'Captcha verification failed. Please try again.',
                    'code' => 'captcha_failed',
                ];
            }

            return ['ok' => true];
        }

        // Fallback math challenge when Turnstile keys are not configured / disabled.
        $challengeId = trim((string) $request->input('challenge_id', ''));
        $answer = trim((string) $request->input('captcha_answer', ''));

        if ($challengeId === '' || $answer === '') {
            return [
                'ok' => false,
                'status' => 422,
                'detail' => 'Please solve the captcha challenge.',
                'code' => 'captcha_required',
            ];
        }

        $expected = Cache::pull($this->challengeKey($challengeId));
        if ($expected === null) {
            return [
                'ok' => false,
                'status' => 422,
                'detail' => 'Captcha expired. Refresh and try again.',
                'code' => 'captcha_expired',
            ];
        }

        if ((string) $expected !== (string) $answer) {
            return [
                'ok' => false,
                'status' => 422,
                'detail' => 'Incorrect captcha answer.',
                'code' => 'captcha_failed',
            ];
        }

        return ['ok' => true];
    }

    /**
     * @return array{mode:string,site_key?:string,challenge_id?:string,question?:string}
     */
    public function issueChallenge(): array
    {
        /** @var SystemConfigService $config */
        $config = app(SystemConfigService::class);
        $siteKey = $config->turnstileSiteKey();
        $secret = $config->turnstileSecretKey();

        if ($siteKey !== '' && $secret !== '') {
            return [
                'mode' => 'turnstile',
                'site_key' => $siteKey,
            ];
        }

        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $challengeId = (string) Str::uuid();
        Cache::put($this->challengeKey($challengeId), (string) ($a + $b), 600);

        return [
            'mode' => 'math',
            'challenge_id' => $challengeId,
            'question' => "What is {$a} + {$b}?",
        ];
    }

    private function isMassSubmitting(string $ip, string $action): bool
    {
        $key = "auth:mass:{$action}:{$ip}";
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, $this->massWindowSeconds);

        return $count > $this->massLimit;
    }

    private function failKey(string $ip): string
    {
        return "auth:fail:{$ip}";
    }

    private function blockKey(string $ip): string
    {
        return "auth:block:{$ip}";
    }

    private function challengeKey(string $id): string
    {
        return "auth:challenge:{$id}";
    }
}
