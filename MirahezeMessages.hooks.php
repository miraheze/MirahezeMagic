<?php
class MirahezeMessagesHooks {
        /**
         * From WikimediaMessages
         *
         * @return bool
         */
        public static function onMessageCacheGet( &$lcKey ) {
                global $wgLanguageCode;
                static $keys = array(
                        'centralauth-groupname',
                );
                if ( in_array( $lcKey, $keys, true ) ) {
                        $prefixedKey ="miraheze-$lcKey";
                        // MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
                        // of the above.  Revisit if non-ASCII keys are used.
                        $ucKey = ucfirst( $lcKey );
                        $cache = MessageCache::singleton();
                        if (
                                // Override order:
                                // 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
                                // (in all languages) with normal fallback order.  Specific
                                // language pages (MediaWiki:$ucKey/xy) are not checked when
                                // deciding which key to use, but are still used if applicable
                                // after the key is decided.
                                //
                                // 2. Otherwise, use the prefixed key with normal fallback order
                                // (including MediaWiki pages if they exist).
                                $cache->getMsgFromNamespace( $ucKey, $wgLanguageCode ) === false
                        ) {
                                $lcKey = $prefixedKey;
                        }
                }
                return true;
        }
}

