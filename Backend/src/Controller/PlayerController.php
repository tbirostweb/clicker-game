<?php
// src/Controller/PlayerController.php
namespace App\Controller;

use App\Entity\Player;
use App\Repository\PlayerRepository;
use App\Security\SimpleRateLimiter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Public leaderboard API.
 *
 * Scores are self-declared by the browser and NOT verified server-side:
 * the bounds below only reject obviously bogus submissions, they are not an
 * anti-cheat. The UI labels the leaderboard as unverified accordingly.
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
    private const MAX_TROPHY_COUNT = 1_000;
    private const ALLOWED_SORTS = ['active', 'rebirths', 'score', 'trophies'];
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9-]{16,128}$/';
    private const EDIT_TOKEN_PATTERN = '/^[a-f0-9]{64}$/';
    private const ADMIN_TOKEN_MIN_LENGTH = 32;

    // [limit, window seconds] per client IP.
    private const RATE_POST = [10, 600];
    private const RATE_PUT = [30, 600];
    private const RATE_OWNER_DELETE = [10, 600];
    private const RATE_ADMIN_FAILURE = [5, 900];

    public function __construct(
        private readonly SimpleRateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
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

        $runs = $playerRepository->findTopRuns($limit, $sort);

        return $this->json(array_map([$this, 'serialize'], $runs));
    }

    #[Route('/api/leaderboard', name: 'api_leaderboard_post', methods: ['POST'])]
    public function submitRun(
        Request $request,
        EntityManagerInterface $entityManager,
        PlayerRepository $playerRepository,
        ValidatorInterface $validator,
    ): JsonResponse {
        if (!$this->rateLimiter->consume('post|' . $request->getClientIp(), ...self::RATE_POST)) {
            return $this->tooManyRequests(self::RATE_POST[1]);
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
            // return the existing run instead of creating a duplicate. The
            // caller proved knowledge of the secret key, so it gets a freshly
            // rotated edit token (the previous one stops working).
            $existing = $playerRepository->findOneBy(['idempotencyKeyHash' => $idempotencyKeyHash]);
            if ($existing) {
                $editToken = $this->issueEditToken($existing);
                $entityManager->flush();

                return $this->noStore($this->json($this->serialize($existing) + ['editToken' => $editToken], 200));
            }
        }

        if (!is_string($payload['name'] ?? null)) {
            return $this->validationError(['name: This value should be of type string.']);
        }

        $player = new Player();
        $player->setName(trim($payload['name']));
        $player->setIdempotencyKeyHash($idempotencyKeyHash);

        $error = $this->fillFromPayload($player, $payload, $validator);
        if ($error) {
            return $error;
        }

        $editToken = $this->issueEditToken($player);

        try {
            $entityManager->persist($player);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Same Idempotency-Key submitted concurrently: the other request won.
            return $this->noStore($this->json(['error' => 'Duplicate request, retry later'], 409));
        }

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
        if (!$this->rateLimiter->consume('put|' . $request->getClientIp(), ...self::RATE_PUT)) {
            return $this->tooManyRequests(self::RATE_PUT[1]);
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
            $player->setName(trim($payload['name']));
        }

        $previousTime = $player->getTimeSeconds() ?? 0;
        $previousRebirths = $player->getRebirth() ?? 0;

        $error = $this->fillFromPayload($player, $payload, $validator);
        if ($error) {
            return $error;
        }

        // A run only moves forward: an older, late-arriving update must not
        // overwrite a newer one.
        if ($player->getTimeSeconds() < $previousTime || $player->getRebirth() < $previousRebirths) {
            return $this->noStore($this->json(['error' => 'Stale update'], 409));
        }

        try {
            $entityManager->flush();
        } catch (OptimisticLockException) {
            return $this->noStore($this->json(['error' => 'Concurrent update, retry'], 409));
        }

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
            if (!$this->rateLimiter->consume('owner-delete|' . $clientIp, ...self::RATE_OWNER_DELETE)) {
                return $this->tooManyRequests(self::RATE_OWNER_DELETE[1]);
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
            if ($this->rateLimiter->isLimited('admin-fail|' . $clientIp, ...self::RATE_ADMIN_FAILURE)) {
                return $this->tooManyRequests(self::RATE_ADMIN_FAILURE[1]);
            }
            if (!$this->isValidAdminToken($request->headers->get('X-Admin-Token'))) {
                $this->logger->warning('Leaderboard admin delete refused', ['ip' => $clientIp, 'run_id' => $id]);
                $this->rateLimiter->consume('admin-fail|' . $clientIp, ...self::RATE_ADMIN_FAILURE);

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

        return $this->noStore($this->json(null, 204));
    }

    private function isValidAdminToken(?string $providedToken): bool
    {
        $expectedToken = $_SERVER['ADMIN_TOKEN'] ?? $_ENV['ADMIN_TOKEN'] ?? null;

        return is_string($expectedToken)
            && strlen($expectedToken) >= self::ADMIN_TOKEN_MIN_LENGTH
            && is_string($providedToken)
            && '' !== $providedToken
            && hash_equals($expectedToken, $providedToken);
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

        // Active (window-focused) time can never exceed total elapsed time.
        $activeSeconds = min($values['activeSeconds'], $values['timeSeconds']);

        $player->setRebirth($values['rebirths']);
        $player->setScore($values['score']);
        $player->setTimeSeconds($values['timeSeconds']);
        $player->setActiveSeconds($activeSeconds);
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
