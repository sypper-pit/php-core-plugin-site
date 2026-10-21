<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Viewer
{
    public ?string $userId = null;
    public string $role = 'public'; // public, user, admin
    public ?string $csrfSecret = null;

    public function __construct(?string $userId = null, string $role = 'public', ?string $csrfSecret = null)
    {
        $this->userId = $userId;
        $this->role = $role;
        $this->csrfSecret = $csrfSecret;
    }

    public function isAuthenticated(): bool
    {
        return $this->role === 'user' || $this->role === 'admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
