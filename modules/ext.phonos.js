( function () {
	function init( $content ) {
		$content.find( '.ext-phonos' ).each( function () {
			// TODO: Do things
		} );
	}

	mw.hook( 'wikipage.content' ).add( init );
}() );
