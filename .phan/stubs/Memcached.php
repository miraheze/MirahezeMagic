<?php

/**
 * Phan stub for Memcached
 */
class Memcached {

	/**
	 * @param string|null $persistent_id
	 */
	public function __construct( $persistent_id = null ) {
	}

	/**
	 * @param string $host
	 * @param string|int $port
	 * @param int $weight
	 */
	public function addServer( $host, $port, $weight = 0 ) {
	}

	/**
	 * @return array|bool
	 */
	public function getAllKeys() {
	}

	/**
	 * @param string $key
	 * @param int $time
	 * @return bool
	 */
	public function delete( $key, $time = 0 ) {
	}
}
