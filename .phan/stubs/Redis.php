<?php

/**
 * Phan stub for Redis
 */
class Redis {

	/**
	 * @param array|null $options
	 */
	public function __construct( $options = null ) {
	}

	/**
	 * @param string $host
	 * @param int $port
	 * @param float $timeout
	 * @param string|null $persistent_id
	 * @param int $retry_interval
	 * @param float $read_timeout
	 * @param array|null $context
	 * @return bool
	 */
	public function connect( $host, $port, $timeout = 0, $persistent_id = null, $retry_interval = 0, $read_timeout = 0, $context = null ) {
	}

	/**
	 * @param mixed $credentials
	 * @return \Redis|bool
	 */
	public function auth( $credentials ) {
	}

	/**
	 * @param array|string $key
	 * @param string ...$other_keys
	 * @return \Redis|false|int
	 */
	public function del( $key, ...$other_keys ) {
	}

	/**
	 * @param string $pattern
	 */
	public function keys( $pattern ) {
	}
}
