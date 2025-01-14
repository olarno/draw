<?php

namespace Draw\Bundle\UserBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Draw\Component\Core\DateTimeUtils;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'draw_user__user_lock')]
#[ORM\HasLifecycleCallbacks]
class UserLock implements \Stringable
{
    final public const REASON_PASSWORD_EXPIRED = 'password-expired';

    final public const REASON_MANUAL_LOCK = 'manual-lock';

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'guid')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: LockableUserInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LockableUserInterface $user = null;

    #[ORM\Column(name: 'reason', type: 'string', length: 255, nullable: false)]
    private ?string $reason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'lock_on', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockOn = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'unlock_until', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $unlockUntil = null;

    public function __construct(?string $reason = null)
    {
        if ($reason) {
            $this->setReason($reason);
        }
    }

    #[ORM\PrePersist]
    public function getId(): string
    {
        if (null === $this->id) {
            $this->id = Uuid::uuid6()->toString();
        }

        return $this->id;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getUser(): ?LockableUserInterface
    {
        return $this->user;
    }

    public function setUser(?LockableUserInterface $user): self
    {
        $this->user = $user;

        if ($this->reason && null !== $this->user) {
            $user->lock($this);
        }

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        if ($this->reason && null !== $this->user) {
            $this->user->lock($this);
        }

        return $this;
    }

    #[ORM\PrePersist]
    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt ?: $this->createdAt = new \DateTimeImmutable();
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): self
    {
        if (!DateTimeUtils::isSameTimestamp($this->createdAt, $createdAt)) {
            $this->createdAt = DateTimeUtils::toDatetimeImmutable($createdAt);
        }

        return $this;
    }

    public function getLockOn(): ?\DateTimeInterface
    {
        return $this->lockOn;
    }

    public function setLockOn(?\DateTimeInterface $lockOn): self
    {
        if (!DateTimeUtils::isSameTimestamp($this->lockOn, $lockOn)) {
            $this->lockOn = DateTimeUtils::toDatetimeImmutable($lockOn);
        }

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): self
    {
        if (!DateTimeUtils::isSameTimestamp($this->expiresAt, $expiresAt)) {
            $this->expiresAt = DateTimeUtils::toDatetimeImmutable($expiresAt);
        }

        return $this;
    }

    public function getUnlockUntil(): ?\DateTimeInterface
    {
        return $this->unlockUntil;
    }

    public function setUnlockUntil(?\DateTimeInterface $unlockUntil): self
    {
        if (!DateTimeUtils::isSameTimestamp($this->unlockUntil, $unlockUntil)) {
            $this->unlockUntil = DateTimeUtils::toDatetimeImmutable($unlockUntil);
        }

        return $this;
    }

    public function isActive(): bool
    {
        switch (true) {
            default:
            case $this->lockOn && $this->lockOn->getTimestamp() > time():
            case $this->unlockUntil && $this->unlockUntil->getTimestamp() > time():
                return false;
            case null === $this->expiresAt:
            case $this->expiresAt->getTimestamp() > time():
                return true;
        }
    }

    public function isSame(self $userLock): bool
    {
        return match (true) {
            $userLock->getReason() !== $this->getReason(), !DateTimeUtils::isSameTimestamp($userLock->getLockOn(), $this->getLockOn()), !DateTimeUtils::isSameTimestamp($userLock->getExpiresAt(), $this->getExpiresAt()) => false,
            default => true,
        };
    }

    public function copyInto(self $userLock): void
    {
        if ($userLock->getReason() !== $this->getReason()) {
            throw new \LogicException(sprintf('User lock for reason [%s] cannot be copied into user lock for reason [%s]', $this->getReason(), $userLock->getReason()));
        }

        $userLock->setLockOn($this->getLockOn());
        $userLock->setUnlockUntil($this->getUnlockUntil());
        $userLock->setExpiresAt($this->getExpiresAt());
    }

    public function __toString(): string
    {
        return sprintf(
            '%s -> %s -> %s',
            $this->getUser(),
            $this->getReason(),
            $this->isActive() ? 'Active' : 'Inactive',
        );
    }
}
