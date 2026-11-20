<?php
/**
 * 2024 Smaily
 *
 * NOTICE OF LICENSE
 *
 * Smaily for PrestaShop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Smaily for PrestaShop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smaily for PrestaShop. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Smaily <info@smaily.com>
 * @copyright 2024 Smaily
 * @license   GPL3
 */
declare(strict_types=1);

namespace PrestaShop\Module\SmailyForPrestaShop\Scheduler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the background tasks last did: when the last tick ran and through which source (a door of
 * Door, or Tick::SOURCE_TRAFFIC), when each source last ran one, and when each job last ran. Times
 * are Unix times. The Dashboard reads it; the tick writes it.
 */
final class TickState
{
    private ?int $lastTickAt = null;

    private ?string $lastSource = null;

    /** @var array<string, int> */
    private array $sources = [];

    /** @var array<string, int> */
    private array $jobs = [];

    private function __construct()
    {
    }

    /**
     * Reads a stored state. A value that is absent or unreadable holds no tick.
     */
    public static function fromJson(?string $json): self
    {
        $state = new self();
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return $state;
        }

        if (isset($data['last']['at'], $data['last']['source'])) {
            $state->lastTickAt = (int) $data['last']['at'];
            $state->lastSource = (string) $data['last']['source'];
        }
        foreach (['sources', 'jobs'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $state->{$field} = array_map('intval', $data[$field]);
            }
        }

        return $state;
    }

    public function toJson(): string
    {
        return json_encode([
            'last' => ['at' => $this->lastTickAt, 'source' => $this->lastSource],
            'sources' => (object) $this->sources,
            'jobs' => (object) $this->jobs,
        ], JSON_THROW_ON_ERROR);
    }

    public function withTick(string $source, int $at): self
    {
        $state = clone $this;
        $state->lastTickAt = $at;
        $state->lastSource = $source;
        $state->sources[$source] = $at;

        return $state;
    }

    public function withJobRun(string $job, int $at): self
    {
        $state = clone $this;
        $state->jobs[$job] = $at;

        return $state;
    }

    public function lastTickAt(): ?int
    {
        return $this->lastTickAt;
    }

    public function lastSource(): ?string
    {
        return $this->lastSource;
    }

    /**
     * When a tick last ran through $source.
     */
    public function lastTickFrom(string $source): ?int
    {
        return $this->sources[$source] ?? null;
    }

    public function jobRanAt(string $job): ?int
    {
        return $this->jobs[$job] ?? null;
    }
}
