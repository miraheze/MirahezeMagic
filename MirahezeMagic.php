<?php
if ( !defined( 'MEDIAWIKI' ) ) {
        exit( 1 );
}
$wgExtensionCredits['other'][] = array(
        'author'         => 'John Lewis',
        'descriptionmsg' => 'mirahezemagic-description',
        'name'           => 'MirahezeMagic',
        'path'           => __FILE__,
        'url'            => '//github.com/Miraheze/MirahezeMagic',
);

$wgMessagesDirs['MirahezeMagic'] = dirname( __FILE__ ) . '/i18n/miraheze';
$wgMessagesDirs['MirahezeOverrideMessagesMagic'] = dirname( __FILE__ ) . '/i18n/overrides';

$wgAutoloadClasses['MirahezeMagicHooks'] = __DIR__ . '/MirahezeMagic.hooks.php';

$wgHooks['MessageCache::get'][] = 'MirahezeMagicHooks::onMessageCacheGet';
