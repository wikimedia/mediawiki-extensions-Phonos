( function () {
	function init( $content ) {
		$content.find( '.mw-phonos' ).each( function () {
			// TODO: Do things
		} );
	}

	mw.hook( 'wikipage.content' ).add( init );
}() );
