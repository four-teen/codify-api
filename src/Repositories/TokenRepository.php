<?php
declare(strict_types=1);

namespace Codify\Repositories;

use PDO;

final class TokenRepository
{
    /** @var PDO */
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function issue(int $userId, int $minutes): string
    {
        $plain = bin2hex(random_bytes(40));
        $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));
        $statement = $this->db->prepare("INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, abilities, expires_at, created_at, updated_at) VALUES ('Codify\\\\User', :user_id, 'codify-web', :token, '[\"*\"]', :expires_at, NOW(), NOW())");
        $statement->execute(['user_id' => $userId, 'token' => hash('sha256', $plain), 'expires_at' => $expiresAt]);
        return (string) $this->db->lastInsertId() . '|' . $plain;
    }

    public function resolve(string $bearer): ?array
    {
        [$id, $plain] = array_pad(explode('|', $bearer, 2), 2, '');
        if (!ctype_digit($id) || $plain === '') return null;
        $statement = $this->db->prepare('SELECT * FROM personal_access_tokens WHERE id = :id LIMIT 1');
        $statement->execute(['id' => (int) $id]);
        $token = $statement->fetch();
        if (!$token || !hash_equals((string) $token['token'], hash('sha256', $plain))) return null;
        if ($token['expires_at'] !== null && strtotime((string) $token['expires_at']) <= time()) {
            $this->delete((int) $token['id']);
            return null;
        }
        $this->db->prepare('UPDATE personal_access_tokens SET last_used_at = NOW(), updated_at = NOW() WHERE id = :id')->execute(['id' => (int) $token['id']]);
        $token['id'] = (int) $token['id'];
        $token['tokenable_id'] = (int) $token['tokenable_id'];
        return $token;
    }

    public function delete(int $tokenId): void { $this->db->prepare('DELETE FROM personal_access_tokens WHERE id = :id')->execute(['id' => $tokenId]); }
    public function revokeAll(int $userId): void { $this->db->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id = :id')->execute(['id' => $userId]); }
    public function activeCount(int $userId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM personal_access_tokens WHERE tokenable_id = :id AND (expires_at IS NULL OR expires_at > NOW())');
        $statement->execute(['id' => $userId]);
        return (int) $statement->fetchColumn();
    }
    public function revokeOthers(int $userId, int $currentTokenId): void { $this->db->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id = :user AND id <> :token')->execute(['user' => $userId, 'token' => $currentTokenId]); }
    public function revokeIds(int $userId, array $tokenIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tokenIds), static function (int $id): bool { return $id > 0; })));
        if ($ids === []) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id = ? AND id IN (' . $placeholders . ')');
        $statement->execute(array_merge([$userId], $ids));
    }
}
