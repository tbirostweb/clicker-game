<?php
// src/Controller/PlayerController.php
namespace App\Controller;

use App\Entity\Player;
use App\Repository\PlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PlayerController extends AbstractController
{
    private const MAX_LIMIT = 100;
    // Sanity bounds to reject obviously bogus submissions (not real anti-cheat).
    private const MAX_REBIRTH = 1_000_000;
    private const MAX_SCORE = PHP_INT_MAX;
    private const MAX_TIME_SECONDS = 24 * 60 * 60;
    private const MAX_TROPHY_COUNT = 1_000;
    private const ALLOWED_SORTS = ['active', 'rebirths', 'score', 'trophies'];

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
        ValidatorInterface $validator,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $player = new Player();
        $player->setName(trim((string) ($payload['name'] ?? '')));

        $error = $this->fillFromPayload($player, $payload, $validator);
        if ($error) {
            return $error;
        }

        $entityManager->persist($player);
        $entityManager->flush();

        return $this->json($this->serialize($player), 201);
    }

    // A player's browser remembers the id it got back from the initial POST
    // and calls this to refresh the SAME run in place (more rebirths, more
    // playtime, more trophies) instead of leaving a stale first-rebirth row
    // behind and piling up duplicates every time they resubmit.
    #[Route('/api/leaderboard/{id}', name: 'api_leaderboard_put', methods: ['PUT'])]
    public function updateRun(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        PlayerRepository $playerRepository,
        ValidatorInterface $validator,
    ): JsonResponse {
        $player = $playerRepository->find($id);
        if (!$player) {
            return $this->json(['error' => 'Run not found'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        if (array_key_exists('name', $payload)) {
            $player->setName(trim((string) $payload['name']));
        }

        $error = $this->fillFromPayload($player, $payload, $validator);
        if ($error) {
            return $error;
        }

        $entityManager->flush();

        return $this->json($this->serialize($player));
    }

    // Admin-only cleanup (e.g. removing test/spam entries). Requires the
    // X-Admin-Token header to match the ADMIN_TOKEN env var -- there's no
    // user accounts/auth system in this app, so this is the whole gate.
    // Refuses everything if ADMIN_TOKEN isn't configured, rather than
    // silently allowing open deletes.
    #[Route('/api/leaderboard/{id}', name: 'api_leaderboard_delete', methods: ['DELETE'])]
    public function deleteRun(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        PlayerRepository $playerRepository,
    ): JsonResponse {
        $expectedToken = $_ENV['ADMIN_TOKEN'] ?? null;
        $providedToken = $request->headers->get('X-Admin-Token');

        if (!$expectedToken || !$providedToken || !hash_equals($expectedToken, $providedToken)) {
            return $this->json(['error' => 'Unauthorized'], 403);
        }

        $player = $playerRepository->find($id);
        if (!$player) {
            return $this->json(['error' => 'Run not found'], 404);
        }

        $entityManager->remove($player);
        $entityManager->flush();

        return $this->json(null, 204);
    }

    private function fillFromPayload(Player $player, array $payload, ValidatorInterface $validator): ?JsonResponse
    {
        $rebirths = (int) ($payload['rebirths'] ?? -1);
        $score = (int) ($payload['score'] ?? -1);
        $timeSeconds = (int) ($payload['timeSeconds'] ?? -1);
        $activeSeconds = (int) ($payload['activeSeconds'] ?? 0);
        $trophies = (int) ($payload['trophies'] ?? 0);

        if (
            $rebirths > self::MAX_REBIRTH
            || $score > self::MAX_SCORE
            || $timeSeconds > self::MAX_TIME_SECONDS
            || $activeSeconds > self::MAX_TIME_SECONDS
            || $trophies > self::MAX_TROPHY_COUNT
        ) {
            return $this->json(['error' => 'Submitted values are out of allowed range'], 422);
        }

        // Active (window-focused) time can never exceed total elapsed time.
        $activeSeconds = min($activeSeconds, max($timeSeconds, 0));

        $player->setRebirth($rebirths);
        $player->setScore($score);
        $player->setTimeSeconds($timeSeconds);
        $player->setActiveSeconds($activeSeconds);
        $player->setTrophyCount($trophies);

        $errors = $validator->validate($player);
        if (count($errors) > 0) {
            $messages = array_map(
                static fn ($error) => $error->getPropertyPath() . ': ' . $error->getMessage(),
                iterator_to_array($errors)
            );

            return $this->json(['error' => 'Validation failed', 'details' => $messages], 422);
        }

        return null;
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
        ];
    }
}
