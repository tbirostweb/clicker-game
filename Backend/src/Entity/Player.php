<?php
namespace App\Entity;

use App\Repository\PlayerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_player_idempotency_key_hash', columns: ['idempotency_key_hash'])]
class Player
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 20)]
    #[Assert\NoSuspiciousCharacters]
    private ?string $name = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $rebirth = null;

    // BIGINT: cumulative money in a clicker quickly exceeds a 32-bit INT.
    #[ORM\Column(type: Types::BIGINT)]
    #[Assert\PositiveOrZero]
    private int|string|null $score = null;

    #[ORM\Column]
    #[Assert\Positive]
    private ?int $timeSeconds = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $activeSeconds = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $trophyCount = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    // SHA-256 of the secret edit token handed out once, at creation, to the
    // browser that created the run. Never serialized. NULL for runs created
    // before ownership tokens existed: those can no longer be edited publicly.
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $editTokenHash = null;

    // SHA-256 of the client's Idempotency-Key, so a retried POST does not
    // create a duplicate row (unique constraint above).
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $idempotencyKeyHash = null;

    // Optimistic locking: concurrent PUTs on the same run cannot silently
    // overwrite each other (Doctrine adds "WHERE version = ?" on UPDATE).
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // --- getters/setters ---
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getRebirth(): ?int
    {
        return $this->rebirth;
    }

    public function setRebirth(int $rebirth): self
    {
        $this->rebirth = $rebirth;
        return $this;
    }

    public function getScore(): ?int
    {
        return null === $this->score ? null : (int) $this->score;
    }

    public function setScore(int $score): self
    {
        $this->score = $score;
        return $this;
    }

    public function getTimeSeconds(): ?int
    {
        return $this->timeSeconds;
    }

    public function setTimeSeconds(int $timeSeconds): self
    {
        $this->timeSeconds = $timeSeconds;
        return $this;
    }

    public function getActiveSeconds(): ?int
    {
        return $this->activeSeconds;
    }

    public function setActiveSeconds(int $activeSeconds): self
    {
        $this->activeSeconds = $activeSeconds;
        return $this;
    }

    public function getTrophyCount(): ?int
    {
        return $this->trophyCount;
    }

    public function setTrophyCount(int $trophyCount): self
    {
        $this->trophyCount = $trophyCount;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditTokenHash(): ?string
    {
        return $this->editTokenHash;
    }

    public function setEditTokenHash(?string $editTokenHash): self
    {
        $this->editTokenHash = $editTokenHash;
        return $this;
    }

    public function getIdempotencyKeyHash(): ?string
    {
        return $this->idempotencyKeyHash;
    }

    public function setIdempotencyKeyHash(?string $idempotencyKeyHash): self
    {
        $this->idempotencyKeyHash = $idempotencyKeyHash;
        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
