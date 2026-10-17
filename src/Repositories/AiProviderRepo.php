<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Crypto;
use PDO;

class AiProviderRepo
{
    /** Known OpenAI-compatible services, used to prefill the provider form. */
    public const PRESETS = [
        'openrouter' => ['name' => 'OpenRouter', 'base_url' => 'https://openrouter.ai/api/v1'],
        'google' => ['name' => 'Google AI Studio (Gemini)', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai'],
        'thaillm' => ['name' => 'ThaiLLM', 'base_url' => 'https://thaillm.or.th/api/v1'],
        'iapp' => ['name' => 'iApp OpenThai', 'base_url' => 'https://api.iapp.co.th/v3/llm/openthai2p0'],
        'openai' => ['name' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1'],
        'ollama' => ['name' => 'Ollama (local)', 'base_url' => 'http://localhost:11434/v1'],
        'custom' => ['name' => 'LLM Server อื่น ๆ (OpenAI-compatible)', 'base_url' => ''],
    ];

    /** Roles a provider can be limited to (same as users.role, minus consignor-only). */
    public const ROLES = ['admin', 'manager', 'advisor', 'cashier'];

    /** SQL: provider p is open to the role bound at this placeholder. */
    private const ROLE_FILTER = '(p.allowed_roles IS NULL OR FIND_IN_SET(?, p.allowed_roles) > 0)';

    public function __construct(private PDO $db)
    {
    }

    /** Providers with their saved models, API keys masked. */
    public function allForAdmin(): array
    {
        $providers = $this->db->query('SELECT * FROM ai_providers ORDER BY sort_order ASC, id ASC')->fetchAll();
        $models = $this->db->query('SELECT * FROM ai_models ORDER BY model_id ASC')->fetchAll();
        $byProvider = [];
        foreach ($models as $m) {
            $byProvider[$m['provider_id']][] = [
                'id' => (int) $m['id'],
                'model_id' => $m['model_id'],
                'display_name' => $m['display_name'],
                'supports_tools' => (bool) $m['supports_tools'],
                'supports_vision' => (bool) $m['supports_vision'],
                'price_in' => $m['price_in'] !== null ? (float) $m['price_in'] : null,
                'price_out' => $m['price_out'] !== null ? (float) $m['price_out'] : null,
                'enabled' => (bool) $m['enabled'],
            ];
        }
        return array_map(fn ($p) => $this->publicProvider($p) + ['models' => $byProvider[$p['id']] ?? []], $providers);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ai_providers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function apiKey(array $provider): string
    {
        return Crypto::decrypt($provider['api_key_enc'] ?? null);
    }

    public function extraHeaders(array $provider): array
    {
        $decoded = json_decode((string) ($provider['extra_headers'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ai_providers (name, preset, base_url, api_key_enc, extra_headers, enabled, allowed_roles, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $key = trim((string) ($data['api_key'] ?? ''));
        $stmt->execute([
            $data['name'],
            $data['preset'] ?? 'custom',
            rtrim($data['base_url'], '/'),
            $key !== '' ? Crypto::encrypt($key) : null,
            $this->encodeHeaders($data['extra_headers'] ?? null),
            !empty($data['enabled']) ? 1 : 0,
            $this->encodeRoles($data['allowed_roles'] ?? null),
            (int) ($data['sort_order'] ?? 0),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** A blank api_key keeps the stored one; pass clear_api_key to remove it. */
    public function update(int $id, array $data): void
    {
        $sets = ['name = ?', 'preset = ?', 'base_url = ?', 'extra_headers = ?', 'enabled = ?', 'allowed_roles = ?', 'sort_order = ?', 'updated_at = NOW()'];
        $params = [
            $data['name'],
            $data['preset'] ?? 'custom',
            rtrim($data['base_url'], '/'),
            $this->encodeHeaders($data['extra_headers'] ?? null),
            !empty($data['enabled']) ? 1 : 0,
            $this->encodeRoles($data['allowed_roles'] ?? null),
            (int) ($data['sort_order'] ?? 0),
        ];
        $key = trim((string) ($data['api_key'] ?? ''));
        if ($key !== '') {
            $sets[] = 'api_key_enc = ?';
            $params[] = Crypto::encrypt($key);
        } elseif (!empty($data['clear_api_key'])) {
            $sets[] = 'api_key_enc = NULL';
        }
        $params[] = $id;
        $stmt = $this->db->prepare('UPDATE ai_providers SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM ai_providers WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Replaces the provider's saved model list with the given selection.
     * @param array<int,array{model_id:string,display_name?:string,enabled?:bool,supports_tools?:bool}> $models
     */
    public function saveModels(int $providerId, array $models): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM ai_models WHERE provider_id = ?')->execute([$providerId]);
            $ins = $this->db->prepare(
                'INSERT INTO ai_models (provider_id, model_id, display_name, supports_tools, supports_vision, price_in, price_out, enabled, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $seen = [];
            foreach ($models as $m) {
                $modelId = trim((string) ($m['model_id'] ?? ''));
                if ($modelId === '' || isset($seen[$modelId])) {
                    continue;
                }
                $seen[$modelId] = true;
                $display = trim((string) ($m['display_name'] ?? ''));
                $ins->execute([
                    $providerId,
                    $modelId,
                    $display !== '' ? $display : null,
                    array_key_exists('supports_tools', $m) ? ($m['supports_tools'] ? 1 : 0) : 1,
                    !empty($m['supports_vision']) ? 1 : 0,
                    isset($m['price_in']) && is_numeric($m['price_in']) && $m['price_in'] >= 0 ? round((float) $m['price_in'], 4) : null,
                    isset($m['price_out']) && is_numeric($m['price_out']) && $m['price_out'] >= 0 ? round((float) $m['price_out'], 4) : null,
                    array_key_exists('enabled', $m) ? ($m['enabled'] ? 1 : 0) : 1,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Models a user with this role may pick in the chat widget. */
    public function enabledModels(string $role): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.model_id, m.display_name, m.supports_tools, m.supports_vision, m.price_in, m.price_out,
                    p.id AS provider_id, p.name AS provider_name
             FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id
             WHERE m.enabled = 1 AND p.enabled = 1 AND ' . self::ROLE_FILTER . '
             ORDER BY p.sort_order ASC, p.id ASC, m.model_id ASC'
        );
        $stmt->execute([$role]);
        return $stmt->fetchAll();
    }

    /**
     * "Auto" mode: the cheapest enabled model that can do the job. Photos need
     * a vision model; tool-capable models are preferred so the assistant can
     * still act in the system. Models without a price sort after priced ones.
     * @return array{0:array,1:array}|null [provider row, model row]
     */
    public function pickCheapest(bool $needVision, string $role): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.* FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id
             WHERE m.enabled = 1 AND p.enabled = 1 AND (? = 0 OR m.supports_vision = 1) AND ' . self::ROLE_FILTER . '
             ORDER BY m.supports_tools DESC,
                      (m.price_in IS NULL OR m.price_out IS NULL) ASC,
                      (COALESCE(m.price_in, 0) + COALESCE(m.price_out, 0)) ASC,
                      p.sort_order ASC, m.id ASC
             LIMIT 1'
        );
        $stmt->execute([$needVision ? 1 : 0, $role]);
        $model = $stmt->fetch();
        return $model ? [$this->find((int) $model['provider_id']), $model] : null;
    }

    /** @return array{0:array,1:array}|null [provider row, model row] if both are enabled and open to the role */
    public function resolveEnabledModel(int $modelRowId, string $role): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.* FROM ai_models m JOIN ai_providers p ON p.id = m.provider_id
             WHERE m.id = ? AND m.enabled = 1 AND p.enabled = 1 AND ' . self::ROLE_FILTER
        );
        $stmt->execute([$modelRowId, $role]);
        $model = $stmt->fetch();
        if (!$model) {
            return null;
        }
        return [$this->find((int) $model['provider_id']), $model];
    }

    private function publicProvider(array $p): array
    {
        $key = $this->apiKey($p);
        return [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'preset' => $p['preset'],
            'base_url' => $p['base_url'],
            'has_api_key' => $key !== '',
            'api_key_hint' => $key !== '' ? '••••' . substr($key, -4) : '',
            'extra_headers' => $this->extraHeaders($p),
            'enabled' => (bool) $p['enabled'],
            'allowed_roles' => $p['allowed_roles'] !== null ? explode(',', $p['allowed_roles']) : [],
            'sort_order' => (int) $p['sort_order'],
        ];
    }

    /** Empty / every role → NULL (open to everyone with ai.use). */
    private function encodeRoles(mixed $roles): ?string
    {
        $roles = array_values(array_intersect(self::ROLES, is_array($roles) ? $roles : []));
        return $roles && count($roles) < count(self::ROLES) ? implode(',', $roles) : null;
    }

    private function encodeHeaders(mixed $headers): ?string
    {
        if (is_string($headers)) {
            $headers = json_decode($headers, true);
        }
        if (!is_array($headers) || !$headers) {
            return null;
        }
        $clean = [];
        foreach ($headers as $k => $v) {
            $k = trim((string) $k);
            // Header names only; values can't contain line breaks (header injection).
            if ($k === '' || !preg_match('/^[A-Za-z0-9-]+$/', $k)) {
                continue;
            }
            $clean[$k] = str_replace(["\r", "\n"], '', (string) $v);
        }
        return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
    }
}
