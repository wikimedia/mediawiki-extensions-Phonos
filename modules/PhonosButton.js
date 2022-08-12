/**
 * @class
 * @constructor
 * @extends OO.ui.ButtonWidget
 * @mixin OO.ui.mixin.PendingElement
 * @param {Object} [config] Configuration parameters.
 */
function PhonosButton( config ) {
	// Parent constructor.
	PhonosButton.super.call( this, config );

	// Mixin constructor.
	OO.ui.mixin.PendingElement.call( this, { $pending: this.$button } );

	this.phonosData = this.getData();

	// Set an aria description attribute for the button.
	this.$button.attr(
		'aria-description',
		mw.message( 'phonos-player-aria-description', [ this.phonosData.text ] ).parse()
	);

	// T315404: Wrap output element in data-nosnippet
	this.$element.attr( 'data-nosnippet', '' );

	// This HTMLAudioElement will be instantiated once.
	this.audio = null;

	// Create a popup for error messages.
	this.popup = this.getErrorPopup();

	// Add click handlers.
	$( 'html' ).on( 'click', this.onHtmlClick );
	this.connect( this, { click: this.onClick } );
}

OO.inheritClass( PhonosButton, OO.ui.ButtonWidget );
OO.mixinClass( PhonosButton, OO.ui.mixin.PendingElement );

/**
 * @static
 * @member {Array} All popups for all buttons.
 */
PhonosButton.static.popups = [];

/**
 * HTML element click handler: close all popups when clicking anywhere
 * outside of any Phonos elements.
 *
 * @param {Event} event
 * @return {void}
 */
PhonosButton.prototype.onHtmlClick = function ( event ) {
	// Do nothing if we're clicking inside a button or popup.
	const $parents = $( event.target ).closest( '.ext-phonos' );
	if ( $parents.length > 0 ) {
		return;
	}
	// Otherwise, close all popups.
	PhonosButton.static.popups.forEach( ( popup ) => {
		popup.toggle( false );
	} );
};

/**
 * Click handler: play or pause the audio.
 *
 * @param {Event} event
 * @return {void}
 */
PhonosButton.prototype.onClick = function ( event ) {
	event.preventDefault();

	// A popup exists, so no audio can be played.
	if ( this.popup ) {
		// First close other popups.
		PhonosButton.static.popups.forEach( ( otherPopup ) => {
			if ( otherPopup !== this.popup ) {
				otherPopup.toggle( false );
			}
		} );
		// Then show or hide this one.
		this.popup.toggle();
		return;
	}

	// Already playing, so pause and reset to the beginning.
	if ( this.audio && !this.audio.paused ) {
		this.audio.pause();
		return;
	}

	// Already loaded, but has ended so play again from the beginning.
	if ( this.audio ) {
		this.audio.currentTime = 0;
		this.audio.play();
		return;
	}

	// Not loaded yet, but has a src URL so use that.
	if ( !this.audio && this.getHref() ) {
		this.pushPending();
		this.audio = this.getAudioEl( this.getHref() );
		// Play once after loading.
		this.audio.addEventListener( 'canplaythrough', () => {
			this.popPending();
			this.audio.play();
		}, { once: true } );
	}
};

/**
 * @private
 * @param {string} src
 * @return {HTMLAudioElement}
 */
PhonosButton.prototype.getAudioEl = function ( src ) {
	const audio = new Audio( src );
	audio.addEventListener( 'playing', () => {
		this.setFlags( { progressive: true } );
	} );
	audio.addEventListener( 'paused', () => {
		this.setFlags( { progressive: false } );
	} );
	audio.addEventListener( 'ended', () => {
		this.setFlags( { progressive: false } );
	} );
	audio.onerror = this.handleMissingFile.bind( this );
	return audio;
};

/**
 * Create and return an error popup if necessary.
 *
 * @return {null|OO.ui.PopupWidget}
 */
PhonosButton.prototype.getErrorPopup = function () {
	if ( !this.phonosData.error ) {
		return null;
	}

	// Messages that can be used here:
	// * phonos-audio-conversion-error
	// * phonos-directory-error
	// * phonos-engine-error
	// * phonos-storage-error
	// * phonos-wikibase-api-error
	// * phonos-wikibase-invalid-entity-lexeme
	// * phonos-wikibase-not-found
	let error = this.phonosData.error;

	// If a file was given, we know this is an error specifically involving the file
	// and we want to construct a link to the file page.
	if ( this.phonosData.file ) {
		const fileTitle = new mw.Title( 'File:' + this.phonosData.file );
		const $link = $( '<a>' )
			.attr( 'href', fileTitle.getUrl() )
			.text( fileTitle.getMainText() );
		// Messages that can be used here:
		// * phonos-file-not-found
		// * phonos-file-not-audio
		error = mw.message( this.phonosData.error, [ $link.prop( 'outerHTML' ) ] ).text();
	}

	return this.getErrorPopupWidget( error );
};

/**
 * Get an error popup widget with the given message.
 *
 * @param {string} error
 * @return {OO.ui.PopupWidget}
 * @private
 */
PhonosButton.prototype.getErrorPopupWidget = function ( error ) {
	const popup = new OO.ui.PopupWidget( {
		classes: [ 'ext-phonos-error-popup' ],
		$content: $( '<p>' ).append( error ),
		padded: true
	} );
	PhonosButton.static.popups.unshift( popup );
	this.$element.append( popup.$element );
	return popup;
};

/**
 * This is called when there's an error when attempting playback. We assume in this case
 * the file somehow went missing. In any case, re-trying is probably the best advice for the user.
 *
 * @private
 */
PhonosButton.prototype.handleMissingFile = function () {
	this.popPending();
	this.setDisabled( true );
	this.setIcon( 'volumeOff' );
	const $link = $( '<a>' )
		.attr( 'href', mw.util.getUrl( mw.config.get( 'wgPageName' ), { action: 'purge' } ) )
		.text( mw.message( 'phonos-purge-needed-error-link' ) );
	this.popup = this.getErrorPopupWidget(
		$( '<span>' ).append(
			mw.message( 'phonos-purge-needed-error' ).text() + '&nbsp;',
			$link
		)
	);
	this.popup.toggle( true );

	// Set up click listener for the link so that users with JS
	// aren't shown the action=purge confirmation screen.
	$link.on( 'click', ( e ) => {
		this.setIcon( 'reload' );
		e.preventDefault();
		mw.loader.using( 'mediawiki.api' ).done( () => {
			new mw.Api().post( {
				action: 'purge',
				pageids: mw.config.get( 'wgArticleId' )
			} ).always( () => {
				// The browser *should* bring the user back to the same scroll position.
				location.reload();
			} );
		} );
	} );
};

module.exports = PhonosButton;
