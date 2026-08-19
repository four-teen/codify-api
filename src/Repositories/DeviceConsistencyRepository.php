<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class DeviceConsistencyRepository
{
    /** @var PDO */
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function latestConsent(int $studentId): ?array
    {
        $statement = $this->db->prepare('SELECT policy_version, action, created_at FROM student_device_consents WHERE student_id = :student ORDER BY id DESC LIMIT 1');
        $statement->execute(['student' => $studentId]); $row = $statement->fetch();
        return $row ?: null;
    }

    public function hasCurrentConsent(int $studentId, string $policyVersion): bool
    {
        $consent = $this->latestConsent($studentId);
        return $consent !== null && $consent['action'] === 'granted' && hash_equals($policyVersion, (string) $consent['policy_version']);
    }

    public function consentHistory(int $studentId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->db->prepare('SELECT id, policy_version, action, created_at FROM student_device_consents WHERE student_id = :student ORDER BY id DESC LIMIT ' . $limit);
        $statement->execute(['student' => $studentId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) $row['id'] = (int) $row['id'];
        unset($row);
        return $rows;
    }

    public function recordConsent(int $studentId, string $policyVersion, string $action, string $noticeHash, ?string $networkHash): void
    {
        $statement = $this->db->prepare('INSERT INTO student_device_consents (student_id, policy_version, action, notice_hash, network_hash, created_at) VALUES (:student, :policy, :action, :notice, :network, NOW())');
        $statement->execute(['student' => $studentId, 'policy' => $policyVersion, 'action' => $action, 'notice' => $noticeHash, 'network' => $networkHash]);
    }

    public function deviceCount(int $studentId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM student_devices WHERE student_id = :student');
        $statement->execute(['student' => $studentId]);
        return (int) $statement->fetchColumn();
    }

    public function devices(int $studentId): array
    {
        $sql = 'SELECT d.*, (SELECT COUNT(*) FROM student_device_events e WHERE e.device_id = d.id AND e.event_type = \'session_start\') AS sessions_count, (SELECT e.match_status FROM student_device_events e WHERE e.device_id = d.id ORDER BY e.id DESC LIMIT 1) AS latest_match_status FROM student_devices d WHERE d.student_id = :student ORDER BY FIELD(d.status, \'new\', \'recognized\', \'reported\', \'revoked\'), d.last_seen_at DESC';
        $statement = $this->db->prepare($sql);
        $statement->execute(['student' => $studentId]);
        return array_map([$this, 'devicePayload'], $statement->fetchAll());
    }

    public function recentEvents(int $studentId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT e.id, e.device_id, e.event_type, e.match_status, e.changed_components, e.occurred_at, d.device_label FROM student_device_events e INNER JOIN student_devices d ON d.id = e.device_id WHERE e.student_id = :student ORDER BY e.id DESC LIMIT ' . $limit;
        $statement = $this->db->prepare($sql); $statement->execute(['student' => $studentId]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $row['id'] = (int) $row['id']; $row['device_id'] = (int) $row['device_id'];
            $changed = json_decode((string) ($row['changed_components'] ?? ''), true);
            $row['changed_components'] = is_array($changed) ? $changed : [];
            $rows[] = $row;
        }
        return $rows;
    }

    public function findOwnedByCredential(int $studentId, string $credentialId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM student_devices WHERE student_id = :student AND credential_id = :credential LIMIT 1');
        $statement->execute(['student' => $studentId, 'credential' => $credentialId]); $row = $statement->fetch();
        return $row ?: null;
    }

    public function findAnyByCredential(string $credentialId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM student_devices WHERE credential_id = :credential LIMIT 1');
        $statement->execute(['credential' => $credentialId]); $row = $statement->fetch();
        return $row ?: null;
    }

    public function findOwnedDevice(int $studentId, int $deviceId): array
    {
        $statement = $this->db->prepare('SELECT * FROM student_devices WHERE id = :device AND student_id = :student LIMIT 1');
        $statement->execute(['device' => $deviceId, 'student' => $studentId]); $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Recognized device not found.');
        return $row;
    }

    public function findByFingerprint(int $studentId, string $fingerprintHash): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM student_devices WHERE student_id = :student AND fingerprint_hash = :fingerprint ORDER BY id DESC LIMIT 1');
        $statement->execute(['student' => $studentId, 'fingerprint' => $fingerprintHash]); $row = $statement->fetch();
        return $row ?: null;
    }

    public function createDevice(int $studentId, string $credentialId, string $publicKey, array $fingerprint, string $status): array
    {
        $statement = $this->db->prepare('INSERT INTO student_devices (student_id, credential_id, public_key_jwk, fingerprint_hash, component_hashes, device_label, browser_label, os_label, device_type, status, first_seen_at, last_seen_at, last_verified_at, seen_count, created_at, updated_at) VALUES (:student, :credential, :public_key, :fingerprint, :components, :device_label, :browser_label, :os_label, :device_type, :status, NOW(), NOW(), NULL, 0, NOW(), NOW())');
        $statement->execute([
            'student' => $studentId, 'credential' => $credentialId, 'public_key' => $publicKey,
            'fingerprint' => $fingerprint['fingerprint_hash'], 'components' => json_encode($fingerprint['component_hashes']),
            'device_label' => $fingerprint['device_label'], 'browser_label' => $fingerprint['browser_label'],
            'os_label' => $fingerprint['os_label'], 'device_type' => $fingerprint['device_type'], 'status' => $status,
        ]);
        return $this->findOwnedDevice($studentId, (int) $this->db->lastInsertId());
    }

    public function updateStatus(int $studentId, int $deviceId, string $status): void
    {
        $statement = $this->db->prepare('UPDATE student_devices SET status = :status, updated_at = NOW() WHERE id = :device AND student_id = :student');
        $statement->execute(['status' => $status, 'device' => $deviceId, 'student' => $studentId]);
        if ($statement->rowCount() < 1) $this->findOwnedDevice($studentId, $deviceId);
    }

    public function deleteStudentData(int $studentId): void
    {
        $this->db->prepare('DELETE FROM student_devices WHERE student_id = :student')->execute(['student' => $studentId]);
    }

    public function clearSessionEvents(int $studentId): int
    {
        $statement = $this->db->prepare('DELETE FROM student_device_events WHERE student_id = :student AND event_type = \'session_start\'');
        $statement->execute(['student' => $studentId]);
        return $statement->rowCount();
    }

    public function clearDevices(int $studentId): int
    {
        $statement = $this->db->prepare('DELETE FROM student_devices WHERE student_id = :student');
        $statement->execute(['student' => $studentId]);
        return $statement->rowCount();
    }

    public function deleteDevice(int $studentId, int $deviceId): void
    {
        $statement = $this->db->prepare('DELETE FROM student_devices WHERE id = :device AND student_id = :student');
        $statement->execute(['device' => $deviceId, 'student' => $studentId]);
        if ($statement->rowCount() < 1) throw new HttpException(404, 'Recognized device not found.');
    }

    public function createChallenge(int $studentId, int $deviceId, int $tokenId): array
    {
        $id = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = date('Y-m-d H:i:s', time() + 300);
        $statement = $this->db->prepare('INSERT INTO student_device_challenges (id, student_id, device_id, access_token_id, challenge, expires_at, created_at) VALUES (:id, :student, :device, :token, :challenge, :expires_at, NOW())');
        $statement->execute(['id' => $id, 'student' => $studentId, 'device' => $deviceId, 'token' => $tokenId, 'challenge' => $challenge, 'expires_at' => $expiresAt]);
        return ['id' => $id, 'challenge' => $challenge, 'expires_at' => $expiresAt];
    }

    public function consumeChallenge(string $id, int $studentId, int $deviceId, int $tokenId): array
    {
        $owns = !$this->db->inTransaction(); if ($owns) $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT * FROM student_device_challenges WHERE id = :id AND student_id = :student AND device_id = :device AND access_token_id = :token FOR UPDATE');
            $statement->execute(['id' => $id, 'student' => $studentId, 'device' => $deviceId, 'token' => $tokenId]); $row = $statement->fetch();
            if (!$row || $row['used_at'] !== null || strtotime((string) $row['expires_at']) <= time()) throw new HttpException(422, 'The device verification challenge is invalid or expired.');
            $this->db->prepare('UPDATE student_device_challenges SET used_at = NOW() WHERE id = :id')->execute(['id' => $id]);
            if ($owns) $this->db->commit(); return $row;
        } catch (Throwable $exception) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $exception;
        }
    }

    public function prune(int $retentionDays): void
    {
        $retentionDays = max(30, min(365, $retentionDays));
        $this->db->exec('DELETE FROM student_device_challenges WHERE expires_at < NOW() OR used_at IS NOT NULL');
        $this->db->exec('DELETE FROM student_device_events WHERE occurred_at < DATE_SUB(NOW(), INTERVAL ' . $retentionDays . ' DAY)');
        $this->db->exec('DELETE FROM student_devices WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL ' . $retentionDays . ' DAY)');
    }

    public function recordSession(int $studentId, int $deviceId, int $tokenId, string $matchStatus, array $changed, ?string $networkHash, array $fingerprint): bool
    {
        $link = $this->db->prepare('INSERT INTO student_device_sessions (student_id, device_id, access_token_id, first_verified_at, last_verified_at) VALUES (:student, :device, :token, NOW(), NOW()) ON DUPLICATE KEY UPDATE last_verified_at = NOW()');
        $link->execute(['student' => $studentId, 'device' => $deviceId, 'token' => $tokenId]);
        $statement = $this->db->prepare('INSERT IGNORE INTO student_device_events (student_id, device_id, access_token_id, event_type, match_status, changed_components, network_hash, occurred_at) VALUES (:student, :device, :token, \'session_start\', :match_status, :changed, :network, NOW())');
        $statement->execute(['student' => $studentId, 'device' => $deviceId, 'token' => $tokenId, 'match_status' => $matchStatus, 'changed' => $changed === [] ? null : json_encode($changed), 'network' => $networkHash]);
        if ($statement->rowCount() < 1) return false;
        $eventId = (int) $this->db->lastInsertId();
        $this->db->prepare('UPDATE student_login_events SET source_device_event_id = :event WHERE student_id = :student AND access_token_id = :token')->execute(['event' => $eventId, 'student' => $studentId, 'token' => $tokenId]);
        $update = $this->db->prepare('UPDATE student_devices SET fingerprint_hash = :fingerprint, component_hashes = :components, device_label = :device_label, browser_label = :browser_label, os_label = :os_label, device_type = :device_type, last_seen_at = NOW(), last_verified_at = NOW(), seen_count = seen_count + 1, updated_at = NOW() WHERE id = :device AND student_id = :student');
        $update->execute([
            'fingerprint' => $fingerprint['fingerprint_hash'], 'components' => json_encode($fingerprint['component_hashes']),
            'device_label' => $fingerprint['device_label'], 'browser_label' => $fingerprint['browser_label'],
            'os_label' => $fingerprint['os_label'], 'device_type' => $fingerprint['device_type'],
            'device' => $deviceId, 'student' => $studentId,
        ]);
        return true;
    }

    public function recordAction(int $studentId, int $deviceId, int $tokenId, string $eventType, string $matchStatus, ?string $networkHash): void
    {
        $statement = $this->db->prepare('INSERT IGNORE INTO student_device_events (student_id, device_id, access_token_id, event_type, match_status, network_hash, occurred_at) VALUES (:student, :device, :token, :event_type, :match_status, :network, NOW())');
        $statement->execute(['student' => $studentId, 'device' => $deviceId, 'token' => $tokenId, 'event_type' => $eventType, 'match_status' => $matchStatus, 'network' => $networkHash]);
    }

    public function tokenIdsForDevice(int $studentId, int $deviceId): array
    {
        $statement = $this->db->prepare('SELECT access_token_id FROM student_device_sessions WHERE student_id = :student AND device_id = :device');
        $statement->execute(['student' => $studentId, 'device' => $deviceId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function devicePayload(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'credential_id' => $row['credential_id'],
            'device_label' => $row['device_label'], 'browser_label' => $row['browser_label'],
            'os_label' => $row['os_label'], 'device_type' => $row['device_type'], 'status' => $row['status'],
            'first_seen_at' => $row['first_seen_at'], 'last_seen_at' => $row['last_seen_at'],
            'last_verified_at' => $row['last_verified_at'], 'seen_count' => (int) $row['seen_count'],
            'sessions_count' => isset($row['sessions_count']) ? (int) $row['sessions_count'] : 0,
            'latest_match_status' => $row['latest_match_status'] ?? null,
        ];
    }
}
