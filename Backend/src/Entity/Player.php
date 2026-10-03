<?php
namespace App\Entity;

use App\Repository\PlayerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
class Player
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 20)]
    private ?string $name = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $rebirth = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $score = null;

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
        return $this->score;
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
}
