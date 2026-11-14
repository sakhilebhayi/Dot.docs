<?php

declare(strict_types=1);

namespace Dot\Ui;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The platform switcher's view model (R-51).
 *
 * Asks InfoDot which platforms the CURRENT company holds, for the platform that is asking, and turns the answer into
 * something a template can print without deciding anything. It never throws: every failure is the `degraded` state.
 *
 *   ok        InfoDot answered and the answer was readable. Only rows with `entitled === true` are links.
 *   degraded  InfoDot did not give a usable answer (outage, 401/403/404/429/5xx, timeout, unreadable body, no secret).
 *             Shows the static core set as "open via InfoDot" tiles and a visible "could not check" notice. Never says
 *             a platform is active. A stale tile cannot grant anything: every tile goes through InfoDot's /go, where
 *             InfoDot decides again for the person.
 *   personal  A personal workspace (or no company at all): one sentence, no list, nothing asked.
 *
 * The answer is about the COMPANY only (InfoDot's read endpoint says nothing about the person), so a tile means "the
 * company has it", not "you may open it". InfoDot's /go makes the per-person decision and explains a refusal.
 *
 * Nothing here trusts the browser: the platform key, company uuid and secret come from the server. The view model
 * carries no secret and no token; links are `{infodot}/go/<key>?company=<uuid>` (a company id is an identifier, not a
 * credential), and InfoDot validates membership.
 */
final class PlatformSwitcher
{
    public const STATE_OK = 'ok';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_PERSONAL = 'personal';

    public const ANSWER_SECONDS = 600;

    public const FAILURE_SECONDS = 60;

    /** `entitlement()` answers, the three a platform's own access check works with. */
    public const HELD = 'held';

    public const NOT_HELD = 'not_held';

    public const UNKNOWN = 'unknown';

    /** The key the answer is cached under unless a host changes it. `{platform}` and `{company}` are replaced. */
    public const DEFAULT_CACHE_KEY = 'infodot:platforms:{platform}:{company}';

    /**
     * The category, plain, core and naming data live in ONE file, `data/categories.json` (one level above `src/`), read
     * here and turned into the generated `static/dot-switcher-data.js` for static hosts. Tests fail when they drift.
     */
    private static ?array $data = null;

    private readonly string $infodot;

    private readonly Http $http;

    /**
     * @param  string  $platform  This platform's own key, as InfoDot knows it (config `ecosystem.infodot.platform`).
     * @param  string  $infodotUrl  InfoDot's base URL (config `ecosystem.platforms.infodot.url`).
     * @param  string|null  $secret  This platform's service secret (config `ecosystem.infodot.secret`).
     * @param  string|null  $companiesUrl  Where the read goes, `{companies_url}/{uuid}/platforms`. Blank: `{infodot}/api/ecosystem/companies`.
     * @param  Cache|null  $cache  The answer cache. `null` is for a host that never asks (see `plain()`).
     * @param  string|\Closure|null  $cacheKey  Where the answer is cached. A string with `{platform}` and `{company}`
     *                                          placeholders, or `fn (string $platform, string $company): string`.
     *                                          Default `infodot:platforms:{platform}:{company}`. See "Sharing the answer" in docs/SWITCHER.md.
     */
    public function __construct(
        private readonly string $platform,
        string $infodotUrl,
        #[\SensitiveParameter] private readonly ?string $secret,
        private readonly ?Cache $cache,
        ?Http $http = null,
        private readonly ?LoggerInterface $log = null,
        private readonly ?string $companiesUrl = null,
        private readonly int $timeout = 3,
        private readonly int $connectTimeout = 2,
        private readonly string|\Closure|null $cacheKey = null,
    ) {
        $this->infodot = rtrim($infodotUrl, '/');
        $this->http = $http ?? new Http();
    }

    /**
     * Built from the keys every Dot platform already has: `ecosystem.infodot.{platform,secret,redeem_url,companies_url,
     * timeout}` and `ecosystem.platforms.infodot.url`. Laravel only; the constructor is the framework-free door.
     * `$platform` overrides `ecosystem.infodot.platform` (the component's `platform` prop).
     */
    public static function fromConfig(?string $platform = null): self
    {
        $config = static fn (string $key, mixed $default = null): mixed => config($key, $default);

        $redeem = (string) $config('ecosystem.infodot.redeem_url', '');
        $base = (string) ($config('ecosystem.platforms.infodot.url') ?: self::origin($redeem) ?: 'https://infodot.co.za');
        $companies = (string) $config('ecosystem.infodot.companies_url', '');
        $secret = $config('ecosystem.infodot.secret');

        return new self(
            platform: $platform !== null && $platform !== '' ? $platform : (string) $config('ecosystem.infodot.platform', ''),
            infodotUrl: $base,
            secret: is_string($secret) ? $secret : null,
            cache: app('cache')->store(),
            http: app(Http::class),
            log: app()->bound(LoggerInterface::class) ? app(LoggerInterface::class) : null,
            companiesUrl: $companies !== '' ? $companies : ($redeem !== '' ? self::beforeLast($redeem, '/').'/companies' : null),
            timeout: max(1, (int) $config('ecosystem.infodot.switcher_timeout', 3)),
            cacheKey: is_string($key = $config('ecosystem.infodot.switcher_cache_key')) && $key !== '' ? $key : null,
        );
    }

    /**
     * @return array{
     *   state: string, current: string, company: ?string, infodot_url: string, platforms_url: string,
     *   notice: ?string, message: ?string, count: int,
     *   groups: list<array{code: string, tone: string, name: string, tiles: list<array<string, mixed>>}>,
     *   more: list<array{key: string, name: string, href: string}>
     * }
     */
    public function for(?string $company): array
    {
        $company = $this->cleanUuid($company);

        if ($this->platform === '' || $company === null) {
            return $this->personal($company);
        }

        $answer = $this->answer($company);

        if ($answer['ok'] !== true) {
            return $this->degraded($company);
        }

        if ($answer['personal'] === true) {
            return $this->personal($company);
        }

        return $this->ready($company, $answer['rows']);
    }

    /**
     * The cached answer about a company, as the view model reads it: `ok` (InfoDot gave a usable answer), `personal`,
     * and `rows` (`key`, `name`, `entitled`, `plain`, `status`). It asks InfoDot at most once per cache window, and a
     * failure is `ok: false` and is kept 60 seconds. Null/invalid company: `ok: false`, nothing asked.
     *
     * @return array{ok: bool, personal: bool, rows: list<array{key: string, name: string, entitled: bool, plain: bool, status: ?string}>}
     */
    public function answerFor(?string $company): array
    {
        $company = $this->cleanUuid($company);

        if ($this->platform === '' || $company === null) {
            return ['ok' => false, 'personal' => false, 'rows' => []];
        }

        return $this->answer($company);
    }

    /**
     * What this platform's own access check needs, from the SAME cached answer the switcher uses (one InfoDot read,
     * one cache entry). Three answers, never two, as in the platforms' entitlement clients:
     *
     *   held      the answer lists this platform for the company and says `entitled: true`.
     *   not_held  it lists this platform and says `entitled: false` (`status` carries InfoDot's wording key).
     *   unknown   anything else: no answer, a failed read, a personal workspace, this platform's row missing. The
     *             caller decides what that means; a fail-open check carries on, exactly as before.
     *
     * The switcher itself stays fail-closed for display (an unusable answer is the degraded state); only the caller of
     * this method decides what "unknown" lets through.
     *
     * @return array{state: string, status: ?string}
     */
    public function entitlement(?string $company): array
    {
        $answer = $this->answerFor($company);

        if ($answer['ok'] !== true || $answer['personal'] === true) {
            return ['state' => self::UNKNOWN, 'status' => null];
        }

        foreach ($answer['rows'] as $row) {
            if (($row['key'] ?? null) === $this->platform) {
                return ['state' => $row['entitled'] ? self::HELD : self::NOT_HELD, 'status' => $row['status'] ?? null];
            }
        }

        return ['state' => self::UNKNOWN, 'status' => null];
    }

    /** The cache key for a company, after the host's template or callback. */
    public function cacheKeyFor(string $company): string
    {
        return $this->cacheKey($company);
    }

    /** Where a person sees and manages what their company has. */
    public function platformsUrl(): string
    {
        return $this->infodot.'/platforms';
    }

    /** `{infodot}/go/<key>?company=<uuid>`: InfoDot decides, mints the single-use handoff and posts it on. */
    public function goUrl(string $key, ?string $company): string
    {
        $url = $this->infodot.'/go/'.rawurlencode($key);

        return $company !== null ? $url.'?company='.rawurlencode($company) : $url;
    }

    // ---------------------------------------------------------------------------------------------------- answers

    /**
     * @return array{ok: bool, personal: bool, rows: list<array{key: string, name: string, entitled: bool}>}
     */
    private function answer(string $company): array
    {
        $key = $this->cacheKey($company);

        try {
            $cached = $this->cache?->get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached) && array_key_exists('ok', $cached)) {
            return $cached + ['personal' => false, 'rows' => []];
        }

        $answer = $this->ask($company);

        try {
            $this->cache?->put($key, $answer, $answer['ok'] ? self::ANSWER_SECONDS : self::FAILURE_SECONDS);
        } catch (Throwable) {
            // An unwritable cache must not take the page down. The next render asks again.
        }

        return $answer;
    }

    /**
     * @return array{ok: bool, personal: bool, rows: list<array{key: string, name: string, entitled: bool}>}
     */
    private function ask(string $company): array
    {
        $secret = (string) $this->secret;

        if ($secret === '') {
            return $this->failed('service secret not set');
        }

        $endpoint = rtrim($this->companiesUrl ?: $this->infodot.'/api/ecosystem/companies', '/').'/'.rawurlencode($company).'/platforms';

        try {
            $response = $this->http->withToken($secret)
                ->acceptJson()
                ->withHeaders(['X-Dot-Platform' => $this->platform])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->get($endpoint, ['platform' => $this->platform]);
        } catch (Throwable $e) {
            return $this->failed('unreachable ('.(new \ReflectionClass($e))->getShortName().')');
        }

        if (! $response->successful()) {
            return $this->failed('status '.$response->status());
        }

        try {
            $body = $response->json();
        } catch (Throwable) {
            $body = null;
        }

        if (! is_array($body) || ! is_array($body['platforms'] ?? null) || ! is_array($body['company'] ?? null)
            || ! is_string($body['company']['uuid'] ?? null) || strtolower($body['company']['uuid']) !== $company) {
            return $this->failed('unreadable answer');
        }

        $rows = [];

        foreach ($body['platforms'] as $row) {
            if (! is_array($row) || ! is_string($row['key'] ?? null) || ! preg_match('/^[a-z0-9_-]{1,40}$/', $row['key'])
                || ! is_string($row['name'] ?? null) || trim($row['name']) === '' || ! is_bool($row['entitled'] ?? null)) {
                continue;
            }

            $rows[] = [
                'key' => $row['key'],
                'name' => mb_substr(trim($row['name']), 0, 60),
                'entitled' => $row['entitled'],
                'plain' => ($row['status'] ?? null) === 'managed_in_platform',
                'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
            ];
        }

        if ($rows === [] && ($body['company']['personal'] ?? false) !== true) {
            return $this->failed('answer lists no platforms');
        }

        return ['ok' => true, 'personal' => ($body['company']['personal'] ?? false) === true, 'rows' => $rows];
    }

    /**
     * @return array{ok: bool, personal: bool, rows: list<array{key: string, name: string, entitled: bool}>}
     */
    private function failed(string $why): array
    {
        // Once per cache window, since the failure is cached for that window. Never the secret, never a person.
        try {
            $this->log?->warning('InfoDot platform list gave no answer; showing the core set via InfoDot.', ['platform' => $this->platform, 'reason' => $why]);
        } catch (Throwable) {
        }

        return ['ok' => false, 'personal' => false, 'rows' => []];
    }

    // ----------------------------------------------------------------------------------------------- view models

    /**
     * @param  list<array{key: string, name: string, entitled: bool, plain?: bool}>  $rows
     */
    private function ready(string $company, array $rows): array
    {
        $tiles = [];
        $more = [];
        $seen = [];

        foreach ($rows as $row) {
            if (in_array($row['key'], self::internal(), true) || isset($seen[$row['key']])) {
                continue;
            }
            $seen[$row['key']] = true;

            $current = $row['key'] === $this->platform;

            if ($row['entitled'] === true || $current) {
                $tiles[] = $this->tile($row['key'], $row['name'], $company, $current, ($row['plain'] ?? false) === true);
            } else {
                $more[] = ['key' => $row['key'], 'name' => $row['name'], 'href' => $this->infodot.'/platforms/'.rawurlencode($row['key'])];
            }
        }

        // This platform missing from the answer is not a reason to hide where the person is.
        if (! isset($seen[$this->platform]) && ! in_array($this->platform, self::internal(), true)) {
            array_unshift($tiles, $this->tile($this->platform, self::nameFor($this->platform), $company, true, false));
        }

        return $this->model(self::STATE_OK, $company, $tiles, $more, null, null);
    }

    private function degraded(?string $company): array
    {
        $tiles = [];

        foreach (self::core() as $key) {
            $tiles[] = $this->tile($key, self::nameFor($key), $company, $key === $this->platform, false);
        }

        // A platform outside the core set still says where the person is: it is marked here too, as in the static renderer.
        if ($this->platform !== '' && ! in_array($this->platform, self::core(), true) && ! in_array($this->platform, self::internal(), true)) {
            $tiles[] = $this->tile($this->platform, self::nameFor($this->platform), $company, true, false);
        }

        return $this->model(self::STATE_DEGRADED, $company, $tiles, [], self::notice(), null);
    }

    /**
     * The degraded-style view model for a host that never asks InfoDot (Dot.Mines, Dot.Charts): the core set as
     * "open via InfoDot" tiles (`{infodot}/go/<key>`, no company, InfoDot decides for the person), the current platform marked
     * "You are here", a visible notice line and no claim that anything is active. Nothing is read, cached or sent.
     *
     * Feed it to `<x-dot.switcher :model="...">`.
     *
     * @param  string  $current  This platform's key (marked as the current tile, also when it is outside the core set).
     * @param  string|null  $notice  The visible label, e.g. "These open through InfoDot". Default: the degraded label,
     *                               "Could not check your platforms just now" (a host that never asks should pass its own).
     * @param  string|null  $detail  The sentence after it. Default: "These open through InfoDot, which checks your access."
     * @param  string|null  $infodotUrl  InfoDot's base URL. Default: config `ecosystem.platforms.infodot.url`, else the origin of
     *                                   `ecosystem.infodot.redeem_url`, else https://infodot.co.za.
     * @return array<string, mixed> The same shape as `for()`, plus `notice_detail`.
     */
    public static function plain(string $current, ?string $notice = null, ?string $detail = null, ?string $infodotUrl = null): array
    {
        if ($infodotUrl === null || $infodotUrl === '') {
            $config = static fn (string $key): string => function_exists('config') ? (string) config($key, '') : '';
            $infodotUrl = $config('ecosystem.platforms.infodot.url') ?: self::origin($config('ecosystem.infodot.redeem_url')) ?: 'https://infodot.co.za';
        }

        $model = (new self($current, $infodotUrl, null, null))->degraded(null);

        if ($notice !== null && trim($notice) !== '') {
            $model['notice'] = rtrim(trim($notice), '.');
        }
        $model['notice_detail'] = $detail !== null && trim($detail) !== '' ? trim($detail) : null;

        return $model;
    }

    private function personal(?string $company): array
    {
        return $this->model(self::STATE_PERSONAL, $company, [], [], null, self::personalMessage());
    }

    /**
     * @return array{key: string, name: string, href: ?string, current: bool, plain: bool, via_infodot: bool, logo: ?string}
     */
    private function tile(string $key, string $name, ?string $company, bool $current, bool $plainFromAnswer): array
    {
        $plain = in_array($key, self::plainKeys(), true) || $plainFromAnswer;
        $home = $key === 'infodot';

        // A tile for the current platform is a marker, not a link. InfoDot's own tile goes to its home, no handoff.
        $href = match (true) {
            $current => null,
            $home => $this->infodot.'/home',
            $plain => $this->goUrl($key, null),
            default => $this->goUrl($key, $company),
        };

        return [
            'key' => $key,
            'name' => $name,
            'href' => $href,
            'current' => $current,
            'plain' => $plain,
            'via_infodot' => ! $plain && ! $home,
            'logo' => PlatformLogos::file($key),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tiles
     * @param  list<array{key: string, name: string, href: string}>  $more
     */
    private function model(string $state, ?string $company, array $tiles, array $more, ?string $notice, ?string $message): array
    {
        $groups = [];

        $data = self::data();

        foreach ($tiles as $tile) {
            $category = $data['primary_category'][$tile['key']] ?? null;
            $meta = $category !== null ? ($data['categories'][$category] ?? null) : null;
            $category = $meta !== null ? $category : null;
            $groups[$category ?? 'more'] ??= ['code' => $meta['code'] ?? 'MORE', 'tone' => $meta['tone'] ?? 'platforms', 'name' => $meta['name'] ?? 'More platforms', 'tiles' => []];
            $groups[$category ?? 'more']['tiles'][] = $tile;
        }

        // InfoDot's own order: the catalogue categories as listed, anything unplaced last.
        $ordered = [];
        foreach ([...array_keys($data['categories']), 'more'] as $category) {
            if (isset($groups[$category])) {
                $ordered[] = $groups[$category];
            }
        }

        return [
            'state' => $state,
            'current' => $this->platform,
            'company' => $company,
            'infodot_url' => $this->infodot,
            'platforms_url' => $this->platformsUrl(),
            'notice' => $notice,
            'notice_detail' => null,
            'message' => $message,
            'count' => count($tiles),
            'groups' => $ordered,
            'more' => $more,
        ];
    }

    // ------------------------------------------------------------------------------------------------- the data

    /**
     * @return array{core: list<string>, plain: list<string>, internal: list<string>, notice: string, personal_message: string,
     *               names: array<string, string>, categories: array<string, array{code: string, tone: string, name: string}>,
     *               primary_category: array<string, string>}
     */
    public static function data(): array
    {
        if (self::$data === null) {
            $json = @file_get_contents(dirname(__DIR__).'/data/categories.json');
            $data = is_string($json) ? json_decode($json, true) : null;
            $data = is_array($data) ? $data : [];

            self::$data = [
                'core' => array_values(array_filter((array) ($data['core'] ?? []), 'is_string')),
                'plain' => array_values(array_filter((array) ($data['plain'] ?? []), 'is_string')),
                'internal' => array_values(array_filter((array) ($data['internal'] ?? []), 'is_string')),
                'notice' => is_string($data['notice'] ?? null) ? $data['notice'] : 'Could not check your platforms just now',
                'personal_message' => is_string($data['personal_message'] ?? null) ? $data['personal_message'] : 'You are in your personal workspace.',
                'names' => (array) ($data['names'] ?? []),
                'categories' => (array) ($data['categories'] ?? []),
                'primary_category' => (array) ($data['primary_category'] ?? []),
            ];
        }

        return self::$data;
    }

    /** The free core every company holds. Used only when InfoDot cannot be asked, and only to route via InfoDot. */
    public static function core(): array
    {
        return self::data()['core'];
    }

    /** Platforms with their own sign-in and no InfoDot receiver: opened by a plain link, nothing is handed over. (Was `plain()` before 0.3.0.) */
    public static function plainKeys(): array
    {
        return self::data()['plain'];
    }

    /** Bridge services people never open. Dropped if an answer ever lists them. */
    public static function internal(): array
    {
        return self::data()['internal'];
    }

    /** The visible "could not check" line of the degraded state. */
    public static function notice(): string
    {
        return self::data()['notice'];
    }

    public static function personalMessage(): string
    {
        return self::data()['personal_message'];
    }

    /** The display name for a platform key (the live answer carries its own; this serves the degraded list). */
    public static function nameFor(string $key): string
    {
        return self::data()['names'][$key] ?? $key;
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    private function cacheKey(string $company): string
    {
        if ($this->cacheKey instanceof \Closure) {
            $key = ($this->cacheKey)($this->platform, $company);

            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        $template = is_string($this->cacheKey) && $this->cacheKey !== '' ? $this->cacheKey : self::DEFAULT_CACHE_KEY;

        return strtr($template, ['{platform}' => $this->platform, '{company}' => $company]);
    }

    private function cleanUuid(?string $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1 ? $value : null;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : '';
    }

    private static function beforeLast(string $subject, string $search): string
    {
        $pos = strrpos($subject, $search);

        return $pos === false ? $subject : substr($subject, 0, $pos);
    }
}
