<?php
// src/Controller/PlayerController.php
namespace App\Controller;

use App\Entity\Player;
use App\Repository\PlayerRepository;
use App\Security\LeaderboardRateLimiter;
use App\Security\NameModeration;
use App\Security\RunPlausibility;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Public leaderboard API.
 *
 * Scores are self-declared by the browser and NOT verified server-side:
 * the bounds below and App\Security\RunPlausibility only reject values the
 * real game cannot produce (wide margins), they are not an anti-cheat. The UI
 * labels the leaderboard as unverified accordingly.
 *
 * Ownership: POST returns a one-time secret `editToken` (only its SHA-256 is
 * stored). PUT and owner DELETE require it in the X-Edit-Token header, so a
 * public run id (visible in GET) is not enough to modify someone else's run.
 */
class PlayerController extends AbstractController
{
    private const MAX_LIMIT = 100;
    private const MAX_BODY_BYTES = 2048;
    // Sanity bounds to reject obviously bogus submissions (not real anti-cheat).
    private const MAX_REBIRTH = 1_000_000;
    // Largest integer the browser can represent exactly (Number.MAX_SAFE_INTEGER).
    private const MAX_SCORE = 9_007_199_254_740_991;
    // Cumulative playtime persists across sessions: allow up to one year.
    private const MAX_TIME_SECONDS = 365 * 24 * 60 * 60;
    // Real number of achievements (src/data/achievements.js).
    private const MAX_TROPHY_COUNT = RunPlausibility::GAME_ACHIEVEMENT_COUNT;
    private const ALLOWED_SORTS = ['active', 'rebirths', 'score', 'trophies'];
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9-]{16,128}$/';
    private const EDIT_TOKEN_PATTERN = '/^[a-f0-9]{64}$/';
    private const ADMIN_TOKEN_MIN_LENGTH = 32;
    // Estimated entropy required from ADMIN_TOKEN (hex/base64 random string).
    private const ADMIN_TOKEN_MIN_ENTROPY_BITS = 96;
    // A replayed Idempotency-Key is honoured for 24 h.
    private const IDEMPOTENCY_TTL_SECONDS = 86_400;
    private const LEADERBOARD_CACHE_TAG = 'leaderboard';
    private const LEADERBOARD_CACHE_SECONDS = 15;

    public function __construct(
        private readonly LeaderboardRateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
        private readonly TagAwareCacheInterface $leaderboardCache,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return $this->noStore($this->json(['status' => 'unavailable'], 503));
        }

        return $this->noStore($this->json(['status' => 'ok']));
    }

    #[Route('/api/leaderboard', name: 'api_leaderboard_get', methods: ['GET'])]
    public function getLeaderboard(Request $request, PlayerRepository $playerRepository): JsonResponse
    {
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 20)));
        $sort = $request->query->get('sort', 'active');
        if (!in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = 'active';
        }

        if (null !== $retryAfter = $this->rateLimiter->get($request)) {
            return $this->tooManyRequests($retryAfter);
        }

        // Short server-side cache (invalidated on every write), so bursts of
        // GET do not each hit the database.
        $runs = $this->leaderboardCache->get(
            sprintf('top_%s_%d', $sort, $limit),
            function (ItemInterface $item) use ($playerRepository, $limit, $sort): array {
                $item->expiresAfter(self::LEADERBOARD_CACHE_SECONDS);
                $item->tag(self::LEADERBOARD_CACHE_TAG);

                return array_map([$this, 'serialize'], $playerRepository->findTopRuns($limit, $sort));
            }
        );

        return $this->json($runs);
    }

    #[Route('/api/leaderboard', name: 'api_leaderboard_post', methods: ['POST'])]
    public function submitRun(
        Request $request,
        EntityManagerInterface $entityManager,
        PlayerRepository $playerRepository,
        ValidatorInterface $validator,
    ): JsonResponse {
        if (null !== $retryAfter = $this->rateLimiter->post($request)) {
            return $this->tooManyRequests($retryAfter);
        }

        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $idempotencyKeyHash = null;
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if (null !== $idempotencyKey) {
            if (!preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey)) {
                return $this->noStore($this->json(['error' => 'Invalid Idempotency-Key header'], 400));
            }
            $idempotencyKeyHash = hash('sha256', $idempotencyKey);

            // Retried POST (e.g. network error after the server committed):
            // within 24 h, return the run and the edit token already issued
            // (decrypted with the key only the caller knows), without
            // rotating it. After 24 h the key is forgotten and a new run is
            // created: no token is ever handed out again for the old run.
            $existing = $playerRepository->findOneBy(['idempotencyKeyHash' => $idempotencyKeyHash]);
            if ($existing) {
                $keyCreatedAt = $existing->getIdempotencyCreatedAt() ?? $existing->getCreatedAt();
                if ($keyCreatedAt > new \DateTimeImmutable(sprintf('-%d seconds', self::IDEMPOTENCY_TTL_SECONDS))) {
                    $editToken = $this->openTokenBox($existing->getIdempotencyTokenBox(), $idempotencyKey);
                    if (null === $editToken) {
                        // Run created before tokens were kept for replays:
                        // previous behaviour (rotate the token).
                        $editToken = $this->issueEditToken($existing);
                        $existing->setIdempotencyTokenBox($this->sealTokenBox($editToken, $idempotencyKey));
                        $entityManager->flush();
                    }

                    return $this->noStore($this->json($this->serialize($existing) + ['editToken' => $editToken], 200));
                }

                $existing->setIdempotencyKeyHash(null)->setIdempotencyTokenBox(null)->setIdempotencyCreatedAt(null);
                $entityManager->flush();
            }
        }

        if (!is_string($payload['name'] ?? null)) {
            return $this->validationError(['name: This value should be of type string.']);
        }

        $player = new Player();
        $player->setName($payload['name']);
        if (!NameModeration::isAllowed($player->getName())) {
            return $this->validationError(['name: This name is not allowed.']);
        }
        $player->setIdempotencyKeyHash($idempotencyKeyHash);

        $error = $this->fillFromPayload($player, $payload, $validator)
            ?? $this->checkPlausibility($player);
        if ($error) {
            return $error;
        }

        $editToken = $this->issueEditToken($player);
        if (null !== $idempotencyKey) {
            $player->setIdempotencyCreatedAt(new \DateTimeImmutable());
            $player->setIdempotencyTokenBox($this->sealTokenBox($editToken, $idempotencyKey));
        }

        try {
            $entityManager->persist($player);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Same Idempotency-Key submitted concurrently: the other request won.
            return $this->noStore($this->json(['error' => 'Duplicate request, retry later'], 409));
        }
        $this->leaderboardCache->invalidateTags([self::LEADERBOARD_CACHE_TAG]);

        return $this->noStore($this->json($this->serialize($player) + ['editToken' => $editToken], 201));
    }

    // A player's browser remembers the id AND the secret edit token it got
    // back from the initial POST, and calls this to refresh the SAME run in
    // place (more rebirths, more playtime, more trophies) instead of piling up
    // duplicates every time they resubmit.
    #[Route('/api/leaderboard/{id}', name: 'api_leaderboard_put', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateRun(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        PlayerRepository $playerRepository,
        ValidatorInterface $validator,
    ): JsonResponse {
        if (null !== $retryAfter = $this->rateLimiter->put($request)) {
            return $this->tooManyRequests($retryAfter);
        }

        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $player = $playerRepository->find($id);
        if (!$player) {
            return $this->noStore($this->json(['error' => 'Run not found'], 404));
        }

        $ownership = $this->checkOwnership($player, $request);
        if ($ownership) {
            return $ownership;
        }

        if (array_key_exists('version', $payload)
            && (!is_int($payload['version']) || $payload['version'] !== $player->getVersion())) {
            return $this->noStore($this->json(['error' => 'Stale update', 'version' => $player->getVersion()], 409));
        }

        if (array_key_exists('name', $payload)) {
            if (!is_string($payload['name'])) {
                return $this->validationError(['name: This value should be of type string.']);
            }
            $previousName = $player->getName();
            $player->setName($payload['name']);
            // Only a changed name is checked: an existing run keeps updating.
            if ($player->getName() !== $previousName && !NameModeration::isAllowed($player->getName())) {
                return $this->validationError(['name: This name is not allowed.']);
            }
        }

        $previous = [
            'score' => $player->getScore() ?? 0,
            'rebirths' => $player->getRebirth() ?? 0,
            'timeSeconds' => $player->getTimeSeconds() ?? 0,
        ];
        $lastUpdate = $player->getUpdatedAt();
        $now = new \DateTimeImmutable();

        $error = $this->fillFromPayload($player, $payload, $validator);
        if ($error) {
            return $error;
        }

        // A run only moves forward: an older, late-arriving update must not
        // overwrite a newer one (time, rebirths and cumulative score).
        if ($player->getTimeSeconds() < $previous['timeSeconds']
            || $player->getRebirth() < $previous['rebirths']
            || $player->getScore() < $previous['score']) {
            return $this->noStore($this->json(['error' => 'Stale update'], 409));
        }

        $wallClockSeconds = null === $lastUpdate ? null : $now->getTimestamp() - $lastUpdate->getTimestamp();
        $error = $this->checkPlausibility($player, $previous, $wallClockSeconds);
        if ($error) {
            return $error;
        }
        $player->setUpdatedAt($now);

        try {
            $entityManager->flush();
        } catch (OptimisticLockException) {
            return $this->noStore($this->json(['error' => 'Concurrent update, retry'], 409));
        }
        $this->leaderboardCache->invalidateTags([self::LEADERBOARD_CACHE_TAG]);

        return $this->noStore($this->json($this->serialize($player)));
    }

    // Two ways to delete a run:
    //  - its owner, with the X-Edit-Token received at creation (right to
    //    erasure / "withdraw my score" from the game settings);
    //  - an administrator, with X-Admin-Token matching the ADMIN_TOKEN env var
    //    (e.g. removing test/spam entries). Fails closed if ADMIN_TOKEN is not
    //    configured or too short; failed attempts are rate limited and logged
    //    (never the token itself).
    #[Route('/api/leaderboard/{id}', name: 'api_leaderboard_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteRun(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        PlayerRepository $playerRepository,
    ): JsonResponse {
        $clientIp = (string) $request->getClientIp();

        if ($request->headers->has('X-Edit-Token')) {
            if (null !== $retryAfter = $this->rateLimiter->ownerDelete($request)) {
                return $this->tooManyRequests($retryAfter);
            }
            $player = $playerRepository->find($id);
            if (!$player) {
                return $this->noStore($this->json(['error' => 'Run not found'], 404));
            }
            $ownership = $this->checkOwnership($player, $request);
            if ($ownership) {
                return $ownership;
            }
        } else {
            // Lockout is checked BEFORE validating the token, so a brute-force
            // client cannot keep testing guesses once it is rate limited.
            if (null !== $retryAfter = $this->rateLimiter->adminLockedOut($request)) {
                return $this->tooManyRequests($retryAfter);
            }
            if (!$this->isValidAdminToken($request->headers->get('X-Admin-Token'))) {
                $this->logger->warning('Leaderboard admin delete refused', ['ip' => $clientIp, 'run_id' => $id]);
                $this->rateLimiter->adminFailure($request);

                return $this->noStore($this->json(['error' => 'Unauthorized'], 403));
            }
            $player = $playerRepository->find($id);
            if (!$player) {
                return $this->noStore($this->json(['error' => 'Run not found'], 404));
            }
            $this->logger->notice('Leaderboard run deleted by admin', ['run_id' => $id]);
        }

        $entityManager->remove($player);
        $entityManager->flush();
        $this->leaderboardCache->invalidateTags([self::LEADERBOARD_CACHE_TAG]);

        return $this->noStore($this->json(null, 204));
    }

    private function isValidAdminToken(?string $providedToken): bool
    {
        $expectedToken = $_SERVER['ADMIN_TOKEN'] ?? $_ENV['ADMIN_TOKEN'] ?? null;

        return is_string($expectedToken)
            && self::adminTokenIsStrong($expectedToken)
            && is_string($providedToken)
            && '' !== $providedToken
            && hash_equals($expectedToken, $providedToken);
    }

    /**
     * ADMIN_TOKEN must look like a random secret: >= 32 hex or base64(url)
     * characters with enough estimated entropy (e.g. `openssl rand -hex 32`).
     * A weak value disables admin deletion (fail closed).
     */
    public static function adminTokenIsStrong(string $token): bool
    {
        $length = strlen($token);
        if ($length < self::ADMIN_TOKEN_MIN_LENGTH
            || !preg_match('#^(?:[A-Fa-f0-9]+|[A-Za-z0-9+/_-]+={0,2})$#', $token)) {
            return false;
        }

        // Shannon estimate over the observed characters.
        $entropy = 0.0;
        foreach (count_chars($token, 1) as $count) {
            $p = $count / $length;
            $entropy -= $p * log($p, 2);
        }

        return $entropy * $length >= self::ADMIN_TOKEN_MIN_ENTROPY_BITS;
    }

    private function checkPlausibility(Player $player, ?array $previous = null, ?int $wallClockSeconds = null): ?JsonResponse
    {
        $reason = RunPlausibility::check([
            'score' => $player->getScore(),
            'rebirths' => $player->getRebirth(),
            'timeSeconds' => $player->getTimeSeconds(),
            'activeSeconds' => $player->getActiveSeconds(),
            'trophies' => $player->getTrophyCount(),
        ], $previous, $wallClockSeconds);
        if (null === $reason) {
            return null;
        }
        $this->logger->info('Leaderboard run refused as implausible', ['reason' => $reason, 'run_id' => $player->getId()]);

        return $this->noStore($this->json(['error' => 'Implausible run values'], 422));
    }

    private static function tokenBoxKey(string $idempotencyKey): string
    {
        // Distinct from the stored SHA-256 of the key.
        return hash_hmac('sha256', 'clicker-edit-token-box', $idempotencyKey, true);
    }

    private function sealTokenBox(string $editToken, string $idempotencyKey): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($editToken, $nonce, self::tokenBoxKey($idempotencyKey)));
    }

    private function openTokenBox(?string $box, string $idempotencyKey): ?string
    {
        $raw = null === $box ? false : base64_decode($box, true);
        if (false === $raw || strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $token = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::tokenBoxKey($idempotencyKey)
        );

        return is_string($token) && preg_match(self::EDIT_TOKEN_PATTERN, $token) ? $token : null;
    }

    private function checkOwnership(Player $player, Request $request): ?JsonResponse
    {
        $token = $request->headers->get('X-Edit-Token');
        if (null === $token || '' === $token) {
            return $this->noStore($this->json(['error' => 'Missing edit token'], 401));
        }

        $expectedHash = $player->getEditTokenHash();
        if (null === $expectedHash
            || !preg_match(self::EDIT_TOKEN_PATTERN, $token)
            || !hash_equals($expectedHash, hash('sha256', $token))) {
            return $this->noStore($this->json(['error' => 'Forbidden'], 403));
        }

        return null;
    }

    private function issueEditToken(Player $player): string
    {
        $token = bin2hex(random_bytes(32));
        $player->setEditTokenHash(hash('sha256', $token));

        return $token;
    }

    private function decodeJsonBody(Request $request): array|JsonResponse
    {
        $contentLength = (int) $request->headers->get('Content-Length', '0');
        $content = $request->getContent();
        if ($contentLength > self::MAX_BODY_BYTES || strlen($content) > self::MAX_BODY_BYTES) {
            return $this->noStore($this->json(['error' => 'Payload too large'], 413));
        }

        if (!str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            return $this->noStore($this->json(['error' => 'Content-Type must be application/json'], 415));
        }

        try {
            $payload = json_decode($content, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->noStore($this->json(['error' => 'Invalid JSON body'], 400));
        }

        if (!is_array($payload) || array_is_list($payload) && [] !== $payload) {
            return $this->noStore($this->json(['error' => 'Invalid JSON body'], 400));
        }

        return $payload;
    }

    private function fillFromPayload(Player $player, array $payload, ValidatorInterface $validator): ?JsonResponse
    {
        $fields = [
            'rebirths' => [true, self::MAX_REBIRTH],
            'score' => [true, self::MAX_SCORE],
            'timeSeconds' => [true, self::MAX_TIME_SECONDS],
            'activeSeconds' => [false, self::MAX_TIME_SECONDS],
            'trophies' => [false, self::MAX_TROPHY_COUNT],
        ];

        $values = [];
        $typeErrors = [];
        foreach ($fields as $field => [$required, $max]) {
            if (!array_key_exists($field, $payload)) {
                if ($required) {
                    $typeErrors[] = $field . ': This value is required.';
                }
                $values[$field] = 0;
                continue;
            }
            if (!is_int($payload[$field])) {
                $typeErrors[] = $field . ': This value should be an integer.';
                continue;
            }
            if ($payload[$field] < 0 || $payload[$field] > $max) {
                return $this->noStore($this->json(['error' => 'Submitted values are out of allowed range'], 422));
            }
            $values[$field] = $payload[$field];
        }

        if ($typeErrors) {
            return $this->validationError($typeErrors);
        }

        // activeSeconds <= timeSeconds is enforced by checkPlausibility (422).
        $player->setRebirth($values['rebirths']);
        $player->setScore($values['score']);
        $player->setTimeSeconds($values['timeSeconds']);
        $player->setActiveSeconds($values['activeSeconds']);
        $player->setTrophyCount($values['trophies']);

        $errors = $validator->validate($player);
        if (count($errors) > 0) {
            $messages = array_map(
                static fn ($error) => $error->getPropertyPath() . ': ' . $error->getMessage(),
                iterator_to_array($errors)
            );

            return $this->validationError($messages);
        }

        return null;
    }

    private function validationError(array $messages): JsonResponse
    {
        return $this->noStore($this->json(['error' => 'Validation failed', 'details' => array_values($messages)], 422));
    }

    private function tooManyRequests(int $retryAfter): JsonResponse
    {
        $response = $this->json(['error' => 'Too many requests'], 429);
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $this->noStore($response);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function serialize(Player $player): array
    {
        return [
            'id' => $player->getId(),
            'name' => $player->getName(),
            'rebirths' => $player->getRebirth(),
            'score' => $player->getScore(),
            'timeSeconds' => $player->getTimeSeconds(),
            'activeSeconds' => $player->getActiveSeconds(),
            'trophies' => $player->getTrophyCount(),
            'createdAt' => $player->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'version' => $player->getVersion(),
        ];
    }
}
