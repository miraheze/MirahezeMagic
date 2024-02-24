<?php

/**
 * Phan stub for MirahezeFunctions
 */
class MirahezeFunctions {

	/**
	 * @param ?string $database
	 * @return string
	 */
	public static function getMediaWikiVersion( ?string $database = null ): string {
		return MW_VERSION;
	}
}
