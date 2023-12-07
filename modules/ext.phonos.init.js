( function () {
	'use strict';

	function phonosClickHandler( event ) {
		// This handler is used for both click and keydown.
		if ( event.keyCode !== undefined &&
			event.keyCode !== 13 /* OO.ui.Keys.ENTER */ &&
			event.keyCode !== 32 /* OO.ui.Keys.SPACE */
		) {
			return;
		}
		event.preventDefault();
		mw.loader.using( 'ext.phonos' ).then( function () {
			const buttonElement = event.target.closest( '.ext-phonos-PhonosButton' );
			const button = OO.ui.infuse( buttonElement );
			button.focus();
			button.emit( 'click' );
			event.target.removeEventListener( 'click', phonosClickHandler );
			event.target.removeEventListener( 'keydown', phonosClickHandler );
		} );
	}

	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		$content.find( '.ext-phonos-PhonosButton .oo-ui-buttonElement-button' ).each( function () {
			this.addEventListener( 'click', phonosClickHandler );
			this.addEventListener( 'keydown', phonosClickHandler );
		} );
	} );

}() );
