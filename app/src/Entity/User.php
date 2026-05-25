<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_login_pass', columns: ['login', 'pass'])]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 8)]
    #[ORM\Column(length: 8)]
    private ?string $login = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 8)]
    #[ORM\Column(length: 8)]
    private ?string $phone = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 8)]
    #[ORM\Column(length: 8)]
    private ?string $pass = null;

    #[ORM\Column(length: 10)]
    private string $role = 'user';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLogin(): ?string
    {
        return $this->login;
    }
    public function setLogin(string $login): static
    {
        $this->login = $login; return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }
    public function setPhone(string $phone): static
    {
        $this->phone = $phone; return $this;
    }

    public function getPass(): ?string
    {
        return $this->pass;
    }
    public function setPass(string $pass): static
    {
        $this->pass = $pass; return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }
    public function setRole(string $role): static
    {
        $this->role = $role; return $this;
    }

    // --- UserInterface methods ---

    public function getRoles(): array
    {
        return [$this->role === 'root' ? 'ROLE_ROOT' : 'ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->pass;
    }

    public function getUserIdentifier(): string
    {
        return $this->login;
    }

    public function eraseCredentials(): void {}
}
