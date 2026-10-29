( function () {
	'use strict';

	const pixelArtLogo = [
        '                    █████████                   ',
        '                   ███████████                  ',
        '                  █████████████                 ',
        '                 ███████████████                ',
        '     █████████    █████████████    █████████    ',
        '    ███████████    ███████████    ███████████   ',
        '   █████████████    █████████    █████████████  ',
        '  ███████████████               ███████████████ ',
        '   █████████████    █████████    █████████████  ',
        '    ███████████    ███████████    ███████████   ',
        '     █████████    █████████████    █████████    ',
        '                 ███████████████                ',
        '                 ███████████████                ',
        '     █████████    █████████████    █████████    ',
        '    ███████████    ███████████    ███████████   ',
        '   █████████████    █████████    █████████████  ',
        '  ███████████████               ███████████████ ',
        '   █████████████    █████████    █████████████  ',
        '    ███████████    ███████████    ███████████   ',
        '     █████████    █████████████    █████████    ',
        '                 ███████████████                ',
        '                  █████████████                 ',
        '                   ███████████                  ',
        '                    █████████                   ',
        '',
    ].join('\n');

	// Log a help message in the browser console so that users who are debugging
	// CSP errors see it alongside the browser's CSP violation messages.
	// https://issue-tracker.miraheze.org/T15875
	const help = mw.message(
		'miraheze-console-message',
		'https://meta.miraheze.org/wiki/Help:Content_Security_Policy'
	).text();

	console.log(
		'%c' + pixelArtLogo + '%cMiraheze\n\n%c' + help,
		'color: #ffc200; font-size: medium; font-family: monospace; line-height: 100%; display: block;',
        'color: #ffc200; font-size: xxx-large; font-family: monospace; font-weight: bold;',
		'color: #ffc200; font-size: large;',
	);
}() );
