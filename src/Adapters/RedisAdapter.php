<?php declare(strict_types=1);

namespace LeoCarmo\CircuitBreaker\Adapters;

use Redis;

class RedisAdapter implements AdapterInterface
{

    /**
     * @var Redis
     */
    protected Redis $redis;

    /**
     * @var string
     */
    protected string $redisNamespace;

    /**
     * @var array
     */
    protected array $cachedService = [];

    /**
     * Set settings for start circuit service
     *
     * @param Redis $redis
     * @param string $redisNamespace
     */
    public function __construct(Redis $redis, string $redisNamespace)
    {
        $this->redis = $redis;
        $this->redisNamespace = $redisNamespace;
    }

    /**
     * @param string $service
     * @return bool
     */
    public function isOpen(string $service): bool
    {
        return (bool) $this->redis->get(
            $this->makeNamespace($service) . ':open'
        );
    }

    /**
     * @param string $service
     * @param int $failureRateThreshold
     * @return bool
     */
    public function reachRateLimit(string $service, int $failureRateThreshold): bool
    {
        $failures = (int) $this->redis->get(
            $this->makeNamespace($service) . ':failures'
        );

        return ($failures >= $failureRateThreshold);
    }

    /**
     * @param string $service
     * @return bool|string
     */
    public function isHalfOpen(string $service): bool
    {
        return (bool) $this->redis->get(
            $this->makeNamespace($service) . ':half_open'
        );
    }

    /**
     * @param string $service
     * @param int $timeWindow
     * @return bool
     */
    public function incrementFailure(string $service, int $timeWindow) : bool
    {
        $serviceName = $this->makeNamespace($service) . ':failures';

        if (! $this->redis->get($serviceName)) {
            $this->redis->multi();
            $this->redis->incr($serviceName);
            $this->redis->expire($serviceName, $timeWindow);
            return (bool) ($this->redis->exec()[0] ?? false);
        }

        return (bool) $this->redis->incr($serviceName);
    }

    /**
     * @param string $service
     */
    public function setSuccess(string $service): void
    {
        $serviceName = $this->makeNamespace($service);

        $this->redis->multi();
        $this->redis->del($serviceName . ':open');
        $this->redis->del($serviceName . ':failures');
        $this->redis->del($serviceName . ':half_open');
        $this->redis->exec();
    }

    /**
     * @param string $service
     * @param int $timeWindow
     */
    public function setOpenCircuit(string $service, int $timeWindow): void
    {
        $this->redis->set(
            $this->makeNamespace($service) . ':open',
            time(),
            $timeWindow
        );
    }

    /**
     * @param string $service
     * @param int $timeWindow
     * @param int $intervalToHalfOpen
     */
    public function setHalfOpenCircuit(string $service, int $timeWindow, int $intervalToHalfOpen): void
    {
        $this->redis->set(
            $this->makeNamespace($service) . ':half_open',
            time(),
            ($timeWindow + $intervalToHalfOpen)
        );
    }

    /**
     * @param string $service
     * @return string
     */
    protected function makeNamespace(string $service): string
    {
        if (isset($this->cachedService[$service])) {
            return $this->cachedService[$service];
        }

        return $this->cachedService[$service] = 'circuit-breaker:' . $this->redisNamespace . ':' . $service;
    }
}
